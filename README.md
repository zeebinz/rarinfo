A set of basic utility classes for working with RAR archives and related parity
and verification files in pure PHP (no external dependencies). See the [Releases]
(https://github.com/zeebinz/rarinfo/releases) page for versioned releases of the
whole library, which contains:

ArchiveReader
-------------------------------
Abstract base class for the various file inspectors that defines the basic API
and implements common methods for file/data handling.

- 2.7 Added strposall() static method
- 2.6 Added methods for creating temporary files
- 2.5 Added support for ArchiveInfo
- 2.4 Fixed getting file sizes on Windows without com_dotnet loaded
- 2.3 Cleaned up code, added convert2hex() method
- 2.2 Added support for Windows timestamps and some tweaks
- 2.1 Code cleanup, made file property protected
- 2.0 Made get/save range methods protected
- 1.9 Improved range checks
- 1.8 Added methods for getting & saving file data by range
- 1.7 Added support for setting analysis byte ranges, improved handling of
      archive file fragments, added default constructor, and misc fixes.
- 1.6 Improved property initialization
- 1.5 Improved method for unpacking unsigned longs
- 1.4 Seeking in files beyond PHP_INT_MAX now throws exception
- 1.3 Improved filesize calculation for large files
- 1.2 Added dos2unixtime() from RarInfo
- 1.1 Added int64() method for handling 64-bit integers
- 1.0 Initial release (derived from RarInfo v2.8, with bugfixes)

ArchiveInfo (extends ArchiveReader)
-----------------------------------
Example class that provides a facade for all the readers in the library, and also
allows recursive inspection of archives packed within archives.

- 2.0 Improved error reporting for recursive extraction
- 1.9 Added support for recursive extraction using external clients
- 1.8 Restricted stored archives to supported types only
- 1.7 Added ability to reset the readers list dynamically
- 1.6 Added method for setting the readers list per instance
- 1.5 Restricted next_offset values to main archive files only
- 1.4 Added option for getArchiveFileList() to merge all archive file lists
- 1.3 Improved performance and error reporting
- 1.2 Fixed allowsRecursion() method to return only booleans
- 1.1 Fixed backward-compatibility with PHP < 5.3.0
- 1.0 Initial release

RarInfo (extends ArchiveReader)
-------------------------------
Class for inspecting the contents of RAR archives.

- 5.1 Speeded up findFileHeader() method quite a bit
- 5.0 Improved handling of external clients, including Windows fix
- 4.9 Added option for extracting files with external clients
- 4.8 Tweaked File header sanity check
- 4.7 Added support for ArchiveInfo
- 4.6 Fixed handling of archives with encrypted headers
- 4.5 Improved handling of some corrupt sources
- 4.4 Improved analysis performance, cleaned up code, fixed b/c
- 4.3 Added handling of RAR 5.0 Quick Open data
- 4.2 Added basic support for the RAR 5.0 archive format
- 4.1 Added handling of zero-padded RAR files
- 4.0 Tweaked handing of file block headers and summaries
- 3.9 Improved methods for extracting file contents
- 3.8 Added support for byte ranges, better handling of RAR fragments
- 3.7 Added more info to Archive block output
- 3.6 Improved handling of Marker blocks, with new test files
- 3.5 Improved Marker block searching
- 3.4 Improved property initialization
- 3.3 Moved dos2unixtime() to ArchiveReader
- 3.2 Added RarInfo::getFileData() and RarInfo::saveFileData();
      Data & open file handles now persist until closed manually
- 3.1 Now uses ArchiveReader::int64() for handling 64-bit integers
- 3.0 Improved speed when handling RAR file fragments
- 2.9 Refactored quite a lot to allow easier extension
- 2.8 Added support for files larger than PHP_INT_MAX bytes
- 2.7 Fixed read & seek issues
- 2.6 Improved input error checking, fixed reset bug
- 2.5 Code cleanup & optimization, added fileCount
- 2.4 Better method for unpacking unsigned longs
- 2.3 Added skipping of directory entries, unicode fixes
- 2.2 Fixed some seeking issues, added more Archive End info
- 2.1 Better support for analyzing large files from disk via open()
- 2.0 Proper unicode support with ported UnicodeFilename class
- 1.9 Basic unicode support, fixed password & salt info
- 1.8 Better info for multipart files, added PACK_SIZE properly
- 1.7 Improved support for RAR file fragments
- 1.6 Added extra error checking to read method
- 1.5 Improved getSummary method output
- 1.4 Added filename sanity checks & maxFilenameLength variable
- 1.3 Fixed issues with some file headers lacking LONG_BLOCK flag
- 1.2 Tweaked seeking method
- 1.1 Fixed issues with PHP not handling unsigned longs properly (pfft)
- 1.0 Initial release

RarUnicodeFilename (in rarinfo.php)
-----------------------------------
Class for handling unicode filenames in RAR archive listings.

- 1.2 Fixed issues with byte processing
- 1.1 Renamed class to avoid collisions
- 1.0 Initial release

SfvInfo (extends ArchiveReader)
-------------------------------
Class for inspecting the contents of SFV verification files.

- 1.9 Added use_range value to getSummary() output
- 1.8 Added support for ArchiveInfo
- 1.7 Added support for byte ranges
- 1.6 Fixed regex greediness with extra whitespaces
- 1.5 File comments are now stored
- 1.4 Now supports all line ending types
- 1.3 Improved check for valid SFV data only
- 1.2 Fixed last byte being discarded when analyzing
- 1.1 Results of getFileList() made consistent with other inspectors
- 1.0 Initial release

SrrInfo (extends RarInfo)
-------------------------------
Class for inspecting the contents of SRR files and reporting on the RAR files
that they cover, as well as allowing extraction of any stored files that they
might contain.

- 2.2 Tweak to support RarInfo 4.9
- 2.1 Added stricter check for SRR Marker block
- 2.0 Added support for ArchiveInfo
- 1.9 Improved analysis performance, cleaned up code
- 1.8 Added handling of OSO hash blocks
- 1.7 Improved handling of invalid extraction requests
- 1.6 Added support for byte ranges
- 1.5 Improved handling of Marker blocks
- 1.4 Improved Marker block searching
- 1.3 Improved property initialization
- 1.2 Data & open file handles now persist until closed manually
- 1.1 Added easier reporting of client info
- 1.0 Initial release

Par2Info (extends ArchiveReader)
--------------------------------
Class for inspecting the contents of PAR2 parity files and reporting on the
archives that they cover.

- 1.6 Added support for ArchiveInfo
- 1.5 Improved analysis performance
- 1.4 Added support for byte ranges
- 1.3 Added block count to file list entries
- 1.2 Improved property initialization
- 1.1 Fixed unpacking of unsigned longs
- 1.0 Initial release

ZipInfo (extends ArchiveReader)
--------------------------------
Class for inspecting the contents of ZIP archives.

- 1.9 Improved handling of external clients, including Windows fix
- 1.8 Added option for extracting files with external clients
- 1.7 Fixed finding the marker signature
- 1.6 Added support for ArchiveInfo
- 1.5 Improved analysis performance, cleaned up code
- 1.4 Improved methods for extracting file contents
- 1.3 Added support for byte ranges
- 1.2 Improved property initialization
- 1.1 Fixed filecount when CDR is missing
- 1.0 Initial release

PipeReader
-------------------------------
A utility class for handling the piped output of an external command.

- 1.1 Fixed visibility of pipe handle
- 1.0 Initial release


Testing
-------------------------------
Some basic unit tests are in `/tests`, with sample files in `/tests/fixtures`
(run `generate.php` from there first, and on each pull), more coverage and any
Github-friendly samples are always welcome. Some optional tests require external
binaries (see `/tests/bin/README.md`). Enjoy :)
