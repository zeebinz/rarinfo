<?php
/**
 * RarInfo class.
 * 
 * A simple class for inspecting RAR file data and listing information about 
 * the archive contents in pure PHP (no external dependencies). Data can be 
 * loaded directly from a file or from a variable passed by reference.
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
 * @copyright  (c) 2010-2011 Hecks
 * @license    Modified BSD
 * @version    2.4
 *
 * CHANGELOG:
 * ----------
 * 2.4 Better method for unpacking unsigned longs
 * 2.3 Added skipping of directory entries, unicode fixes
 * 2.2 Fixed some seeking issues, added more Archive End info
 * 2.1 Better support for analyzing large files from disk via open()
 * 2.0 Proper unicode support with ported UnicodeFilename class
 * 1.9 Basic unicode support, fixed password & salt info
 * 1.8 Better info for multipart files, added PACK_SIZE properly
 * 1.7 Improved support for RAR file fragments
 * 1.6 Added extra error checking to read method
 * 1.5 Improved getSummary method output
 * 1.4 Added filename sanity checks & maxFilenameLength variable
 * 1.3 Fixed issues with some file headers lacking LONG_BLOCK flag
 * 1.2 Tweaked seeking method
 * 1.1 Fixed issues with PHP not handling unsigned longs properly (pfft)
 * 1.0 Initial release
 *
 */
class RarInfo
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
	
	/**#@-*/
	
	/**
	 * Format for unpacking the main part of each block header.
	 */
	const FORMAT_BLOCK_HEADER = 'vhead_crc/Chead_type/vhead_flags/vhead_size';

	/**
	 * Format for unpacking the remainder of a File block header.
	 */
	const FORMAT_FILE_HEADER = 'Vpack_size/Vunp_size/Chost_os/Vfile_crc/Vftime/Cunp_ver/Cmethod/vname_size/Vattr';
	
	/**
	 * Signature for the Marker block.
	 */	
	const MARKER_BLOCK = '526172211a0700';
	
	
	// ------ Class variables and methods -----------------------------------------

	/**
	 * List of block names corresponding to block types.
	 * @var array
	 */	
	static $blockNames = array(
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
	
	// ------ Instance variables and methods ---------------------------------------
	
	/**
	 * Is the volume attribute set for the archive?
	 * @var bool
	 */
	public $isVolume;
	
	/**
	 * Is authenticity information present?
	 * @var bool
	 */
	public $hasAuth;
	
	/**
	 * Is a recovery record present?
	 * @var bool
	 */
	public $hasRecovery;

	/**
	 * Is the archive encrypted with a password?
	 * @var bool
	 */
	public $isEncrypted;
	
	/**
	 * The last error message.
	 * @var string
	 */
	public $error;
		
	/**
	 * Opens a handle to the archive file, or loads data from a file fragment up to
	 * maxReadBytes, and analyzes the archive contents.
	 *
	 * @param   string  path to the file
	 * @param   bool    true if file is a RAR fragment
	 * @return  bool    false if archive analysis fails
	 */
	public function open($file, $isFragment=false)
	{
		$this->isFragment = $isFragment;
		$this->reset();
		if (!($rarFile = realpath($file))) {
			trigger_error("File does not exist ($file)", E_USER_WARNING);
			$this->error = 'File does not exist';
			return false;
		}
		$this->rarFile = $rarFile;
		$this->fileSize = filesize($rarFile);
		
		if ($isFragment) {
			
			// Read the fragment into memory
			$this->data = file_get_contents($rarFile, NULL, NULL, 0, $this->maxReadBytes);
			$this->dataSize = strlen($this->data);
			
		} else {
		
			// Open the file handle
			$this->handle = fopen($rarFile, 'r');
		}
		
		return $this->analyze();
	}

	/**
	 * Closes any open file handle.
	 *
	 * @return  void
	 */	
	public function close()
	{
		if (is_resource($this->handle)) {
			fclose($this->handle);
		}
	}
	
	/**
	 * Loads data passed by reference (up to maxReadBytes) and analyzes the 
	 * archive contents.
	 *
	 * This method is recommended when dealing with RAR file fragments.
	 *
	 * @param   string  archive data stored in a variable
	 * @param   bool    true if data is a RAR fragment
	 * @return  bool    false if archive analysis fails
	 */	
	public function setData(&$data, $isFragment=false)
	{
		$this->isFragment = $isFragment;
		$this->reset();
		
		$this->data = substr($data, 0, $this->maxReadBytes);
		$this->dataSize = strlen($this->data);

		return $this->analyze();
	}

	/**
	 * Sets the maximum number of data bytes to be stored.
	 *
	 * @param   integer maximum bytes
	 * @return  void
	 */
	public function setMaxBytes($bytes)
	{
		if (is_int($bytes)) {$this->maxReadBytes = $bytes;}
	}
	
	/**
	 * Convenience method that outputs a summary list of the archive information,
	 * useful for pretty-printing.
	 *
	 * @param   bool   add file list to output?
	 * @return  array  archive summary
	 */	
	public function getSummary($full=false)
	{
		$summary = array(
			'rar_file' => $this->rarFile,
			'file_size' => $this->fileSize,
			'data_size' => $this->dataSize,
			'is_volume' => (int) $this->isVolume,
			'has_auth' => (int) $this->hasAuth,
			'has_recovery' => (int) $this->hasRecovery,
			'is_encrypted' => (int) $this->isEncrypted,
		);
		$fileList = $this->getFileList();
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
	 * @param   bool   should numeric values be displayed as hexadecimal?
	 * @return  array  list of blocks
	 */	
	public function getBlocks($asHex=false)
	{
		// Check that blocks are stored
		if (!$this->blocks) {return false;}
		
		// Build the block list
		$ret = array();
		foreach ($this->blocks AS $block) {
			$b = array();
			$b['type'] = isset(self::$blockNames[$block['head_type']]) ? self::$blockNames[$block['head_type']] : 'Unknown';
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
	 * @param   bool   should directory entries be skipped?
	 * @return  mixed  false if no file blocks available, or array of file records
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
				$arr = array(
					'name' => !empty($block['file_name']) ? substr($block['file_name'], 0, $this->maxFilenameLength) : 'Unknown',
					'size' => isset($block['unp_size']) ? $block['unp_size'] : 0,
					'date' => !empty($block['ftime']) ? $this->dos2unixtime($block['ftime']) : 0,
					'pass' => (int) $block['has_password'],
					'next_offset' => $block['next_offset'],
				);
				if (!empty($block['is_dir'])) {$arr['is_dir'] = 1;}
				if (!empty($block['split_after']) || !empty($block['split_before'])) {$arr['split'] = 1;}
				$ret[] = $arr;
			}
		}
		
		return $ret;
	}
	
	/**
	 * Path to the RAR file (if any).
	 * @var string
	 */
	protected $rarFile;

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
	 * Have the archive contents been analyzed?
	 * @var bool
	 */
	protected $isAnalyzed = false;

	/**
	 * Is this a RAR file/data fragment?
	 * @var bool
	 */
	protected $isFragment = false;	
	
	/**
	 * The stored RAR file data.
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
	 * @return  bool  false if no valid file header is found
	 */
	protected function findFileHeader() 
	{
		$dataSize = $this->data ? $this->dataSize : $this->fileSize;
		while ($this->offset < $dataSize) try {
		
			// Get the current block header
			$block = array('offset' => $this->offset);
			$block += $this->unpack(self::FORMAT_BLOCK_HEADER, $this->read(7), false);

			if ($block['head_type'] == self::BLOCK_FILE) {
				
				// Run file header CRC check
				if ($this->checkFileHeaderCRC($block) === false) {
				
					// Skip to next byte to continue searching for valid header
					$this->seek($block['offset'] + 1);
					continue;
				
				} else {
					
					// A valid file header was found
					$this->seek($block['offset']);
					return true;
				}
			
			} else {
			
				// Skip to next byte to continue searching for valid header
				$this->seek($block['offset'] + 1);
			}
			
 		// No more readable data, or read error
		} catch (Exception $e) {
			return false;
		}
	}

	/**
	 * Runs a File Header CRC check on a valid file block.
	 *
	 * @param   array  a valid file block
	 * @return  bool   false if CRC check fails
	 */
	protected function checkFileHeaderCRC($block)
	{
		// Get the file header CRC data
		$this->seek($block['offset'] + 2);
		try {
			$data = $this->read($block['head_size'] - 2);
			$crc = crc32($data) & 0xffff;
			if ($crc !== $block['head_crc']) {
				return false;
			}
		
		// No more readable data, or read error
		} catch (Exception $e) {
			return false;
		}
		
		return true;
	}

	/**
	 * Returns the position of the RAR Marker block in the stored data or file.
	 *
	 * @return  mixed  Marker Block position, or false if block is missing
	 */	
	protected function findMarkerBlock()
	{
		if ($this->data) {
			return strpos($this->data, pack('H*', self::MARKER_BLOCK));
		}
		try {
			$buff = $this->read(min($this->fileSize - 1, $this->maxReadBytes));
			$this->rewind();
			return strpos($buff, pack('H*', self::MARKER_BLOCK));
			
		} catch (Exception $e) {
			return false;
		}
	}
		
	/**
	 * Parses the RAR data and stores a list of valid blocks locally.
	 *
	 * @return  bool  false if parsing fails
	 */
	protected function analyze()
	{
		// Find the MARKER block, if there is one
		$startPos = $this->findMarkerBlock();
		if ($startPos === false && !$this->isFragment) {
			
			// Not a RAR fragment or valid file, so abort here
			trigger_error('Not a valid RAR file', E_USER_WARNING);
			$this->error = 'Could not find Marker Block, not a valid RAR file';
			return false;
		
		} elseif ($startPos !== false) {
		
			// Add the Marker block to the list
			$this->seek($startPos);
			$block = array('offset' => $startPos);
			$block += $this->unpack(self::FORMAT_BLOCK_HEADER, $this->read(7), false);
			$this->blocks[] = $block;
		
		} elseif ($this->isFragment) {
		
			// Search for a valid file header and continue unpacking from there
			if ($this->findFileHeader() === false) {
				$this->error = 'Could not find a valid File Header';
				return false;
			}
		}

		// Analyze all remaining blocks
		$dataSize = $this->data ? $this->dataSize : $this->fileSize;
		while ($this->offset < $dataSize) try {
		
			// Get the current block header
			$block = array('offset' => $this->offset);
			$block += $this->unpack(self::FORMAT_BLOCK_HEADER, $this->read(7), false);
			if (($block['head_flags'] & self::LONG_BLOCK)
				&& ($block['head_type'] != self::BLOCK_FILE)
				) {
				$block += $this->unpack('Vadd_size', $this->read(4));
			} else {
				$block['add_size'] = 0;
			}

			// Add offset info for next block (if any)
			$block['next_offset'] = $block['offset'] + $block['head_size'] + $block['add_size'];

			// Block type: ARCHIVE
			if ($block['head_type'] == self::BLOCK_MAIN) {
			
				// Unpack the remainder of the Archive block header
				$block += $this->unpack('vreserved1/Vreserved2', $this->read(6));
				
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
			
			// Block type: FILE
			elseif ($block['head_type'] == self::BLOCK_FILE) {
				
				// Unpack the remainder of the File block header
				$block += $this->unpack(self::FORMAT_FILE_HEADER, $this->read(25));
												
				// Large file sizes
				if ($block['head_flags'] & self::FILE_LARGE) {
					$block += $this->unpack('Vhigh_pack_size/Vhigh_unp_size', $this->read(8));
					$block['pack_size'] += ($block['high_pack_size'] * 0x100000000);
					$block['unp_size'] += ($block['high_unp_size'] * 0x100000000);
				}
				
				// Update next header block offset
				$block['next_offset'] += $block['pack_size'];
		
				// Is this a directory entry?
				if (($block['head_flags'] & self::FILE_DIRECTORY) == self::FILE_DIRECTORY) {
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
				$block['has_password'] = (bool) ($block['head_flags'] & self::FILE_PASSWORD);
				
				// Continued from previous volume?
				if ($block['head_flags'] & self::FILE_SPLIT_BEFORE) {
					$block['split_before'] = true;
				}
				
				// Continued in next volume?
				if ($block['head_flags'] & self::FILE_SPLIT_AFTER) {
					$block['split_after'] = true;
				}				
			}
			
			// Add current block to the list
			$this->blocks[] = $block;
			
			// Skip to the next block, if any
			$this->seek($block['next_offset']);
		
			// Sanity check
			if ($block['offset'] == $this->offset) {
				trigger_error('Parsing failed', E_USER_WARNING);
				$this->error = 'Parsing seems to be stuck';
				return false;
			}
			
		// No more readable data, or read error
		} catch (Exception $e) {
			if ($this->error) {return false;}
			break;
		}

		// End	
		$this->isAnalyzed = true;
		$this->close();
		return true;
	}
	
	/**
	 * Unpacks data from a binary string.
	 *
	 * This method helps in particular to fix unpacking of unsigned longs on 32-bit
	 * systems due to PHP internal quirks.
	 *
	 * @param   string  format codes for unpacking
	 * @param   string  the packed string
	 * @param   bool    should unsigned longs be fixed?
	 * @return  array   the unpacked data
	 */
	protected function unpack($format, $data, $fixLongs=true)
	{
		$unpacked = unpack($format, $data);
		
		// Fix conversion of unsigned longs on 32-bit systems
		if ($fixLongs && PHP_INT_SIZE <= 4 && strpos($format, 'V') !== false) {
			$codes = explode('/', $format);
			foreach ($unpacked as $key=>$value) {
				$code = array_shift($codes);
				if ($code[0] == 'V' && $value < 0) {
					$unpacked[$key] = $value + 4294967296; // converts to float
				}
			}
		}
		
		return $unpacked;
	}
		
	/**
	 * Reads the given number of bytes from the stored data and moves the 
	 * pointer forward.
	 *
	 * @param   integer  number of bytes to read
	 * @return  string   byte string
	 */
	protected function read($num)
	{
		// Check that enough data is available
		$newPos = $this->offset + $num;
		if (($this->data && ($newPos > ($this->dataSize - 1)))
			|| (!$this->data && ($newPos > ($this->fileSize - 1)))
			) {
			throw new Exception('End of readable data reached');
		}
		
		// Read the requested bytes
		if ($this->data) {
			$read = substr($this->data, $this->offset, $num);
		} else {
			$read = fread($this->handle, $num);
		}
		
		// Confirm read length
		$rlen = strlen($read);
		if ($rlen < $num) {
			$this->error = "Not enough data to read ({$num} bytes requested, {$rlen} available)";
			trigger_error($this->error, E_USER_WARNING);
			throw new Exception('Read error');
		}
		
		// Move the data pointer
		$this->offset = $newPos;
		
		return $read;
	}
	
	/**
	 * Moves the current pointer to the given position in the stored data or file.
	 *
	 * @param   integer  new pointer position
	 * @return  void
	 */
	protected function seek($pos)
	{
		if ($this->data && ($pos > ($this->dataSize - 1) || $pos < 0)) {
			$pos = $this->dataSize;
		} elseif (!$this->data && ($pos > ($this->fileSize - 1) || $pos < 0)) {
			$pos = $this->fileSize;
		}
		if (!$this->data) {
			fseek($this->handle, $pos, 'SEEK_SET');
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
	 * Converts DOS standard timestamps to UNIX timestamps.
	 *
	 * @param   integer  DOS timestamp
	 * @return  integer  UNIX timestamp
	 */
	protected function dos2unixtime($dostime)
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
	 * Resets the instance variables before parsing new data.
	 *
	 * @return  void
	 */
	protected function reset()
	{
		$this->rarFile = null;
		$this->data = null;
		$this->fileSize = null;
		$this->dataSize = null;
		$this->offset = 0;
		$this->isAnalyzed = false;
		$this->error = null;
		$this->isVolume = null;
		$this->hasAuth = null;
		$this->hasRecovery = null;
		$this->isEncrypted = null;
		$this->blocks = null;
		$this->close();
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
 * CHANGELOG:
 * ----------
 * 1.2 Fixed issues with byte processing
 * 1.1 Renamed class to avoid collisions
 * 1.0 Initial release
 *
 */
class RarUnicodeFilename
{	
	/**
	 * Initializes the class instance.
	 *
	 * @param   string  the standard filename
	 * @param   string  the unicode data
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
	 * @var bool
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
	 * @param   integer  low byte value
	 * @param   integer  high byte value
	 * @return  void
	 */		
	protected function put($low, $high)
	{
		$this->output .= chr($low);
		$this->output .= chr($high);
		$this->pos++;
	}
	
} // End RarUnicodeFilename class
