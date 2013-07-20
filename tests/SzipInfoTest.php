<?php

include_once dirname(__FILE__).'/../szipinfo.php';

/**
 * Test case for SzipInfo.
 *
 * @group  szip
 */
class SzipInfoTest extends PHPUnit_Framework_TestCase
{
	protected $fixturesDir;

	/**
	 * This method is called before each test is executed.
	 */
	protected function setUp()
	{
		$this->fixturesDir = realpath(dirname(__FILE__).'/fixtures/szip');
	}

	/**
	 * 7z files consist of headers at the archive start and end, with the blocks
	 * of packed data streams between. We should be abe to report an accurate list
	 * of all headers in summmary form.
	 *
	 * @dataProvider  providerTestFixtures
	 * @param  string  $filename  sample 7z filename
	 * @param  string  $headers   expected list of valid headers
	 */
	public function testStoresListOfAllValidHeaders($filename, $headers)
	{
		$szip = new SzipInfo;
		$szip->open($filename, true);

		$this->assertEmpty($szip->error, $szip->error);
		$headerList = $szip->getHeaders(true);
		$this->assertEquals(count($headers), count($headerList));
		$this->assertEquals($headers, $headerList);
	}

	/**
	 * Provides test data from sample files.
	 */
	public function providerTestFixtures()
	{
		$ds = DIRECTORY_SEPARATOR;
		$fixturesDir = realpath(dirname(__FILE__).'/fixtures/szip');
		$fixtures = array();

		foreach (glob($fixturesDir.$ds.'*.{7z,001,002}', GLOB_BRACE) as $szipfile) {
			$fname = pathinfo($szipfile, PATHINFO_BASENAME).'.headers';
			$fpath = $fixturesDir.$ds.$fname;
			if (file_exists($fpath)) {
				$headers = include $fpath;
				$fixtures[] = array('filename' => $szipfile, 'headers' => $headers);
			}
		}

		return $fixtures;
	}

	/**
	 * We should be able to report on the contents of the 7z archive, with some
	 * simple processing of the raw headers to make them human-readable. It's
	 * especially helpful to know if we're dealing with files stored in solid
	 * archives and/or compressed substreams.
	 */
	public function testListsAllArchiveFiles()
	{
		$szip = new SzipInfo;

		// Without substreams
		$szip->open($this->fixturesDir.'/store_method.7z');
		$this->assertEmpty($szip->error);
		$this->assertFalse($szip->isSolid);
		$this->assertSame(2, $szip->blockCount);
		$files = $szip->getFileList();
		$this->assertCount(2, $files);

		$file = $files[0];
		$this->assertSame('7zFormat.txt', $file['name']);
		$this->assertSame(7573, $file['size']);
		$this->assertSame(1284641836, $file['date']);
		$this->assertSame(0, $file['pass']);
		$this->assertSame(0, $file['compressed']);
		$this->assertSame(0, $file['block']);
		$this->assertSame('32-7604', $file['range']);
		$this->assertSame('3f8ccf66', $file['crc32']);
		$this->assertArrayNotHasKey('is_dir', $file);

		$file = $files[1];
		$this->assertSame('foo.txt', $file['name']);
		$this->assertSame(15, $file['size']);
		$this->assertSame(1373228890, $file['date']);
		$this->assertSame(0, $file['pass']);
		$this->assertSame(0, $file['compressed']);
		$this->assertSame(1, $file['block']);
		$this->assertSame('7605-7619', $file['range']);
		$this->assertSame('1a92d0b1', $file['crc32']);
		$this->assertArrayNotHasKey('is_dir', $file);

		// With compressed substreams
		$szip->open($this->fixturesDir.'/solid_lzma_multi.7z');
		$this->assertEmpty($szip->error);
		$this->assertTrue($szip->isSolid);
		$this->assertSame(1, $szip->blockCount);
		$files = $szip->getFileList();
		$this->assertCount(3, $files);

		$file = $files[0];
		$this->assertSame('7zFormat.txt', $file['name']);
		$this->assertSame(7573, $file['size']);
		$this->assertSame(1, $file['compressed']);
		$this->assertSame(0, $file['block']);
		$this->assertSame('32-2087', $file['range']);
		$this->assertSame('3f8ccf66', $file['crc32']);

		$file = $files[1];
		$this->assertSame('bar.txt', $file['name']);
		$this->assertSame(15, $file['size']);
		$this->assertSame(1, $file['compressed']);
		$this->assertSame(0, $file['block']);
		$this->assertSame('32-2087', $file['range']);
		$this->assertSame('71afb453', $file['crc32']);

		$file = $files[2];
		$this->assertSame('foo.txt', $file['name']);
		$this->assertSame(15, $file['size']);
		$this->assertSame(1, $file['compressed']);
		$this->assertSame(0, $file['block']);
		$this->assertSame('32-2087', $file['range']);
		$this->assertSame('1a92d0b1', $file['crc32']);

		// With directories/empty streams
		$szip->open($this->fixturesDir.'/store_with_empty.7z');
		$this->assertEmpty($szip->error);
		$this->assertSame(2, $szip->blockCount);
		$files = $szip->getFileList();
		$this->assertCount(4, $files);

		$file = $files[0];
		$this->assertSame('testdir3/7zFormat.txt', $file['name']);
		$this->assertSame(7573, $file['size']);
		$this->assertSame(0, $file['block']);

		$file = $files[1];
		$this->assertSame('testdir3/foo.txt', $file['name']);
		$this->assertSame(15, $file['size']);
		$this->assertSame(1, $file['block']);

		$file = $files[2];
		$this->assertSame('testdir3/empty.txt', $file['name']);
		$this->assertSame(0, $file['size']);
		$this->assertArrayNotHasKey('block', $file);
		$this->assertArrayNotHasKey('range', $file);
		$this->assertArrayNotHasKey('crc32', $file);
		$this->assertArrayNotHasKey('is_dir', $file);

		$file = $files[3];
		$this->assertSame('testdir3', $file['name']);
		$this->assertSame(0, $file['size']);
		$this->assertSame(1, $file['is_dir']);
		$this->assertArrayNotHasKey('block', $file);
		$this->assertArrayNotHasKey('crc32', $file);
		$this->assertArrayNotHasKey('range', $file);
	}

	/**
	 * If the archive files aren't compressed, we should just be able to extract
	 * the file data and use it as is.
	 */
	public function testExtractsUncompressedFileData()
	{
		$szip = new SzipInfo;

		// With all data available
		$szip->open($this->fixturesDir.'/store_method.7z');
		$this->assertEmpty($szip->error);
		$files = $szip->getFileList();
		$this->assertCount(2, $files);

		$this->assertSame('7zFormat.txt', $files[0]['name']);
		$this->assertSame(0, $files[0]['compressed']);
		$data = $szip->getFileData($files[0]['name']);
		$this->assertSame($files[0]['size'], strlen($data));
		$this->assertSame($files[0]['crc32'], dechex(crc32($data)));
		$this->assertStringStartsWith('7z Format description', $data);
		$this->assertStringEndsWith("End of document\r\n", $data);

		$this->assertSame('foo.txt', $files[1]['name']);
		$this->assertSame(0, $files[1]['compressed']);
		$data = $szip->getFileData($files[1]['name']);
		$this->assertSame($files[1]['size'], strlen($data));
		$this->assertSame($files[1]['crc32'], dechex(crc32($data)));
		$this->assertSame('sample foo text', $data);

		// With partial data available
		$szip->open($this->fixturesDir.'/multi_volume.7z.002', true);
		$this->assertEmpty($szip->error);
		$files = $szip->getFileList();
		$this->assertCount(2, $files);
		$this->assertSame('7zFormat.txt', $files[0]['name']);
		$this->assertSame(0, $files[0]['compressed']);
		$data = $szip->getFileData($files[0]['name']);
		$this->assertNotSame($files[0]['size'], strlen($data));
		$this->assertStringStartsWith('odecIdSize', $data);
		$this->assertStringEndsWith("End of document\r\n", $data);
	}

	/**
	 * Provides the path to the external client executable, or false if it
	 * doesn't exist in the given directory.
	 *
	 * @return  string|boolean  the absolute path to the executable, or false
	 */
	protected function getUnzipPath()
	{
		$unzip = DIRECTORY_SEPARATOR === '\\'
			? dirname(__FILE__).'\bin\7z\7za.exe'
			: dirname(__FILE__).'/bin/7z/7za';

		if (file_exists($unzip))
			return $unzip;

		return false;
	}

	/**
	 * Decompression and/or decryption of archive contents should be possible by
	 * using an external client to read the current file, or temporary files for
	 * data sources. The test should be skipped if no external client is available.
	 *
	 * @group  external
	 */
	public function testExtractsFilesWithExternalClient()
	{
		if (!($unzip = $this->getUnzipPath())) {
			$this->markTestSkipped();
		}
		$szip = new SzipInfo;

		// Compressed files
		$szfile = $this->fixturesDir.'/solid_lzma_multi.7z';
		$szip->open($szfile);
		$this->assertEmpty($szip->error);
		$files = $szip->getFileList();

		$file = $files[0];
		$this->assertSame('7zFormat.txt', $file['name']);
		$this->assertSame(1, $file['compressed']);
		$data = $szip->extractFile($file['name']);
		$this->assertNotEmpty($szip->error);
		$this->assertContains('external client', $szip->error);

		$szip->setExternalClient($unzip);

		$data = $szip->extractFile($file['name']);
		$this->assertEmpty($szip->error);
		$this->assertSame(strlen($data), $file['size']);
		$this->assertSame($file['crc32'], dechex(crc32($data)));
		$this->assertStringStartsWith('7z Format description', $data);
		$this->assertStringEndsWith("End of document\r\n", $data);

		// Encrypted files
		$szfile = $this->fixturesDir.'/multi_substreams_encrypted.7z';
		$szip->open($szfile);
		$this->assertEmpty($szip->error);
		$files = $szip->getFileList();

		$file = $files[2];
		$this->assertSame('7zFormat.txt', $file['name']);
		$this->assertSame(1, $file['pass']);
		$data = $szip->extractFile($file['name']);
		$this->assertNotEmpty($szip->error);
		$this->assertContains('passworded', $szip->error);

		$data = $szip->extractFile($file['name'], null, 'password');
		$this->assertEmpty($szip->error, $szip->error);
		$this->assertSame(strlen($data), $file['size']);
		$this->assertSame($file['crc32'], dechex(crc32($data)));
		$this->assertStringStartsWith('7z Format description', $data);
		$this->assertStringEndsWith("End of document\r\n", $data);

		// From a data source (via temp file)
		$szip->setData(file_get_contents($szfile));
		$this->assertEmpty($szip->error);
		$summary = $szip->getSummary(true);
		$this->assertSame(filesize($szfile), $summary['data_size']);
		$data = $szip->extractFile($file['name'], null, 'password');
		$this->assertEmpty($szip->error);
		$this->assertSame($file['size'], strlen($data));
		$this->assertSame($file['crc32'], dechex(crc32($data)));
	}

	/**
	 * Headers can be compressed or encrypted, and we should be able to use an
	 * external client to extract these before further parsing.
	 *
	 * @depends testExtractsFilesWithExternalClient
	 * @group   external
	 */
	public function testExtractsEncodedHeadersWithExternalClient()
	{
		if (!($unzip = $this->getUnzipPath())) {
			$this->markTestSkipped();
		}
		$szip = new SzipInfo;

		// Compressed headers
		$file = $this->fixturesDir.'/store_method_enc_headers.7z';
		$szip->open($file);
		$this->assertEmpty($szip->error);
		$this->assertSame(1, $szip->blockCount);
		$this->assertEmpty($szip->getFileList());

		$szip->setExternalClient($unzip);

		$szip->open($file);
		$this->assertEmpty($szip->error);
		$this->assertSame(2, $szip->blockCount);
		$files = $szip->getFileList();
		$this->assertCount(2, $files);

		$file = $files[0];
		$this->assertSame('7zFormat.txt', $file['name']);
		$this->assertSame('32-7604', $file['range']);
		$file = $files[1];
		$this->assertSame('foo.txt', $file['name']);
		$this->assertSame('7605-7619', $file['range']);

		// Encrypted headers
		$file = $this->fixturesDir.'/encrypted_headers.7z';
		$szip->open($file);
		$this->assertNotEmpty($szip->error);
		$this->assertContains('password needed', $szip->error);
		$this->assertTrue($szip->isEncrypted);
		$this->assertSame(1, $szip->blockCount);
		$this->assertEmpty($szip->getFileList());

		$szip->setPassword('password');

		$szip->open($file);
		$this->assertEmpty($szip->error);
		$this->assertSame(1, $szip->blockCount);
		$files = $szip->getFilelist();
		$this->assertCount(1, $files);

		$file = $files[0];
		$this->assertSame('7zFormat.txt', $file['name']);
		$this->assertSame(7573, $file['size']);
		$this->assertSame('32-7615', $file['range']);
		$this->assertSame(1, $file['pass']);
		$this->assertSame(0, $file['compressed']);
	}

} // End SzipInfoTest
