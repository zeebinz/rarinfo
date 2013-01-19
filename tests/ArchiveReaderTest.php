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
	 * Files can be streamed directly using the open() method, with any errors
	 * reported via the public $error property.
	 */
	public function testHandlesFileStreams()
	{
		$archive = new TestArchiveReader;

		$this->assertTrue($archive->open($this->testFile));
		$this->assertNull($archive->error, $archive->error);
		$this->assertSame($this->testFile, $archive->file);
		$this->assertTrue(is_resource($archive->getHandle()));
		$this->assertTrue($archive->analyzed);

		$summary = $archive->getSummary();
		$this->assertSame(filesize($this->testFile), $summary['fileSize']);
		$this->assertNull($summary['dataSize']);

		$archive->close();
		$this->assertFalse(is_resource($archive->getHandle()));

		$this->assertFalse($archive->open('missingfile'));
		$this->assertSame('File does not exist (missingfile)', $archive->error);
	}


	/**
	 * Data can be passed directly to the instance via the setData() method, with
	 * any errors reported via the public $error property.
	 */
	public function testHandlesDataFromMemory()
	{
		$archive = new TestArchiveReader;
		$data = file_get_contents($this->testFile);

		$this->assertTrue($archive->setData($data));
		$this->assertNull($archive->error, $archive->error);
		$this->assertTrue($archive->analyzed);
		
		$summary = $archive->getSummary();
		$this->assertSame(strlen($data), $summary['dataSize']);
		$this->assertNull($summary['fileSize']);
		$archive->close();

		$this->assertFalse($archive->setData(''));
		$this->assertSame('No data was passed, nothing to analyze', $archive->error);		
	}

} // End ArchiveReaderTest

class TestArchiveReader extends ArchiveReader
{
	// Abstract method implementations
	public function getSummary() 
	{
		return array(
			'fileSize' => $this->fileSize,
			'dataSize' => $this->dataSize,
		);
	}

	public function getFileList() {}

	protected function analyze()
	{
		$this->analyzed = true;
		return true;
	}

	// Added for test convenience
	public $analyzed = false;

	public function getHandle()
	{
		return $this->handle;
	}
}
