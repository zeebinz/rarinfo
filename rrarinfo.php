<?php

require_once dirname(__FILE__).'/rarinfo.php';

/**
 * RecursiveRarInfo class.
 *
 * This is an example implementation of a class for recursively inspecting the
 * contents of RAR archives packed within RAR archives (Store method only).
 *
 * Example usage:
 *
 * <code>
 *
 *   // Load the RAR file or data
 *   $rar = new RecuriveRarInfo;
 *   $rar->open('./foo.rar'); // or $rar->setData($data);
 *   if ($rar->error) {
 *     echo "Error: {$rar->error}\n";
 *     exit;
 *   }
 *
 *   // List the contents of all archives recursively
 *   foreach($rar->getArchiveFileList() as $file) {
 *     if (isset($file['error'])) {
 *       echo "Error: {$file['error']} (in: {$file['source']})\n";
 *       continue;
 *     }
 *     if ($file['pass'] == true) {
 *       echo "File is passworded: {$file['name']} (in: {$file['source']})\n";
 *     }
 *   }
 *
 * </code>
 *
 * @author     Hecks
 * @copyright  (c) 2010-2013 Hecks
 * @license    Modified BSD
 * @version    1.0
 */
class RecursiveRarInfo extends RarInfo
{
	/**
	 * Cached list of any embedded archive objects.
	 * @var array
	 */
	protected $archives = array();

	/**
	 * Determines whether the current RAR archive contains another archive.
	 *
	 * @return  boolean
	 */
	public function containsArchive()
	{
		$this->getArchiveList();
		return !empty($this->archives);
	}

	/**
	 * Lists any embedded archives, either as raw archive info objects or as file
	 * summaries, and caches the object list locally.
	 *
	 * @param   boolean  $summary  return file summaries?
	 * @return  boolean|array  list of stored objects/summaries, or false on error
	 */
	public function getArchiveList($summary=false)
	{
		if (empty($this->blocks)) {return false;}

		if (empty($this->archives)) foreach ($this->getBlocks() as $block) {
			if ($block['head_type'] == self::BLOCK_FILE) {

				// Check the file extensions (lazy!)
				$ext = pathinfo($block['file_name'], PATHINFO_EXTENSION);
				if (preg_match('/(rar|r[0-9]+)/', $ext)) {
					$this->archives[$block['file_name']] = $this->getArchive($block['file_name']);
				}
			}
		}

		// Return a summary or object list
		return $summary ? array_map(function ($rar) {return $rar->getSummary(true);},
			$this->archives) : $this->archives;
	}

	/**
	 * Returns the archive object for an embedded archive file with the contents
	 * analyzed (initially without recursion). Calls to this method can also be
	 * chained together to navigate the tree, e.g.:
	 *
	 *    $rar->getArchive('parent.rar')->getArchive('child.rar')->getSummary();
	 *
	 * @param   string   $filename  the embedded archive filename
	 * @return  boolean|RecursiveRarInfo  false if an object can't be returned
	 */
	public function getArchive($filename)
	{
		if (empty($this->blocks)) {return false;}

		// Check the cache first
		if (isset($this->archives[$filename]))
			return $this->archives[$filename];

		foreach ($this->blocks AS $block) {
			if ($block['head_type'] == self::BLOCK_FILE && $block['file_name'] == $filename) {

				// Create the new archive object
				$rar = new self;
				$start = $this->start + $block['offset'] + $block['head_size'];
				if ($this->file) {
					$end = min($this->end, ($this->start + $block['next_offset'] - 1));
					$rar->open($this->file, $this->isFragment, array($start, $end));
				} else {
					$length = min($this->length, ($block['next_offset'] - $block['offset'] - $block['head_size']));
					$rar->setData(substr($this->data, $start, $length), $this->isFragment);
				}

				// Make any error messages more specific
				if ($block['method'] != self::METHOD_STORE && $rar->error) {
					$rar->error = 'The archive is compressed and cannot be read';
				}
				if (isset($block['has_password']) || $rar->isEncrypted) {
					$rar->error = 'The archive is encrypted and cannot be read';
				}

				return $rar;
			}
		}

		// Something went wrong
		return false;
	}

	/**
	 * Provides the contents of the current archive in a flat list, optionally
	 * recursing through all embedded archives as well, and appends a 'source'
	 * field to each item with the archive tree info.
	 *
	 * @param   boolean  $recurse   list all archive contents recursively?
	 * @param   string   $source    the archive source tree of the file item
	 * @return  array|boolean  the flat archive file list, or false on error
	 */
	public function getArchiveFileList($recurse=true, $source=null)
	{
		if (empty($this->blocks)) {return false;}
		$ret = array();

		// Start with the main parent
		if ($source == null) {
			$source = 'main';
			$ret = $this->getFileList();
			foreach ($ret as &$file) {$file['source'] = $source;}
		}

		// Merge each archive file list
		if ($recurse && $this->containsArchive()) {
			foreach ($this->getArchiveList() as $name => $archive) {
				$branch = $source.' > '.$name;

				// We should append any errors
				if ($archive->error || !($files = $archive->getFileList())) {
					$error = $archive->error ? $archive->error : 'No files found';
					$ret[] = array('error' => $error, 'source' => $branch);
					continue;
				}

				// Otherwise merge recursively
				foreach ($files as &$file) {$file['source'] = $branch;}
				$ret = array_merge($ret, $files);
				if ($archive->containsArchive()) {
					$ret = array_merge($ret, $archive->getArchiveFileList(true, $branch));
				}
			}
		}

		return $ret;
	}

	/**
	 * When called with $full set to true, this method will return a nested summary
	 * of all the embedded archive contents in the 'archives' value(s), keyed to the
	 * archive filenames.
	 *
	 * @param   boolean   $full      add file list to output?
	 * @param   boolean   $skipDirs  should directory entries be skipped?
	 * @return  array     archive summary
	 */
	public function getSummary($full=false, $skipDirs=false)
	{
		$summary = parent::getSummary($full, $skipDirs);
		if ($full && $this->containsArchive()) {
			$summary['archives'] = $this->getArchiveList(true); // recursive
		}
		return $summary;
	}

	/**
	 * Resets the instance variables before parsing new data.
	 *
	 * @return  void
	 */
	protected function reset()
	{
		parent::reset();
		$this->archives = array();
	}

} // End RecursiveRarInfo class
