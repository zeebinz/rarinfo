<?php

include_once dirname(__FILE__).'/../rarinfo.php';

/**
 * Test case for RarInfo.
 *
 * @group  rar
 */
class RarInfoTest extends PHPUnit_Framework_TestCase
{
	protected $fixturesDir;

	/**
	 * This method is called before each test is executed.
	 */
	protected function setUp()
	{
		$this->fixturesDir = realpath(dirname(__FILE__).'/fixtures/rar');
	}

	/**
	 * RAR files consist of a series of header blocks and optional bodies for
	 * certain block types and subblocks. We should be abe to report an accurate
	 * list of all blocks in summmary form.
	 *
	 * @dataProvider  providerTestFixtures
	 * @param  string  $filename  sample rar filename
	 * @param  string  $blocks    expected list of valid blocks
	 */
	public function testStoresListOfAllValidBlocks($filename, $blocks)
	{
		$rar = new RarInfo;
		$rar->open($filename, true);

		$this->assertEmpty($rar->error, $rar->error);
		$blockList = $rar->getBlocks();
		$this->assertEquals(count($blocks), count($blockList));
		$this->assertEquals($blocks, $blockList);
	}

	/**
	 * Provides test data from sample files.
	 */
	public function providerTestFixtures()
	{
		$ds = DIRECTORY_SEPARATOR;
		$fixturesDir = realpath(dirname(__FILE__).'/fixtures/rar');
		$fixtures = array();

		foreach (glob($fixturesDir.$ds.'*.rar') as $rarfile) {
			$fname = pathinfo($rarfile, PATHINFO_BASENAME).'.blocks';
			$fpath = $fixturesDir.$ds.$fname;
			if (file_exists($fpath)) {
				$blocks = include $fpath;
				$fixtures[] = array('filename' => $rarfile, 'blocks' => $blocks);
			}
		}

		return $fixtures;
	}

	/**
	 * We should be able to report on the contents of the RAR file, with some
	 * simple processing of the raw File blocks to make them human-readable.
	 */
	public function testListsAllArchiveFiles()
	{
		$rar = new RarInfo;
		$rar->open($this->fixturesDir.'/multi.part1.rar');
		$files = $rar->getFileList();

		$this->assertCount(2, $files);
		$this->assertSame('file1.txt', $files[0]['name']);
		$this->assertSame(0, $files[0]['pass']);
		$this->assertSame(0, $files[0]['compressed']);
		$this->assertArrayNotHasKey('split', $files[0]);
		$this->assertArrayNotHasKey('is_dir', $files[0]);

		$this->assertSame('file2.txt', $files[1]['name']);
		$this->assertSame(0, $files[1]['pass']);
		$this->assertSame(1, $files[1]['compressed']);
		$this->assertArrayHasKey('split', $files[1]);
		$this->assertArrayNotHasKey('is_dir', $files[1]);
	}

	/**
	 * If the archive files are packed with the Store method, we should just be able
	 * to extract the file data and use it as is, since it isn't compressed.
	 */
	public function testExtractsFileDataPackedWithStoreMethod()
	{
		$rar = new RarInfo;
		$rarfile = $this->fixturesDir.'/store_method.rar';

		// With default byte range
		$rar->open($rarfile);
		$files = $rar->getFileList();
		$this->assertCount(1, $files);
		$this->assertSame(0, $files[0]['compressed']);
		$data = $rar->getFileData($files[0]['name']);
		$this->assertSame($files[0]['size'], strlen($data));
		$this->assertStringStartsWith('At each generation,', $data);
		$this->assertStringEndsWith('children, y, is', $data);

		// With range, all data available
		$rar->open($rarfile, true, array(1, filesize($rarfile) - 5));
		$files = $rar->getFileList();
		$data = $rar->getFileData($files[0]['name']);
		$this->assertSame($files[0]['size'], strlen($data));
		$this->assertStringStartsWith('At each generation,', $data);
		$this->assertStringEndsWith('children, y, is', $data);

		// With range, partial data available
		$rar->open($rarfile, true, array(1, filesize($rarfile) - 10));
		$files = $rar->getFileList();
		$data = $rar->getFileData($files[0]['name']);
		$this->assertSame($files[0]['size'] - 2, strlen($data));
		$this->assertStringStartsWith('At each generation,', $data);
		$this->assertStringEndsWith('children, y, ', $data);
	}

	/**
	 * Hooray for progress! The RAR 5.0 archive format is quite different from
	 * earlier versions, but initially we just want to be able to detect the
	 * the new format automatically and not break the basic public API.
	 */
	public function testBasicRar50Support()
	{
		$rar = new RarInfo;
		$rar->open($this->fixturesDir.'/rar50_encrypted_files.rar');

		// New archive format should be detected
		$this->assertSame(RarInfo::FMT_RAR50, $rar->format);
		$this->assertEmpty($rar->error);

		// File list output should be the same
		$this->assertSame(4, $rar->fileCount);
		$files = $rar->getFileList();
		$this->assertCount(4, $files);

		$this->assertSame('testdir/4mb.txt', $files[0]['name']);
		$this->assertSame(4194304, $files[0]['size']);
		$this->assertSame(0, $files[0]['pass']);
		$this->assertSame(0, $files[0]['compressed']);
		$this->assertEquals('1275178921', $files[0]['date']);
		$this->assertArrayNotHasKey('is_dir', $files[0]);

		$this->assertSame('testdir', $files[1]['name']);
		$this->assertSame(0, $files[1]['size']);
		$this->assertSame(0, $files[1]['pass']);
		$this->assertSame(0, $files[1]['compressed']);
		$this->assertEquals('1368906855', $files[1]['date']);
		$this->assertArrayHasKey('is_dir', $files[1]);

		$this->assertSame('testdir/bar.txt', $files[2]['name']);
		$this->assertSame(13, $files[2]['size']);
		$this->assertSame(1, $files[2]['pass']);
		$this->assertSame(1, $files[2]['compressed']);
		$this->assertEquals('1369170252', $files[2]['date']);
		$this->assertArrayNotHasKey('is_dir', $files[2]);

		$this->assertSame('foo.txt', $files[3]['name']);
		$this->assertSame(13, $files[3]['size']);
		$this->assertSame(0, $files[3]['pass']);
		$this->assertSame(0, $files[3]['compressed']);
		$this->assertEquals('1369170262', $files[3]['date']);
		$this->assertArrayNotHasKey('is_dir', $files[3]);

		$this->assertSame('foo test text', $rar->getFileData('foo.txt'));

		// Bonus! Archive comments are no longer compressed
		$this->assertSame("test archive comment\x00", $rar->comments);

		// Encrypted headers
		$this->assertFalse($rar->isEncrypted);
		$rar->open($this->fixturesDir.'/rar50_encrypted_headers.rar');
		$this->assertTrue($rar->isEncrypted);
		$this->assertSame(0, $rar->fileCount);
		$this->assertCount(1, $rar->getBlocks());
	}

	/**
	 * We should have an easy way to retrieve a list of cached file headers from
	 * a RAR 5.0 Quick Open block, if it exists.
	 */
	public function testRar50ListsQuickOpenCachedFiles()
	{
		$rar = new RarInfo;
		$rar->open($this->fixturesDir.'/rar50_quickopen.rar');
		$this->assertSame(RarInfo::FMT_RAR50, $rar->format);
		$this->assertEmpty($rar->error);

		$files = $rar->getQuickOpenFileList();
		$this->assertCount(4, $files);

		$this->assertSame('testdir/4mb.txt', $files[0]['name']);
		$this->assertSame(4194304, $files[0]['size']);
		$this->assertSame(0, $files[0]['compressed']);
		$this->assertArrayNotHasKey('range', $files[0]);

		$this->assertSame('testdir', $files[1]['name']);
		$this->assertArrayHasKey('is_dir', $files[1]);

		$this->assertSame('compressed.txt', $files[2]['name']);
		$this->assertSame(4194304, $files[2]['size']);
		$this->assertSame(1, $files[2]['compressed']);
		$this->assertArrayNotHasKey('range', $files[2]);

		$this->assertSame('bar.txt', $files[3]['name']);
		$this->assertSame(13, $files[3]['size']);
		$this->assertSame(1, $files[3]['pass']);
		$this->assertSame(1, $files[3]['compressed']);
		$this->assertArrayNotHasKey('range', $files[3]);
	}

	/**
	 * We want to bail as early as possible when handling archives with encrypted
	 * headers, as there's not much else we can do with them.
	 */
	public function testHandlesEncryptedArchivesGracefully()
	{
		$rar = new RarInfo;
		$rar->open($this->fixturesDir.'/encrypted_headers.rar');
		$this->assertTrue($rar->isEncrypted);
		$this->assertCount(2, $rar->getBlocks());

		$summary = $rar->getSummary(true);
		$this->assertSame(1, $summary['is_encrypted']);
		$this->assertSame(0, $summary['file_count']);
		$this->assertSame(0, $rar->fileCount);
		$this->assertEmpty($summary['file_list']);
		$this->assertCount(0, $rar->getFileList());
	}

	/**
	 * Provides the path to the external client executable, or false if it
	 * doesn't exist in the given directory.
	 *
	 * @return  string|boolean  the absolute path to the executable, or false
	 */
	protected function getUnrarPath()
	{
		$unrar = DIRECTORY_SEPARATOR === '\\'
			? dirname(__FILE__).'\bin\unrar\UnRAR.exe'
			: dirname(__FILE__).'/bin/unrar/unrar';

		if (file_exists($unrar))
			return $unrar;

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
		if (!($unrar = $this->getUnrarPath())) {
			$this->markTestSkipped();
		}
		$rar = new RarInfo;

		// From a file source
		$rarfile = $this->fixturesDir.'/solid.rar';
		$rar->open($rarfile);
		$this->assertEmpty($rar->error);

		$files = $rar->getFileList();
		$file = $files[1];
		$this->assertSame('unrardll.txt', $file['name']);
		$this->assertSame(1, $file['compressed']);

		$data = $rar->extractFile($file['name']);
		$this->assertNotEmpty($rar->error);
		$this->assertContains('external client', $rar->error);
		$this->assertFalse($data);

		$rar->setExternalClient($unrar);
		$data = $rar->extractFile($file['name']);
		$this->assertEmpty($rar->error);
		$this->assertSame($file['size'], strlen($data));
		$this->assertStringStartsWith("\r\n    UnRAR.dll Manual", $data);
		$this->assertStringEndsWith("to access this function.\r\n\r\n", $data);

		// From a data source (via temp file)
		$rar->setData(file_get_contents($rarfile));
		$this->assertEmpty($rar->error);
		$summary = $rar->getSummary(true);
		$this->assertSame(filesize($rarfile), $summary['data_size']);
		$data = $rar->extractFile($file['name']);
		$this->assertEmpty($rar->error);
		$this->assertSame($file['size'], strlen($data));
	}

} // End RarInfoTest
