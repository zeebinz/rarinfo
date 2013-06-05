<?php

include_once dirname(__FILE__).'/../rrarinfo.php';

/**
 * Test case for RecursiveRarInfo.
 *
 * @group  rrar
 */
class RecursiveRarInfoTest extends PHPUnit_Framework_TestCase
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
	 * This class should work identically to RarInfo, except we should also be able
	 * to handle any embedded RAR archives, either as chainable objects or within
	 * flat file lists. The enhanced summary output should display the full nested
	 * tree of archive contents.
	 */
	public function testListsAllArchiveFilesRecursively()
	{
		$rar = new RecursiveRarInfo;
		$rar->open($this->fixturesDir.'/embedded_rars.rar');

		// Vanilla file list
		$files = $rar->getFileList();
		$this->assertCount(4, $files);
		$this->assertSame('embedded_1_rar.rar', $files[0]['name']);
		$this->assertSame('commented.rar', $files[1]['name']);
		$this->assertSame('somefile.txt', $files[2]['name']);
		$this->assertSame('compressed_rar.rar', $files[3]['name']);

		// Enhanced summary
		$this->assertTrue($rar->containsArchive());
		$summary = $rar->getSummary(true);
		$this->assertArrayHasKey('archives', $summary);
		$this->assertCount(3, $summary['archives']);
		$this->assertTrue(isset($summary['archives']['compressed_rar.rar']['archives']['4mb.rar']));
		$file = $summary['archives']['compressed_rar.rar']['archives']['4mb.rar'];
		$this->assertSame($rar->file, $file['rar_file']);
		$this->assertSame($summary['file_size'], $file['file_size']);
		$this->assertSame('7420-7878', $file['use_range']);
		$this->assertContains('archive is compressed', $file['error']);
		unset($summary);

		// Method chaining
		$rar2 = $rar->getArchive('embedded_1_rar.rar');
		$this->assertInstanceof('RecursiveRarInfo', $rar2);
		$files = $rar2->getArchive('store_method.rar')->getFileList();
		$this->assertCount(1, $files);
		$this->assertSame('tese.txt', $files[0]['name']);
		unset($rar2);

		// Archive list as objects or summaries
		$archives = $rar->getArchiveList();
		$this->assertCount(3, $archives);
		$this->assertArrayHasKey('embedded_1_rar.rar', $archives);
		$this->assertInstanceof('RecursiveRarInfo', $archives['embedded_1_rar.rar']);
		$archives = $rar->getArchiveList(true);
		$this->assertNotInstanceof('RecursiveRarInfo', $archives['embedded_1_rar.rar']);
		$this->assertArrayHasKey('archives', $archives['embedded_1_rar.rar']);
		unset($archives);

		// Flat archive file list, recursive
		$files = $rar->getArchiveFileList(true);
		$this->assertCount(15, $files);

		$this->assertSame('embedded_1_rar.rar', $files[0]['name']);
		$this->assertArrayNotHasKey('error', $files[0]);
		$this->assertArrayHasKey('source', $files[0]);
		$this->assertSame('main', $files[0]['source']);

		$this->assertSame('somefile.txt', $files[2]['name']);
		$this->assertSame('main', $files[2]['source']);

		$source = 'main > embedded_1_rar.rar > embedded_2_rar.rar > multi.part1.rar';
		$this->assertSame('file2.txt', $files[11]['name']);
		$this->assertSame($source, $files[11]['source']);

		$source = 'main > compressed_rar.rar';
		$this->assertSame('4mb.rar', $files[13]['name']);
		$this->assertSame($source, $files[13]['source']);

		// Errors should also be appended
		$source = 'main > compressed_rar.rar > 4mb.rar';
		$this->assertSame($source, $files[14]['source']);
		$this->assertArrayHasKey('error', $files[14]);
		$this->assertContains('archive is compressed', $files[14]['error']);
	}

	/**
	 * If the archive files are packed with the Store method, we should just be able
	 * to extract the file data and use it as is, and we should be able to retrieve
	 * the contents of embedded files via chaining or by specifying the source.
	 *
	 * @depends testListsAllArchiveFilesRecursively
	 */
	public function testExtractsEmbeddedFileDataPackedWithStoreMethod()
	{
		$rar = new RecursiveRarInfo;
		$rar->open($this->fixturesDir.'/embedded_rars.rar');
		$content = 'file content';

		$files = $rar->getArchiveFileList(true);
		$this->assertArrayHasKey(12, $files);
		$file = $files[12];
		$this->assertSame('file.txt', $file['name']);
		$this->assertSame('main > commented.rar', $file['source']);
		$this->assertSame(0, $file['compressed']);
		$this->assertSame('7228-7239', $file['range']);

		// Via chaining
		$this->assertSame($content, $rar->getArchive('commented.rar')->getFileData('file.txt'));

		// Via filename & source
		$this->assertSame($content, $rar->getFileData('file.txt', $file['source']));
		$this->assertSame($content, $rar->getFileData('file.txt', 'commented.rar'));

		// Using setData should produce the same results
		$rar->setData(file_get_contents($this->fixturesDir.'/embedded_rars.rar'));
		$files = $rar->getArchiveFileList(true);
		$this->assertArrayHasKey(12, $files);
		$file = $files[12];
		$this->assertSame('7228-7239', $file['range']);

		$this->assertSame($content, $rar->getArchive('commented.rar')->getFileData('file.txt'));
		$this->assertSame($content, $rar->getFileData('file.txt', $file['source']));
		$this->assertSame($content, $rar->getFileData('file.txt', 'commented.rar'));

		// And with a more deeply embedded file
		$content = 'contents of file 1';
		$rar->open($this->fixturesDir.'/embedded_rars.rar');
		$files = $rar->getArchiveFileList(true);
		$file = $files[10];
		$this->assertSame('file1.txt', $file['name']);
		$this->assertSame('main > embedded_1_rar.rar > embedded_2_rar.rar > multi.part1.rar', $file['source']);
		$this->assertSame(0, $file['compressed']);
		$this->assertSame('2089-2106', $file['range']);
		$this->assertSame($content, $rar->getFileData('file1.txt', $file['source']));
		$this->assertSame($content, $rar->getArchive('embedded_1_rar.rar')
			->getFileData('file1.txt', 'main > embedded_2_rar.rar > multi.part1.rar'));
		$this->assertSame($content, $rar->getArchive('embedded_1_rar.rar')
			->getArchive('embedded_2_rar.rar')
			->getFileData('file1.txt', 'main > multi.part1.rar'));
		$this->assertSame($content, $rar->getArchive('embedded_1_rar.rar')
			->getArchive('embedded_2_rar.rar')
			->getArchive('multi.part1.rar')
			->getFileData('file1.txt'));

		$rar->setData(file_get_contents($this->fixturesDir.'/embedded_rars.rar'));
		$files = $rar->getArchiveFileList(true);
		$file = $files[10];
		$this->assertSame('file1.txt', $file['name']);
		$this->assertSame('main > embedded_1_rar.rar > embedded_2_rar.rar > multi.part1.rar', $file['source']);
		$this->assertSame(0, $file['compressed']);
		$this->assertSame('2089-2106', $file['range']);
		$this->assertSame($content, $rar->getFileData('file1.txt', $file['source']));
		$this->assertSame($content, $rar->getArchive('embedded_1_rar.rar')
			->getFileData('file1.txt', 'main > embedded_2_rar.rar > multi.part1.rar'));
		$this->assertSame($content, $rar->getArchive('embedded_1_rar.rar')
			->getArchive('embedded_2_rar.rar')
			->getFileData('file1.txt', 'main > multi.part1.rar'));
		$this->assertSame($content, $rar->getArchive('embedded_1_rar.rar')
			->getArchive('embedded_2_rar.rar')
			->getArchive('multi.part1.rar')
			->getFileData('file1.txt'));
	}

} // End RecursiveRarInfoTest
