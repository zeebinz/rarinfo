<?php

include_once dirname(__FILE__).'/../pipereader.php';

/**
 * Test case for PipeReader.
 *
 * @group  pipe
 */
class PipeReaderTest extends PHPUnit_Framework_TestCase
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
	 * When dealing with the piped output of a shell command, we should be able
	 * to seek/read the same as with file/data sources, although with the option
	 * to read all of the output without knowing its size in advance.
	 */
	public function testHandlesBasicSeekingAndReading()
	{
		$command = DIRECTORY_SEPARATOR === '\\'
			? 'type '.escapeshellarg($this->testFile).' 2>nul'
			: 'cat '.escapeshellarg($this->testFile);
		$filesize = filesize($this->testFile);

		$reader = new TestPipeReader;
		$reader->open($command);
		$this->assertEmpty($reader->error);
		$this->assertSame(0, $reader->tell());

		// Seeking
		$reader->seek(0);
		$this->assertSame(0, $reader->tell());
		$reader->seek($filesize);
		$this->assertSame($filesize, $reader->tell());
		$reader->seek(15);
		$this->assertSame(15, $reader->tell());
		$this->assertSame($reader->offset, $reader->tell());
		$reader->seek(15);
		$this->assertSame(15, $reader->tell());
		$this->assertSame($reader->offset, $reader->tell());
		$reader->seek(100);
		$this->assertSame(100, $reader->tell());
		$this->assertSame($reader->offset, $reader->tell());

		try {
			$reader->seek(-1);
		} catch (InvalidArgumentException $e) {}
		$this->assertSame(100, $reader->tell());
		try {
			$reader->seek($filesize + 1);
		} catch (InvalidArgumentException $e) {}
		$this->assertSame($filesize, $reader->tell());

		// Reading (with known size)
		$reader->seek(0);
		$data = $reader->read($filesize);
		$this->assertSame($filesize, strlen($data));
		$this->assertSame($filesize, $reader->tell());
		$this->assertSame($reader->offset, $reader->tell());

		try {
			$reader->read(1);
		} catch (InvalidArgumentException $e) {}
		$this->assertSame($filesize, $reader->tell());

		$reader->seek(0);
		$data = $reader->read(100);
		$this->assertSame(100, strlen($data));
		$this->assertSame(100, $reader->tell());
		$data = $reader->read(1);
		$this->assertSame(1, strlen($data));
		$this->assertSame(101, $reader->tell());

		try {
			$reader->read($filesize);
		} catch (InvalidArgumentException $e) {}
		$this->assertSame($filesize, $reader->tell());

		// Reading (with unknown size)
		$reader->seek(0);
		$data = '';
		while ($read = $reader->read(8192, false)) {
			$data .= $read;
		}
		$this->assertSame($filesize, strlen($data));
		$this->assertSame($filesize, $reader->tell());

		$reader->seek(0);
		$data = $reader->readAll();
		$this->assertSame($filesize, strlen($data));
		$this->assertSame($filesize, $reader->tell());
	}

	/**
	 * We also need to be able to read one line at a time, with the line ending
	 * included in the output.
	 *
	 * @depends testHandlesBasicSeekingAndReading
	 */
	public function testReadsSingleLines()
	{
		$file = realpath($this->fixturesDir.'/sfv/test001.sfv');
		$command = DIRECTORY_SEPARATOR === '\\'
			? 'type '.escapeshellarg($file).' 2>nul'
			: 'cat '.escapeshellarg($file);

		$reader = new TestPipeReader;
		$reader->open($command);
		$this->assertEmpty($reader->error);

		$line = $reader->readLine();
		$this->assertSame("; example comment\r\n", $line);
		while ($data = $reader->readLine()) {
			$line = $data;
		}
		$this->assertSame("testrar.rar 36fbdd27\r\n", $line);
		$this->assertFalse($data);
		$this->assertSame($reader->offset, $reader->tell());
	}

} // End PipeReaderTest

class TestPipeReader extends PipeReader
{
	// Made public for test convenience
	public $offset = 0;
}
