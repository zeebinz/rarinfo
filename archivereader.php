<?php
/**
 * Abstract base class for all archive file inspectors.
 *
 * @author     Hecks
 * @copyright  (c) 2010-2013 Hecks
 * @license    Modified BSD
 * @version    1.6
 */
abstract class ArchiveReader
{
	// ------ Class variables and methods -----------------------------------------

	/**
	 * Unpacks data from a binary string.
	 *
	 * This method helps in particular to fix unpacking of unsigned longs on 32-bit
	 * systems due to PHP internal quirks.
	 *
	 * @param   string   $format    format codes for unpacking
	 * @param   string   $data      the packed string
	 * @param   boolean  $fixLongs  should unsigned longs be fixed?
	 * @return  array    the unpacked data
	 */
	public static function unpack($format, $data, $fixLongs=true)
	{
		$unpacked = unpack($format, $data);

		// Fix conversion of unsigned longs on 32-bit systems
		if ($fixLongs && PHP_INT_SIZE <= 4 && strpos($format, 'V') !== false) {
			$codes = explode('/', $format);
			$longs = array('V', 'N', 'L');
			foreach ($unpacked as $key=>$value) {
				$code = array_shift($codes);
				if (in_array($code[0], $longs) && $value < 0) {
					$unpacked[$key] = $value + 0x100000000; // converts to float
				}
			}
		}

		return $unpacked;
	}

	/**
	 * Converts two longs into a float to represent a 64-bit integer.
	 *
	 * If more precision is needed, the bcmath functions should be used.
	 *
	 * @param   integer  $low   the low 32 bits
	 * @param   integer  $high  the high 32 bits
	 * @return  float
	 */
	public static function int64($low, $high)
	{
		return ($low + ($high * 0x100000000));
	}

	/**
	 * Converts DOS standard timestamps to UNIX timestamps.
	 *
	 * @param   integer  $dostime  DOS timestamp
	 * @return  integer  UNIX timestamp
	 */
	public static function dos2unixtime($dostime)
	{
		$sec  = 2 * ($dostime & 0x1f);
		$min  = ($dostime >> 5) & 0x3f;
		$hrs  = ($dostime >> 11) & 0x1f;
		$day  = ($dostime >> 16) & 0x1f;
		$mon  = ($dostime >> 21) & 0x0f;
		$year = (($dostime >> 25) & 0x7f) + 1980;

		return mktime($hrs, $min, $sec, $mon, $day, $year);
	}

	/**
	 * Calculates the size of the given file.
	 *
	 * This is fiddly on 32-bit systems for sizes larger than 2GB due to internal
	 * limitations - filesize() returns a signed long - and so needs hackery.
	 *
	 * @param   string   $file  full path to the file
	 * @return  integer/float   the file size in bytes
	 */
	public static function getFileSize($file)
	{
		// 64-bit systems should be OK
		if (PHP_INT_SIZE > 4)
			return filesize($file);

		// Hack for Windows
		if (DIRECTORY_SEPARATOR === '\\') {
			$com = new COM('Scripting.FileSystemObject');
			$f = $com->GetFile($file);
			return $f->Size;
		}

		// Hack for *nix
		return trim(shell_exec('stat -c %s '.escapeshellarg($file)));
	}

	/**
	 * Returns human-readable byte sizes as formatted strings.
	 *
	 * @param   integer   $bytes  the size to format
	 * @param   integer   $round  decimal places limit
	 * @return  string    human-readable size
	 */
	public static function formatSize($bytes, $round=1)
	{
		$suffix = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
		for ($i = 0; $bytes > 1024 && isset($suffix[$i+1]); $i++) {$bytes /= 1024;}
		return round($bytes,$round).' '.$suffix[$i];
	}

	// ------ Instance variables and methods ---------------------------------------

	/**
	 * Path to the archive file (if any).
	 * @var string
	 */
	public $file = '';

	/**
	 * The last error message.
	 * @var string
	 */
	public $error = '';

	/**
	 * The number of files in the archive file/data.
	 * @var integer
	 */
	public $fileCount = 0;

	/**
	 * Opens a handle to the archive file, or loads data from a file fragment up to
	 * maxReadBytes, and analyzes the archive contents.
	 *
	 * @param   string   $file        path to the file
	 * @param   boolean  $isFragment  true if file is an archive fragment
	 * @return  boolean  false if archive analysis fails
	 */
	public function open($file, $isFragment=false)
	{
		$this->reset();
		$this->isFragment = $isFragment;
		if (!($archive = realpath($file))) {
			$this->error = "File does not exist ($file)";
			return false;
		}
		$this->file = $archive;
		$this->fileSize = self::getFileSize($archive);

		if ($isFragment) {

			// Read the fragment into memory
			$this->data = file_get_contents($archive, NULL, NULL, 0, $this->maxReadBytes);
			$this->dataSize = strlen($this->data);

		} else {

			// Open the file handle
			$this->handle = fopen($archive, 'rb');
		}

		return $this->analyze();
	}

	/**
	 * Closes any open file handle and unsets any stored data.
	 *
	 * @return  void
	 */
	public function close()
	{
		if (is_resource($this->handle)) {
			fclose($this->handle);
			$this->handle = null;
		}
		$this->data = null;
	}

	/**
	 * Loads data up to maxReadBytes and analyzes the archive contents.
	 *
	 * This method is recommended when dealing with file fragments.
	 *
	 * @param   string   $data        archive data to be analyzed
	 * @param   boolean  $isFragment  true if data is an archive fragment
	 * @return  boolean  false if archive analysis fails
	 */
	public function setData($data, $isFragment=false)
	{
		$this->reset();
		$this->isFragment = $isFragment;
		if (strlen($data) == 0) {
			$this->error = 'No data was passed, nothing to analyze';
			return false;
		}

		// Store the data locally up to max bytes
		$this->data = (strlen($data) > $this->maxReadBytes) ? substr($data, 0, $this->maxReadBytes) : $data;
		$this->dataSize = strlen($this->data);

		return $this->analyze();
	}

	/**
	 * Convenience method that outputs a summary list of the archive information,
	 * useful for pretty-printing.
	 *
	 * @return  array  archive summary
	 */
	abstract public function getSummary();

	/**
	 * Parses the stored archive info and returns a list of records for each of the
	 * files in the archive.
	 *
	 * @return  mixed  an array of file records or false if none are available
	 */
	abstract public function getFileList();

	/**
	 * File handle for the current archive.
	 * @var resource
	 */
	protected $handle;

	/**
	 * The maximum number of stored data bytes to analyze.
	 * @var integer
	 */
	protected $maxReadBytes = 1048576;

	/**
	 * The maximum length of filenames (for sanity checking).
	 * @var integer
	 */
	protected $maxFilenameLength = 1024;

	/**
	 * Is this a file/data fragment?
	 * @var boolean
	 */
	protected $isFragment = false;

	/**
	 * The stored archive file data.
	 * @var string
	 */
	protected $data;

	/**
	 * The size in bytes of the currently stored data.
	 * @var integer
	 */
	protected $dataSize = 0;

	/**
	 * The size in bytes of the archive file.
	 * @var integer
	 */
	protected $fileSize = 0;

	/**
	 * A pointer to the current position in the data or file.
	 * @var integer
	 */
	protected $offset = 0;

	/**
	 * Parses the archive data and stores the results locally.
	 *
	 * @return  boolean  false if parsing fails
	 */
	abstract protected function analyze();

	/**
	 * Reads the given number of bytes from the stored data and moves the
	 * pointer forward.
	 *
	 * @param   integer  $num  number of bytes to read
	 * @return  string   byte string
	 */
	protected function read($num)
	{
		if ($num == 0) return '';

		// Check that enough data is available
		$newPos = $this->offset + $num;
		if (($num < 1 ) || ($this->data && ($newPos > $this->dataSize))
			|| (!$this->data && ($newPos > $this->fileSize))
			) {
			throw new Exception('End of readable data reached');
		}

		// Read the requested bytes
		if ($this->data) {
			$read = substr($this->data, $this->offset, $num);
		} elseif (is_resource($this->handle)) {
			$read = fread($this->handle, $num);
		}

		// Confirm the read length
		if (!isset($read) || (($rlen = strlen($read)) < $num)) {
			$rlen = isset($rlen) ? $rlen : 'none';
			$this->error = "Not enough data to read ({$num} bytes requested, {$rlen} available)";
			throw new Exception('Read error');
		}

		// Move the data pointer
		$this->offset = $newPos;

		return $read;
	}

	/**
	 * Moves the current pointer to the given position in the stored data or file.
	 *
	 * Note that seeking in files past the 2GB limit on 32-bit systems is either
	 * impossible or needs an incredibly slow hack due to the fseek() pointer not
	 * behaving after 2GB. The only real solution here is to use a 64-bit system.
	 *
	 * @param   integer/float  $pos  new pointer position
	 * @return  void
	 */
	protected function seek($pos)
	{
		if ($this->data && ($pos > $this->dataSize || $pos < 0)) {
			$pos = $this->dataSize;
		} elseif (!$this->data && ($pos > $this->fileSize || $pos < 0)) {
			$pos = $this->fileSize;
		}

		if (!$this->data && is_resource($this->handle)) {
			$max = PHP_INT_MAX;
			if ($pos >= $max) {
				$this->error = 'The file is too large for this PHP version (> '.self::formatSize($max).')';
				throw new Exception('Seek error');
			}
			fseek($this->handle, $pos, SEEK_SET);
		}

		$this->offset = $pos;
	}

	/**
	 * Sets the file or stored data pointer to the starting position.
	 *
	 * @return  void
	 */
	protected function rewind()
	{
		if (is_resource($this->handle)) {
			rewind($this->handle);
		}
		$this->offset = 0;
	}

	/**
	 * Resets the instance variables before parsing new data.
	 *
	 * @return  void
	 */
	protected function reset()
	{
		$this->close();
		$this->file = '';
		$this->fileSize = 0;
		$this->dataSize = 0;
		$this->offset = 0;
		$this->error = '';
		$this->isFragment = false;
		$this->fileCount = 0;
	}

} // End ArchiveInfo class
