<?php

require_once dirname(__FILE__).'/archivereader.php';

/**
 * RarInfo class.
 *
 * A simple class for inspecting RAR file data and listing information about
 * the archive contents in pure PHP (no external dependencies). Data can be
 * streamed from a file or loaded directly from memory.
 *
 * Example usage:
 *
 * <code>
 *
 *   // Load the RAR file or data
 *   $rar = new RarInfo;
 *   $rar->open('./foo.rar'); // or $rar->setData($data);
 *   if ($rar->error) {
 *     echo "Error: {$rar->error}\n";
 *     exit;
 *   }
 *
 *   // Check encryption
 *   if ($rar->isEncrypted) {
 *     echo "Archive is password encrypted\n";
 *     exit;
 *   }
 *
 *   // Process the file list
 *   $files = $rar->getFileList();
 *   foreach ($files as $file) {
 *     if ($file['pass'] == true) {
 *       echo "File is passworded: {$file['name']}\n";
 *     }
 *   }
 *
 * </code>
 *
 * For RAR file fragments - i.e. that may not contain a valid Marker Block - add
 * TRUE as the second parameter for the open() or setData() methods to skip the
 * error messages and allow a forced search for valid File Header blocks.
 *
 * @todo Plenty of parsing still possible, most format values have been added ;)
 * @link http://www.win-rar.com/index.php?id=24&kb_article_id=162
 *
 * @author     Hecks
 * @copyright  (c) 2010-2013 Hecks
 * @license    Modified BSD
 * @version    3.1
 */
class RarInfo extends ArchiveReader
{
	// ------ Class constants -----------------------------------------------------

	/**#@+
	 * RAR file format values (thanks to Marko Kreen)
	 */

	// Block types
	const BLOCK_MARK          = 0x72;
	const BLOCK_MAIN          = 0x73;
	const BLOCK_FILE          = 0x74;
	const BLOCK_OLD_COMMENT   = 0x75;
	const BLOCK_OLD_EXTRA     = 0x76;
	const BLOCK_OLD_SUB       = 0x77;
	const BLOCK_OLD_RECOVERY  = 0x78;
	const BLOCK_OLD_AUTH      = 0x79;
	const BLOCK_SUB           = 0x7a;
	const BLOCK_ENDARC        = 0x7b;

	// Flags for BLOCK_MAIN
	const MAIN_VOLUME         = 0x0001;
	const MAIN_COMMENT        = 0x0002;
	const MAIN_LOCK           = 0x0004;
	const MAIN_SOLID          = 0x0008;
	const MAIN_NEWNUMBERING   = 0x0010;
	const MAIN_AUTH           = 0x0020;
	const MAIN_RECOVERY       = 0x0040;
	const MAIN_PASSWORD       = 0x0080;
	const MAIN_FIRSTVOLUME    = 0x0100;
	const MAIN_ENCRYPTVER     = 0x0200;

	// Flags for BLOCK_FILE
	const FILE_SPLIT_BEFORE   = 0x0001;
	const FILE_SPLIT_AFTER    = 0x0002;
	const FILE_PASSWORD       = 0x0004;
	const FILE_COMMENT        = 0x0008;
	const FILE_SOLID          = 0x0010;
	const FILE_DICTMASK       = 0x00e0;
	const FILE_DICT64         = 0x0000;
	const FILE_DICT128        = 0x0020;
	const FILE_DICT256        = 0x0040;
	const FILE_DICT512        = 0x0060;
	const FILE_DICT1024       = 0x0080;
	const FILE_DICT2048       = 0x00a0;
	const FILE_DICT4096       = 0x00c0;
	const FILE_DIRECTORY      = 0x00e0;
	const FILE_LARGE          = 0x0100;
	const FILE_UNICODE        = 0x0200;
	const FILE_SALT           = 0x0400;
	const FILE_VERSION        = 0x0800;
	const FILE_EXTTIME        = 0x1000;
	const FILE_EXTFLAGS       = 0x2000;

	// Flags for BLOCK_ENDARC
	const ENDARC_NEXT_VOLUME  = 0x0001;
	const ENDARC_DATACRC      = 0x0002;
	const ENDARC_REVSPACE     = 0x0004;
	const ENDARC_VOLNR        = 0x0008;

	// Flags for all blocks
	const SKIP_IF_UNKNOWN     = 0x4000;
	const LONG_BLOCK          = 0x8000;

	// OS types
	const OS_MSDOS = 0;
	const OS_OS2   = 1;
	const OS_WIN32 = 2;
	const OS_UNIX  = 3;
	const OS_MACOS = 4;
	const OS_BEOS  = 5;

	// Subtypes for BLOCK_SUB
	const SUBTYPE_COMMENT   = 'CMT';
	const SUBTYPE_ACL       = 'ACL';
	const SUBTYPE_STREAM    = 'STM';
	const SUBTYPE_UOWNER    = 'UOW';
	const SUBTYPE_AUTHVER   = 'AV';
	const SUBTYPE_RECOVERY  = 'RR';
	const SUBTYPE_OS2EA     = 'EA2';
	const SUBTYPE_BEOSEA    = 'EABE';

	/**#@-*/

	/**
	 * Format for unpacking the main part of each block header.
	 */
	const FORMAT_BLOCK_HEADER = 'vhead_crc/Chead_type/vhead_flags/vhead_size';

	/**
	 * Format for unpacking the remainder of a File block header.
	 */
	const FORMAT_FILE_HEADER = 'Vpack_size/Vunp_size/Chost_os/Vfile_crc/Vftime/Cunp_ver/Cmethod/vname_size/Vattr';


	// ------ Class variables and methods -----------------------------------------

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

	// ------ Instance variables and methods ---------------------------------------

	/**
	 * Signature for the RAR Marker block.
	 * @bar string
	 */
	protected $markerBlock = '526172211a0700';

	/**
	 * List of block names corresponding to block types.
	 * @var array
	 */
	protected $blockNames = array(
		0x72 => 'Marker',
		0x73 => 'Archive',
		0x74 => 'File',
		0x75 => 'Old Style Comment',
		0x76 => 'Old Style Extra Info',
		0x77 => 'Old Style Subblock',
		0x78 => 'Old Style Recovery Record',
		0x79 => 'Old Style Archive Authenticity',
		0x7a => 'Subblock',
		0x7b => 'Archive End',
	);

	/**
	 * List of the names corresponding to Subblock types (0x7a).
	 * @var array
	 */
	protected $subblockNames = array(
		self::SUBTYPE_COMMENT   => 'Comment',
		self::SUBTYPE_ACL       => 'Access Control List',
		self::SUBTYPE_STREAM    => 'Stream',
		self::SUBTYPE_UOWNER    => 'Owner/Group Information',
		self::SUBTYPE_AUTHVER   => 'Authenticity Verification',
		self::SUBTYPE_RECOVERY  => 'Recovery Record',
		self::SUBTYPE_OS2EA     => 'OS2EA',
		self::SUBTYPE_BEOSEA    => 'BEOSEA',
	);

	/**
	 * Is the volume attribute set for the archive?
	 * @var boolean
	 */
	public $isVolume;

	/**
	 * Is authenticity information present?
	 * @var boolean
	 */
	public $hasAuth;

	/**
	 * Is a recovery record present?
	 * @var boolean
	 */
	public $hasRecovery;

	/**
	 * Is the archive encrypted with a password?
	 * @var boolean
	 */
	public $isEncrypted;

	/**
	 * Convenience method that outputs a summary list of the archive information,
	 * useful for pretty-printing.
	 *
	 * @param   boolean   $full      add file list to output?
	 * @param   boolean   $skipDirs  should directory entries be skipped?
	 * @return  array     archive summary
	 */
	public function getSummary($full=false, $skipDirs=false)
	{
		$summary = array(
			'rar_file' => $this->file,
			'file_size' => $this->fileSize,
			'data_size' => $this->dataSize,
			'is_volume' => (int) $this->isVolume,
			'has_auth' => (int) $this->hasAuth,
			'has_recovery' => (int) $this->hasRecovery,
			'is_encrypted' => (int) $this->isEncrypted,
		);
		$fileList = $this->getFileList($skipDirs);
		$summary['file_count'] = $fileList ? count($fileList) : 0;
		if ($full) {
			$summary['file_list'] = $fileList;
		}

		return $summary;
	}

	/**
	 * Returns a list of the blocks found in the archive in human-readable format
	 * (for debugging purposes only).
	 *
	 * @param   boolean  $asHex  should numeric values be displayed as hexadecimal?
	 * @return  array    list of blocks
	 */
	public function getBlocks($asHex=false)
	{
		// Check that blocks are stored
		if (!$this->blocks) {return false;}

		// Build the block list
		$ret = array();
		foreach ($this->blocks AS $block) {
			$b = array();
			$b['type'] = isset($this->blockNames[$block['head_type']]) ? $this->blockNames[$block['head_type']] : 'Unknown';
			if ($block['head_type'] == self::BLOCK_SUB && isset($this->subblockNames[$block['file_name']])) {
				$b['sub_type'] = $this->subblockNames[$block['file_name']];
			}
			if ($asHex) foreach ($block AS $key=>$val) {
				$b[$key] = is_numeric($val) ? dechex($val) : $val;
			} else {
				$b += $block;
			}

			// Sanity check filename length
			if (isset($b['file_name'])) {$b['file_name'] = substr($b['file_name'], 0, $this->maxFilenameLength);}
			$ret[] = $b;
		}

		return $ret;
	}

	/**
	 * Parses the stored blocks and returns a list of records for each of the
	 * files in the archive.
	 *
	 * @param   boolean  $skipDirs  should directory entries be skipped?
	 * @return  mixed    false if no file blocks available, or array of file records
	 */
	public function getFileList($skipDirs=false)
	{
		// Check that blocks are stored
		if (!$this->blocks) {return false;}

		// Build the file list
		$ret = array();
		foreach ($this->blocks AS $block) {
			if ($block['head_type'] == self::BLOCK_FILE) {
				if ($skipDirs && !empty($block['is_dir'])) {continue;}
				$ret[] = $this->getFileBlockSummary($block);
			}
		}

		return $ret;
	}

	/**
	 * Returns a processed summary of a RAR File block.
	 *
	 * @param   array  $block  a valid File block
	 * @return  array  summary information
	 */
	protected function getFileBlockSummary($block)
	{
		$ret = array(
			'name' => !empty($block['file_name']) ? substr($block['file_name'], 0, $this->maxFilenameLength) : 'Unknown',
			'size' => isset($block['unp_size']) ? $block['unp_size'] : 0,
			'date' => !empty($block['ftime']) ? self::dos2unixtime($block['ftime']) : 0,
			'pass' => isset($block['has_password']) ? ((int) $block['has_password']) : 0,
			'next_offset' => $block['next_offset'],
		);
		if (!empty($block['is_dir'])) {$ret['is_dir'] = 1;}
		if (!empty($block['split_after']) || !empty($block['split_before'])) {$ret['split'] = 1;}

		return $ret;
	}

	/**
	 * List of block types and Subblock subtypes without bodies.
	 * @var array
	 */
	protected $headersOnly = array(
		'type'    => array(),
		'subtype' => array()
	);

	/**
	 * List of blocks found in the archive.
	 * @var array
	 */
	protected $blocks;

	/**
	 * Searches for a valid file header in the data or file, and moves the current
	 * pointer to its starting offset.
	 *
	 * This (slow) hack is only useful when handling RAR file fragments.
	 *
	 * @return  boolean  false if no valid File header is found
	 */
	protected function findFileHeader()
	{
		$dataSize = $this->data ? $this->dataSize : $this->fileSize;
		while ($this->offset < $dataSize) try {

			// Search for a BLOCK_FILE byte hint
			if (ord($this->read(1)) != self::BLOCK_FILE || $this->offset < 3) {continue;}
			$this->seek($this->offset - 3);

			// Run a File header CRC check
			$block = $this->getNextBlock();
			if ($this->checkFileHeaderCRC($block)) {

				// A valid File header was found
				$this->seek($block['offset']);
				return true;
			}

			// Continue searching from the next byte
			$this->seek($block['offset'] + 3);
			continue;

 		// No more readable data, or read error
		} catch (Exception $e) {
			return false;
		}
	}

	/**
	 * Runs a File Header CRC check on a valid File block.
	 *
	 * @param   array    $block  a valid File block
	 * @return  boolean  false if CRC check fails
	 */
	protected function checkFileHeaderCRC($block)
	{
		// Get the File header CRC data
		$this->seek($block['offset'] + 2);
		try {
			$data = $this->read($block['head_size'] - 2);
			$crc = crc32($data) & 0xffff;
			if ($crc === $block['head_crc']) {
				return true;
			}

		// No more readable data, or read error
		} catch (Exception $e) {
			return false;
		}

		return false;
	}

	/**
	 * Returns the position of the RAR Marker block in the stored data or file.
	 *
	 * @return  mixed  Marker Block position, or false if block is missing
	 */
	protected function findMarkerBlock()
	{
		if ($this->data) {
			return strpos($this->data, pack('H*', $this->markerBlock));
		}
		try {
			$buff = $this->read(min($this->fileSize, $this->maxReadBytes));
			$this->rewind();
			return strpos($buff, pack('H*', $this->markerBlock));

		} catch (Exception $e) {
			return false;
		}
	}

	/**
	 * Parses the RAR data and stores a list of valid blocks locally.
	 *
	 * @return  boolean  false if parsing fails
	 */
	protected function analyze()
	{
		// Find the MARKER block, if there is one
		$startPos = $this->findMarkerBlock();
		if ($startPos === false && !$this->isFragment) {

			// Not a RAR fragment or valid file, so abort here
			$this->error = 'Could not find Marker block, not a valid RAR file';
			return false;

		} elseif ($startPos !== false) {

			// Add the Marker block to the list
			$this->seek($startPos);
			$block = array('offset' => $startPos);
			$block += self::unpack(self::FORMAT_BLOCK_HEADER, $this->read(7), false);
			$this->blocks[] = $block;

		} elseif ($this->isFragment) {

			// Search for a valid file header and continue unpacking from there
			if ($this->findFileHeader() === false) {
				$this->error = 'Could not find a valid File header';
				return false;
			}
		}

		// Analyze all remaining blocks
		$dataSize = $this->data ? $this->dataSize : $this->fileSize;
		while ($this->offset < $dataSize) try {

			// Get the next block header
			$block = $this->getNextBlock();

			// Process the current block by type
			$this->processBlock($block);

			// Add the current block to the list
			$this->blocks[] = $block;

			// Skip to the next block, if any
			$this->seek($block['next_offset']);

			// Sanity check
			if ($block['offset'] == $this->offset) {
				$this->error = 'Parsing seems to be stuck';
				$this->close();
				return false;
			}

		// No more readable data, or read error
		} catch (Exception $e) {
			if ($this->error) {$this->close(); return false;}
			break;
		}

		// Analysis was successful
		return true;
	}

	/**
	 * Reads the start of the next block header and returns the common block
	 * info before further processing by block type.
	 *
	 * @return  array  the next block header info
	 */
	protected function getNextBlock()
	{
		// Start the block info
		$block = array('offset' => $this->offset);

		// Unpack the block header
		$block += self::unpack(self::FORMAT_BLOCK_HEADER, $this->read(7), false);

		// Check for add_size field
		if (($block['head_flags'] & self::LONG_BLOCK)
			&& ($block['head_type'] != self::BLOCK_FILE)
			&& ($block['head_type'] != self::BLOCK_SUB)
			) {
			$block += self::unpack('Vadd_size', $this->read(4));
		} else {
			$block['add_size'] = 0;
		}

		// Add offset info for next block (if any)
		$block['next_offset'] = $block['offset'] + $block['head_size'] + $block['add_size'];

		// Return the block info
		return $block;
	}

	/**
	 * Processes a block passed by reference based on its type.
	 *
	 * @param   array  $block  the block to process
	 * @return  void
	 */
	protected function processBlock(&$block)
	{
		// Block type: ARCHIVE
		if ($block['head_type'] == self::BLOCK_MAIN) {

			// Unpack the remainder of the Archive block header
			$block += self::unpack('vreserved1/Vreserved2', $this->read(6));

			// Parse Archive flags
			if ($block['head_flags'] & self::MAIN_VOLUME) {
				$this->isVolume = true;
			}
			if ($block['head_flags'] & self::MAIN_AUTH) {
				$this->hasAuth = true;
			}
			if ($block['head_flags'] & self::MAIN_RECOVERY) {
				$this->hasRecovery = true;
			}
			if ($block['head_flags'] & self::MAIN_PASSWORD) {
				$this->isEncrypted = true;
			}
		}

		// Block type: ARCHIVE END
		elseif ($block['head_type'] == self::BLOCK_ENDARC) {
			$block['more_volumes'] = (bool) ($block['head_flags'] & self::ENDARC_NEXT_VOLUME);
		}

		// Block type: FILE or SUBBLOCK (new style)
		elseif ($block['head_type'] == self::BLOCK_FILE || $block['head_type'] == self::BLOCK_SUB) {

			// Unpack the remainder of the block header
			$block += self::unpack(self::FORMAT_FILE_HEADER, $this->read(25));

			// Large file sizes
			if ($block['head_flags'] & self::FILE_LARGE) {
				$block += self::unpack('Vhigh_pack_size/Vhigh_unp_size', $this->read(8));
				$block['pack_size'] = self::int64($block['pack_size'], $block['high_pack_size']);
				$block['unp_size'] = self::int64($block['unp_size'], $block['high_unp_size']);
			}

			// Is this a directory entry?
			if (($block['head_flags'] & self::FILE_DICTMASK) == self::FILE_DIRECTORY) {
				$block['is_dir'] = true;
			}

			// Filename: unicode
			if ($block['head_flags'] & self::FILE_UNICODE) {

				// Split the standard filename and unicode data from the file_name field
				$fn = explode("\x00", $this->read($block['name_size']));

				// Decompress the unicode filename, encode the result as UTF-8
				$uc = new RarUnicodeFilename($fn[0], $fn[1]);
				if ($ucname = $uc->decode()) {
					$block['file_name'] = @iconv('UTF-16LE', 'UTF-8//IGNORE//TRANSLIT', $ucname);

				// Fallback to the standard filename
				} else {
					$block['file_name'] = $fn[0];
				}

			// Filename: non-unicode
			} else {
				$block['file_name'] = $this->read($block['name_size']);
			}

			// Salt (optional)
			if ($block['head_flags'] & self::FILE_SALT) {
				$block['salt'] = $this->read(8);
			}

			// Extended time fields (optional)
			if ($block['head_flags'] & self::FILE_EXTTIME) {
				$block['ext_time'] = true;
			}

			// Encrypted with password?
			if ($block['head_flags'] & self::FILE_PASSWORD) {
				$block['has_password'] = true;
			}

			// Continued from previous volume?
			if ($block['head_flags'] & self::FILE_SPLIT_BEFORE) {
				$block['split_before'] = true;
			}

			// Continued in next volume?
			if ($block['head_flags'] & self::FILE_SPLIT_AFTER) {
				$block['split_after'] = true;
			}

			// Increment the file count
			if ($block['head_type'] == self::BLOCK_FILE) {
				$this->fileCount++;
			}

			// Update next header block offset
			if (($block['head_type'] == self::BLOCK_FILE && !in_array(self::BLOCK_FILE, $this->headersOnly['type']))
			 || ($block['head_type'] == self::BLOCK_SUB  && !in_array($block['file_name'], $this->headersOnly['subtype']))
			) {
				$block['next_offset'] += $block['pack_size'];
			}
		}

		// Parse any useful Subblock info
		if ($block['head_type'] == self::BLOCK_SUB) {

			// Authenticity verification
			if ($block['file_name'] == self::SUBTYPE_AUTHVER) {
				$block += self::unpack('vav_name_size', $this->read(2));
				$block['av_file_name'] = $this->read($block['av_name_size']);
				$block += self::unpack('vav_unknown/vav_cname_size', $this->read(4)); // guesswork
				$block['av_created_by'] = $this->read($block['av_cname_size']);
			}
		}
	}

	/**
	 * Resets the instance variables before parsing new data.
	 *
	 * @return  void
	 */
	protected function reset()
	{
		parent::reset();

		$this->isVolume = null;
		$this->hasAuth = null;
		$this->hasRecovery = null;
		$this->isEncrypted = null;
		$this->blocks = null;
	}

} // End RarInfo class

/**
 * RarUnicodeFilename class.
 *
 * This utility class handles the unicode filename decompression for RAR files. It is
 * adapted directly from Marko Kreen's python script rarfile.py.
 *
 * @link https://github.com/markokr/rarfile
 *
 * @version 1.2
 */
class RarUnicodeFilename
{
	/**
	 * Initializes the class instance.
	 *
	 * @param   string  $stdName  the standard filename
	 * @param   string  $encData  the unicode data
	 * @return  void
	 */
	public function __construct($stdName, $encData)
	{
		$this->stdName = $stdName;
		$this->encData = $encData;
	}

	/**
	 * Decompresses the unicode filename by combining the standard filename with
	 * the additional unicode data, return value is encoded as UTF-16LE.
	 *
	 * @return  mixed  the unicode filename, or false on failure
	 */
	public function decode()
	{
		$highByte = $this->encByte();
		$encDataLen = strlen($this->encData);
		$flagBits = 0;

		while ($this->encPos < $encDataLen) {
			if ($flagBits == 0) {
				$flags = $this->encByte();
				$flagBits = 8;
			}
			$flagBits -= 2;

			switch (($flags >> $flagBits) & 3) {
				case 0:
					$this->put($this->encByte(), 0);
					break;
				case 1:
					$this->put($this->encByte(), $highByte);
					break;
				case 2:
					$this->put($this->encByte(), $this->encByte());
					break;
				default:
					$n = $this->encByte();
					if ($n & 0x80) {
						$c = $this->encByte();
						for ($i = 0; $i < (($n & 0x7f) + 2); $i++) {
							$lowByte = ($this->stdByte() + $c) & 0xFF;
							$this->put($lowByte, $highByte);
						}
					} else {
						for ($i = 0; $i < ($n + 2); $i++) {
							$this->put($this->stdByte(), 0);
						}
					}
			}
		}

		// Return the unicode string
		if ($this->failed) {return false;}
		return $this->output;
	}

	/**
	 * The standard filename data.
	 * @var string
	 */
	protected $stdName;

	/**
	 * The unicode data used for processing.
	 * @var string
	 */
	protected $encData;

	/**
	 * Pointer for the standard filename data.
	 * @var integer
	 */
	protected $pos = 0;

	/**
	 * Pointer for the unicode data.
	 * @var integer
	 */
	protected $encPos = 0;

	/**
	 * Did the decompression fail?
	 * @var boolean
	 */
	protected $failed = false;

	/**
	 * Decompressed unicode filename string.
	 * @var string
	 */
	protected $output;

	/**
	 * Gets the current byte value from the unicode data and increments the
	 * pointer if successful.
	 *
	 * @return  integer  encoded byte value, or 0 on fail
	 */
	protected function encByte()
	{
		if (isset($this->encData[$this->encPos])) {
			$ret = ord($this->encData[$this->encPos]);
		} else {
			$this->failed = true;
			$ret = 0;
		}
		$this->encPos++;
		return $ret;
	}

	/**
	 * Gets the current byte value from the standard filename data.
	 *
	 * @return  integer  standard byte value, or placeholder on fail
	 */
	protected function stdByte()
	{
		if (isset($this->stdName[$this->pos])) {
			return ord($this->stdName[$this->pos]);
		}
		$this->failed = true;
		return ord('?');
	}

	/**
	 * Builds the output for the unicode filename string in 16-bit blocks (UTF-16LE).
	 *
	 * @param   integer  $low   low byte value
	 * @param   integer  $high  high byte value
	 * @return  void
	 */
	protected function put($low, $high)
	{
		$this->output .= chr($low);
		$this->output .= chr($high);
		$this->pos++;
	}

} // End RarUnicodeFilename class
