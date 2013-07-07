<?php

include_once dirname(__FILE__).'/../zipinfo.php';

/**
 * Test case for ZipInfo.
 *
 * @group  zip
 */
class ZipInfoTest extends PHPUnit_Framework_TestCase
{
	protected $fixturesDir;

	/**
	 * This method is called before each test is executed.
	 */
	protected function setUp()
	{
		$this->fixturesDir = realpath(dirname(__FILE__).'/fixtures/zip');
	}

	/**
	 * ZIP files consist of a series of records with headers and optional bodies.
	 * The main info is divided between Local File and Central File records. We
	 * should be able to report an accurate list of all records in summmary form.
	 *
	 * @dataProvider  providerTestFixtures
	 * @param  string  $filename  sample zip filename
	 * @param  string  $records   expected list of valid records
	 */
	public function testStoresListOfAllValidRecords($filename, $records)
	{
		$zip = new ZipInfo;
		$zip->open($filename, true);

		$this->assertEmpty($zip->error, $zip->error);
		$recordList = $zip->getRecords();
		$this->assertEquals(count($records), count($recordList));
		$this->assertEquals($records, $recordList);
	}

	/**
	 * Provides test data from sample files.
	 */
	public function providerTestFixtures()
	{
		$ds = DIRECTORY_SEPARATOR;
		$fixturesDir = realpath(dirname(__FILE__).'/fixtures/zip');
		$fixtures = array();

		foreach (glob($fixturesDir.$ds.'*.zip') as $rarfile) {
			$fname = pathinfo($rarfile, PATHINFO_BASENAME).'.records';
			$fpath = $fixturesDir.$ds.$fname;
			if (file_exists($fpath)) {
				$records = include $fpath;
				$fixtures[] = array('filename' => $rarfile, 'records' => $records);
			}
		}

		return $fixtures;
	}

	/**
	 * We should be able to report on the contents of the ZIP file, with some
	 * simple processing of the raw File blocks to make them human-readable.
	 */
	public function testListsAllArchiveFiles()
	{
		$zip = new ZipInfo;
		$zip->open($this->fixturesDir.'/pecl_test.zip');

		$files = $zip->getFileList();
		$this->assertCount(4, $files);
		$this->assertSame('bar', $files[0]['name']);
		$this->assertSame(0, $files[0]['pass']);
		$this->assertSame(0, $files[0]['compressed']);
		$this->assertArrayNotHasKey('is_dir', $files[0]);

		$this->assertSame('foobar/', $files[1]['name']);
		$this->assertSame(0, $files[1]['pass']);
		$this->assertSame(0, $files[1]['compressed']);
		$this->assertArrayHasKey('is_dir', $files[1]);

		$this->assertSame('foobar/baz', $files[2]['name']);
		$this->assertSame(0, $files[2]['pass']);
		$this->assertSame(1, $files[2]['compressed']);
		$this->assertArrayNotHasKey('is_dir', $files[2]);

		$this->assertSame('entry1.txt', $files[3]['name']);
		$this->assertSame(0, $files[3]['pass']);
		$this->assertSame(1, $files[3]['compressed']);
		$this->assertArrayNotHasKey('is_dir', $files[3]);

		// Encrypted files should be reported
		$zip->open($this->fixturesDir.'/encrypted_file.zip');
		$files = $zip->getFileList();
		$this->assertCount(1, $files);
		$this->assertSame('test_date.txt', $files[0]['name']);
		$this->assertSame(1, $files[0]['pass']);
		$this->assertSame(0, $files[0]['compressed']);
		$this->assertArrayNotHasKey('is_dir', $files[0]);
	}

	/**
	 * The End of Central Directory record keeps a count of files in the current
	 * volume, but if it's missing we should count the Local File records instead.
	 */
	public function testCountsLocalFileRecordsIfCentralDirectoryIsMissing()
	{
		$zip = new ZipInfo;

		// Missing CDR, but has Local File record:
		$zip->open($this->fixturesDir.'/large_file_start.zip');
		$summary = $zip->getSummary();
		$this->assertSame($zip->file, $summary['file_name']);
		$this->assertSame($zip->fileCount, $summary['file_count']);
		$this->assertSame(1, $summary['file_count']);

		// Missing Local File record, but has CDR:
		$zip->open($this->fixturesDir.'/large_file_end.zip');
		$summary = $zip->getSummary();
		$this->assertSame($zip->file, $summary['file_name']);
		$this->assertSame($zip->fileCount, $summary['file_count']);
		$this->assertSame(1, $summary['file_count']);
	}

	/**
	 * If the archive files aren't compressed, we should just be able to extract
	 * the file data and use it as is.
	 */
	public function testExtractsUncompressedFileData()
	{
		$zip = new ZipInfo;
		$zip->open($this->fixturesDir.'/little_file.zip');

		$files = $zip->getFileList();
		$this->assertCount(1, $files);
		$this->assertSame(0, $files[0]['compressed']);

		$data = $zip->getFileData($files[0]['name']);
		$this->assertSame($files[0]['size'], strlen($data));
		$this->assertSame("Some text.\n", $data);
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
	 * Decompression of archive contents should be possible by using an external
	 * client to read the current file, or temporary files for data sources. The
	 * test should be skipped if no external client is available.
	 *
	 * @group  external
	 */
	public function testDecompressesWithExternalClient()
	{
		if (!($unzip = $this->getUnzipPath())) {
			$this->markTestSkipped();
		}
		$zip = new ZipInfo;

		// From a file source
		$zipfile = $this->fixturesDir.'/pecl_test.zip';
		$zip->open($zipfile);
		$this->assertEmpty($zip->error);

		$files = $zip->getFileList();
		$file = $files[3];
		$this->assertSame('entry1.txt', $file['name']);
		$this->assertSame(1, $file['compressed']);

		$data = $zip->extractFile($file['name']);
		$this->assertNotEmpty($zip->error);
		$this->assertContains('external client', $zip->error);
		$this->assertFalse($data);

		$zip->setExternalClient($unzip);
		$data = $zip->extractFile($file['name']);
		$this->assertEmpty($zip->error,$zip->error);
		$this->assertSame($file['size'], strlen($data));
		$this->assertSame("entry #1", $data);

		// From a data source (via temp file)
		$zip->setData(file_get_contents($zipfile));
		$this->assertEmpty($zip->error);
		$summary = $zip->getSummary(true);
		$this->assertSame(filesize($zipfile), $summary['data_size']);
		$data = $zip->extractFile($file['name']);
		$this->assertEmpty($zip->error);
		$this->assertSame($file['size'], strlen($data));
	}

} // End ZipInfoTest
