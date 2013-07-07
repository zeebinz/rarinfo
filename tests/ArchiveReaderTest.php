<?php

include_once dirname(__FILE__).'/../archivereader.php';

/**
 * Test case for ArchiveReader.
 *
 * @group  archive
 */
class ArchiveReaderTest extends PHPUnit_Framework_TestCase
{
	protected $fixturesDir;
	protected $testFile;

	/**
	 * This method is called before each test is executed.
	 */
	protected function setUp()
	{
		$ds = DIRECTORY_SEPARATOR;
		$this->fixturesDir = realpath(dirname(__FILE__).'/fixtures');
		$this->testFile = $this->fixturesDir.$ds.'rar'.$ds.'4mb.rar';
	}

	/**
	 * We need to be able to seek accurately through the available data and check
	 * that read requests are not out of bounds. Trying to read past the last byte
	 * or seeking out of bounds should throw an exception.
	 */
	public function testHandlesBasicSeekingAndReading()
	{
		$data = "some sample data for testing\n";
		$length = strlen($data);
		$archive = new TestArchiveReader;

		$archive->data   = $data;
		$archive->start  = 0;
		$archive->end    = $length - 1;
		$archive->length = $length;

		// Within bounds
		$archive->seek(0);
		$this->assertSame(0, $archive->offset);
		$archive->seek(3);
		$this->assertSame(3, $archive->offset);
		$archive->seek($archive->end);
		$this->assertSame($archive->end, $archive->offset);
		$archive->seek(0);
		$this->assertSame(0, $archive->offset);

		$read = $archive->read(0);
		$this->assertSame(0, $archive->offset);
		$this->assertSame('', $read);
		$read = $archive->read(1);
		$this->assertSame(1, $archive->offset);
		$this->assertSame('s', $read);
		$read = $archive->read(3);
		$this->assertSame(4, $archive->offset);
		$this->assertSame('ome', $read);
		$read = $archive->read(3);
		$this->assertSame(7, $archive->offset);
		$this->assertSame(' sa', $read);

		$archive->seek(5);
		$read = $archive->read(6);
		$this->assertSame(11, $archive->offset);
		$this->assertSame('sample', $read);
		$archive->seek($archive->end);
		$read = $archive->read(1);
		$this->assertSame($archive->end + 1, $archive->offset);
		$this->assertSame("\n", $read);

		$archive->seek(0);
		$read = $archive->read($length);
		$this->assertSame($archive->end + 1, $archive->offset);
		$this->assertSame($data, $read);

		// Out of bounds
		$archive->seek(0);
		try {
			$archive->seek($archive->end + 5);
		} catch (InvalidArgumentException $e) {}
		$this->assertSame(0, $archive->offset);
		try {
			$archive->seek(-1);
		} catch (InvalidArgumentException $e) {}
		$this->assertSame(0, $archive->offset);
		try {
			$archive->read($length + 1);
		} catch (InvalidArgumentException $e) {}
		$this->assertSame(0, $archive->offset);
		try {
			$archive->seek($archive->end + 1);
			$archive->read(1);
		} catch (InvalidArgumentException $e) {}
		$this->assertSame($archive->end + 1, $archive->offset);
		try {
			$archive->seek($archive->end);
			$read = $archive->read(2);
		} catch (InvalidArgumentException $e) {}
		$this->assertSame($archive->end, $archive->offset);
		try {
			$archive->seek($archive->end - 1);
			$read = $archive->read(3);
		} catch (InvalidArgumentException $e) {}
		$this->assertSame($archive->end - 1, $archive->offset);
	}

	/**
	 * Files can be streamed directly using the open() method, with any errors
	 * reported via the public $error property.
	 *
	 * @depends testHandlesBasicSeekingAndReading
	 */
	public function testHandlesFileStreams()
	{
		$archive = new TestArchiveReader;

		$this->assertTrue($archive->open($this->testFile));
		$this->assertEmpty($archive->error, $archive->error);
		$this->assertSame($this->testFile, $archive->file);
		$this->assertTrue(is_resource($archive->handle));
		$this->assertTrue($archive->analyzed);

		$summary = $archive->getSummary();
		$this->assertSame(filesize($this->testFile), $summary['fileSize']);
		$this->assertSame(0, $summary['dataSize']);

		$archive->close();
		$this->assertFalse(is_resource($archive->handle));

		$this->assertFalse($archive->open('missingfile'));
		$this->assertSame('File does not exist (missingfile)', $archive->error);
	}

	/**
	 * Data can be passed directly to the instance via the setData() method, with
	 * any errors reported via the public $error property.
	 *
	 * @depends testHandlesBasicSeekingAndReading
	 */
	public function testHandlesDataFromMemory()
	{
		$archive = new TestArchiveReader;
		$data = file_get_contents($this->testFile);

		$this->assertTrue($archive->setData($data));
		$this->assertEmpty($archive->error, $archive->error);
		$this->assertTrue($archive->analyzed);
		$this->assertSame($data, $archive->data);

		$summary = $archive->getSummary();
		$this->assertSame(strlen($data), $summary['dataSize']);
		$this->assertEmpty($summary['fileSize']);
		$archive->close();

		$this->assertFalse($archive->setData(''));
		$this->assertSame('No data was passed, nothing to analyze', $archive->error);
	}

	/**
	 * We should be able to specify the start and end points for the archive analysis
	 * transparently, with all offsets made relative to the given start point.
	 *
	 * @depends testHandlesFileStreams
	 * @depends testHandlesDataFromMemory
	 */
	public function testByteRangesCanBeSpecifiedForAnalysis()
	{
		$data = file_get_contents($this->testFile);
		$fsize = filesize($this->testFile);
		$archive = new TestArchiveReader;

		// Without set ranges
		$len  = $fsize - 1;
		$tell = $len - ($len % $archive->readSize);
		$archive->open($this->testFile);
		$this->assertSame(0, $archive->start);
		$this->assertSame($fsize - 1, $archive->end);
		$this->assertSame($tell, $archive->tell());

		$archive->setData($data);
		$this->assertSame(0, $archive->start);
		$this->assertSame($fsize - 1, $archive->end);
		$this->assertSame($tell, $archive->tell());

		// With set ranges
		$ranges = array(array(5, 9), array(0, 99), array(50, 249), array(5, $fsize - 1));

		foreach ($ranges as $range)
		{
			$len  = $range[1] - $range[0] + 1;
			$tell = $range[0] + ($len - ($len % $archive->readSize));

			$archive->open($this->testFile, false, $range);
			$this->assertSame($range[0], $archive->start);
			$this->assertSame($range[1], $archive->end);
			$this->assertSame($tell, $archive->tell(), "Length is: $len, offset is: {$archive->offset}");

			$archive->setData($data, false, $range);
			$this->assertSame($range[0], $archive->start);
			$this->assertSame($range[1], $archive->end);
			$this->assertSame($tell, $archive->tell(), "Length is: $len, offset is: {$archive->offset}");
		}
	}

	/**
	 * We shouldn't be able to set ranges that are out of the bounds of any set
	 * data or opened file, and we should handle these as errors rather than throw
	 * exceptions.
	 *
	 * @depends testByteRangesCanBeSpecifiedForAnalysis
	 */
	public function testInvalidByteRangesReturnErrors()
	{
		$data = file_get_contents($this->testFile);
		$archive = new TestArchiveReader;

		// Common checks
		$range = array(-1, -2);
		$regex = '/Start.*end.*positive/';
		$this->assertFalse($archive->setData($data, false, $range));
		$this->assertRegExp($regex, $archive->error);
		$this->assertEmpty($archive->data);
		$this->assertFalse($archive->open($this->testFile, false, $range));
		$this->assertRegExp($regex, $archive->error);
		$this->assertNull($archive->handle);

		$range = array(1.5, 3);
		$regex = '/Start.*end.*integer/';
		$this->assertFalse($archive->setData($data, false, $range));
		$this->assertRegExp($regex, $archive->error);
		$this->assertEmpty($archive->data);
		$this->assertFalse($archive->open($this->testFile, false, $range));
		$this->assertRegExp($regex, $archive->error);
		$this->assertNull($archive->handle);

		$range = array(2, 1);
		$regex = '/End.*must be higher than start/';
		$this->assertFalse($archive->setData($data, false, $range));
		$this->assertRegExp($regex, $archive->error);
		$this->assertEmpty($archive->data);
		$this->assertFalse($archive->open($this->testFile, false, $range));
		$this->assertRegExp($regex, $archive->error);
		$this->assertNull($archive->handle);

		// Setting data
		$archive->setMaxReadBytes(100);

		$range = array(1, 105);
		$regex = '/range.*is invalid/';
		$this->assertFalse($archive->setData($data, false, $range));
		$this->assertRegExp($regex, $archive->error);
		$this->assertEmpty($archive->data);

		$range = array(101, 105);
		$this->assertFalse($archive->setData($data, false, $range));
		$this->assertRegExp($regex, $archive->error);
		$this->assertEmpty($archive->data);

		// Opening file
		$range = array(0, filesize($this->testFile));
		$this->assertFalse($archive->open($this->testFile, false, $range));
		$this->assertRegExp($regex, $archive->error);
		$this->assertNull($archive->handle);
	}

	/**
	 * We should be able to retrieve data from the file/data source using any
	 * absolute byte range, ignoring any analysis range that has been set.
	 *
	 * @depends testInvalidByteRangesReturnErrors
	 */
	public function testAnyDataCanBeFetchedByByteRange()
	{
		$file = $this->fixturesDir.'/rar/commented.rar';
		$data = file_get_contents($file);
		$content = 'file content';
		$range = array(146, 157);
		$archive = new TestArchiveReader;

		// Within bounds
		$archive->open($file);
		$this->assertSame($content, $archive->getRange($range));
		$archive->setData($data);
		$this->assertSame($content, $archive->getRange($range));
		$archive->open($file, false, array(0, 1));
		$this->assertSame($content, $archive->getRange($range));
		$archive->setData($data, false, array(0, 1));
		$this->assertSame($content, $archive->getRange($range));

		// Out of bounds
		$archive->open($file);
		$archive->getRange(array(0, filesize($file)));
		$this->assertRegExp('/range.*is invalid/', $archive->error);
	}

	/**
	 * We need to be able to save any stored data to temporary files so that we
	 * can e.g. extract the archive contents using an external client. These
	 * temporary files should be deleted on reset or destruct.
	 *
	 * @depends testHandlesDataFromMemory
	 */
	public function testCanSaveDataToTemporaryFiles()
	{
		$archive = new TestArchiveReader;
		$data = file_get_contents($this->testFile);

		$archive->setData($data);
		$temp = $archive->createTempDataFile();
		$name = pathinfo($temp, PATHINFO_FILENAME);
		$this->assertArrayHasKey($name, $archive->tempFiles);
		$this->assertSame($temp, $archive->tempFiles[$name]);
		$this->assertFileExists($temp);
		$this->assertSame(strlen($data), filesize($temp));
		unset($archive);
		$this->assertFileNotExists($temp);
	}

} // End ArchiveReaderTest

class TestArchiveReader extends ArchiveReader
{
	// Abstract method implementations
	public function getSummary($full=false)
	{
		return array(
			'fileSize' => $this->fileSize,
			'dataSize' => $this->dataSize,
		);
	}

	public function getFileList() {}
	public function findMarker() {}

	protected function analyze()
	{
		while ($this->offset < $this->length) try {
			$this->read($this->readSize);
		} catch(Exception $e) {
			break;
		}
		$this->analyzed = true;
		return true;
	}

	// Added for test convenience
	public $analyzed = false;
	public $readSize = 3;

	// Made public for test convenience
	public $handle;
	public $data = '';
	public $offset = 0;
	public $length = 0;
	public $start = 0;
	public $end = 0;
	public $tempFiles = array();

	public function seek($pos)
	{
		parent::seek($pos);
	}

	public function read($num, $confirm=true)
	{
		return parent::read($num, $confirm);
	}

	public function tell()
	{
		return parent::tell();
	}

	public function getRange(array $range)
	{
		return parent::getRange($range);
	}

	public function createTempDataFile()
	{
		return parent::createTempDataFile();
	}
}
