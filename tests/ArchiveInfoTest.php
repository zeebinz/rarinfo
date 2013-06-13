<?php

include_once dirname(__FILE__).'/../archiveinfo.php';

/**
 * Test case for ArchiveInfo.
 *
 * @group  ainfo
 */
class ArchiveInfoTest extends PHPUnit_Framework_TestCase
{
	protected $fixturesDir;

	/**
	 * This method is called before each test is executed.
	 */
	protected function setUp()
	{
		$this->fixturesDir = realpath(dirname(__FILE__).'/fixtures');
	}

	/**
	 * The first job of this class is to decide whether we're dealing with one of
	 * the supported archive types, and if so create a reader instance to handle
	 * the rest of the work via delegation.
	 */
	public function testAutomaticallyDetectsSupportedArchiveTypes()
	{
		$archive = new ArchiveInfo;

		// RAR
		$archive->open($this->fixturesDir.'/rar/4mb.rar');
		$this->assertEmpty($archive->error);
		$this->assertSame(ArchiveInfo::TYPE_RAR, $archive->type);
		$this->assertInstanceOf('RarInfo', $archive->getReader());
		$this->assertSame(1, $archive->fileCount);

		// SRR
		$archive->open($this->fixturesDir.'/srr/added_empty_file.srr');
		$this->assertEmpty($archive->error);
		$this->assertSame(ArchiveInfo::TYPE_SRR, $archive->type);
		$this->assertInstanceOf('SrrInfo', $archive->getReader());
		$this->assertSame(1, $archive->fileCount);
		$this->assertCount(1, $archive->getStoredFiles());

		// PAR2
		$archive->open($this->fixturesDir.'/par2/testdata.par2');
		$this->assertEmpty($archive->error);
		$this->assertSame(ArchiveInfo::TYPE_PAR2, $archive->type);
		$this->assertInstanceOf('Par2Info', $archive->getReader());
		$this->assertSame(10, $archive->fileCount);
		$this->assertSame(0, $archive->blockCount);
		$this->assertEquals(5376, $archive->blockSize);

		// ZIP
		$archive->open($this->fixturesDir.'/zip/little_file.zip');
		$this->assertEmpty($archive->error);
		$this->assertSame(ArchiveInfo::TYPE_ZIP, $archive->type);
		$this->assertInstanceOf('ZipInfo', $archive->getReader());
		$this->assertSame(1, $archive->fileCount);

		// SFV
		$archive->open($this->fixturesDir.'/sfv/test001.sfv');
		$this->assertEmpty($archive->error);
		$this->assertSame(ArchiveInfo::TYPE_SFV, $archive->type);
		$this->assertInstanceOf('SfvInfo', $archive->getReader());
		$this->assertSame(5, $archive->fileCount);
		$this->assertNotEmpty($archive->comments);

		// Unsupported
		$archive->open($this->fixturesDir.'/misc/foo.txt');
		$this->assertNotEmpty($archive->error);
		$this->assertContains('not a supported archive type', $archive->error);
		$this->assertSame(ArchiveInfo::TYPE_NONE, $archive->type);
		$this->assertEmpty($archive->getReader());
	}

	/**
	 * The next main responsibility of this class is to handle parsing of any
	 * supported archive types that have been embedded in others. We'll start
	 * here by testing two basic samples by chaining archive calls.
	 *
	 * @depends  testAutomaticallyDetectsSupportedArchiveTypes
	 */
	public function testHandlesEmbeddedArchiveTypes()
	{
		$archive = new ArchiveInfo;

		// ZIP within RAR
		$archive->open($this->fixturesDir.'/misc/zip_in_rar.rar');
		$this->assertEmpty($archive->error);
		$this->assertSame(ArchiveInfo::TYPE_RAR, $archive->type);
		$this->assertSame(1, $archive->fileCount);
		$files = $archive->getFileList();
		$this->assertCount(1, $files);
		$this->assertSame('little_file.zip', $files[0]['name']);
		$this->assertTrue($archive->allowsRecursion());
		$this->assertTrue($archive->containsArchive());

		$zip = $archive->getArchive($files[0]['name']);
		$this->assertSame(ArchiveInfo::TYPE_ZIP, $zip->type);
		$this->assertSame(1, $zip->fileCount);
		$files = $zip->getFileList();
		$this->assertCount(1, $files);
		$this->assertSame('little_file.txt', $files[0]['name']);
		$this->assertSame(11, $files[0]['size']);
		$this->assertSame(0, $files[0]['compressed']);
		$text = $zip->getFileData($files[0]['name']);
		$this->assertSame($files[0]['size'], strlen($text));
		$this->assertContains('Some text', $text);
		unset($zip);

		// RAR within ZIP
		$archive->open($this->fixturesDir.'/misc/rar_in_zip.zip');
		$this->assertEmpty($archive->error);
		$this->assertSame(ArchiveInfo::TYPE_ZIP, $archive->type);
		$this->assertSame(1, $archive->fileCount);
		$files = $archive->getFileList();
		$this->assertCount(1, $files);
		$this->assertSame('commented.rar', $files[0]['name']);
		$this->assertTrue($archive->allowsRecursion());
		$this->assertTrue($archive->containsArchive());

		$rar = $archive->getArchive($files[0]['name']);
		$this->assertSame(ArchiveInfo::TYPE_RAR, $rar->type);
		$this->assertSame(1, $rar->fileCount);
		$files = $rar->getFileList();
		$this->assertCount(1, $files);
		$this->assertSame('file.txt', $files[0]['name']);
		$this->assertSame(12, $files[0]['size']);
		$this->assertSame(0, $files[0]['compressed']);
		$text = $rar->getFileData($files[0]['name']);
		$this->assertSame($files[0]['size'], strlen($text));
		$this->assertContains('file content', $text);
	}

	/**
	 * We also want to be able to list the contents of all embedded archive files
	 * recursively, by either chaining or in a flat list. These samples should
	 * contain all supported archive types in different containers.
	 *
	 * @depends  testHandlesEmbeddedArchiveTypes
	 * @dataProvider  providerSampleFiles
	 * @param  string  $file  the sample filename
	 * @param  string  $type  the sample type
	 */
	public function testListsAllEmbeddedArchiveFilesRecursively($file, $type)
	{
		$archive = new ArchiveInfo;
		$archive->open($this->fixturesDir.$file);
		$this->assertEmpty($archive->error);
		$this->assertSame($type, $archive->type);
		$this->assertSame(8, $archive->fileCount);
		$this->assertTrue($archive->allowsRecursion());

		// List only the archives, as summaries
		$files = $archive->getArchiveList(true);
		$this->assertCount(7, $files);
		foreach ($files as $name => $summary) {

			// Each archive should specify its reader type
			$this->assertArrayHasKey('main_info', $summary);
			$this->assertArrayHasKey('main_type', $summary);
			$child = $archive->getArchive($name);
			$this->assertInstanceOf($summary['main_info'], $child->getReader());
			$this->assertSame($summary['main_type'], $child->type);

			// Any embedded archives should be listed recursively
			$this->assertNotEmpty($summary['file_list']);
			if ($child->containsArchive()) {
				$this->assertTrue($child->allowsRecursion());
				$this->assertArrayHasKey('archives', $summary);
				$this->assertNotEmpty($summary['archives']);
			} else {
				$this->assertArrayNotHasKey('archives', $summary);
			}
			unset($child);
		}

		// List all archive files recursively in a flat list
		$files = $archive->getArchiveFileList(true);
		$this->assertCount(14, $files);
		usort($files, function($a, $b) {
			return strcasecmp($a['name'].': '.$a['source'], $b['name'].': '.$b['source']);
		});

		// Only the main files should include a next_offset value
		foreach ($files as $file) {
			if ($file['source'] != ArchiveInfo::MAIN_SOURCE) {
				$this->assertArrayNotHasKey('next_offset', $file);
			}
		}

		// File packed in RAR in ZIP:
		$this->assertSame('commented.rar', $files[1]['name']);
		$this->assertSame('main > rar_in_zip.zip', $files[1]['source']);
		$this->assertSame('file.txt', $files[3]['name']);
		$this->assertSame('main > rar_in_zip.zip > commented.rar', $files[3]['source']);
		$text = $archive->getFileData($files[3]['name'], $files[3]['source']);
		$this->assertContains('file content', $text);

		// File packed in ZIP in RAR:
		$this->assertSame('little_file.zip', $files[8]['name']);
		$this->assertSame('main > zip_in_rar.rar', $files[8]['source']);
		$this->assertSame('little_file.txt', $files[6]['name']);
		$this->assertSame('main > zip_in_rar.rar > little_file.zip', $files[6]['source']);
		$text = $archive->getFileData($files[6]['name'], $files[6]['source']);
		$this->assertContains('Some text', $text);
	}

	/**
	 * We should also be able to produce a flat list that combines all embedded
	 * archive file lists, even if they don't support recursion, so we can easily
	 * inspect all known file names ... if not much else.
	 *
	 * @depends  testListsAllEmbeddedArchiveFilesRecursively
	 * @dataProvider  providerSampleFiles
	 * @param  string  $file  the sample filename
	 * @param  string  $type  the sample type
	 */
	public function testCanMergeAllEmbeddedArchiveFileLists($file, $type)
	{
		$archive = new ArchiveInfo;
		$archive->open($this->fixturesDir.$file);
		$this->assertEmpty($archive->error);

		// Merge all available file lists in one flat list
		$files = $archive->getArchiveFileList(true, true);
		$this->assertCount(31, $files);
		usort($files, function($a, $b) {
			return strcasecmp($a['name'].': '.$a['source'], $b['name'].': '.$b['source']);
		});

		// SRR file list item
		$this->assertSame('store_little.srr', $files[12]['name']);
		$this->assertSame('main', $files[12]['source']);
		$this->assertFalse($archive->getArchive($files[12]['name'])->allowsRecursion());
		$this->assertSame('store_little.rar', $files[11]['name']);
		$this->assertSame('main > store_little.srr', $files[11]['source']);
		$this->assertArrayHasKey('files', $files[11]);
		$this->assertCount(1, $files[11]['files']);
		$this->assertSame('little_file.txt', $files[6]['name']);
		$this->assertSame('main > store_little.srr > store_little.rar', $files[6]['source']);
		$this->assertArrayNotHasKey('range', $files[6]);

		// PAR2 file list item
		$this->assertSame('testdata.par2', $files[24]['name']);
		$this->assertSame('main', $files[24]['source']);
		$this->assertFalse($archive->getArchive($files[24]['name'])->allowsRecursion());
		$this->assertSame('test-0.data', $files[13]['name']);
		$this->assertSame('main > testdata.par2', $files[13]['source']);
		$this->assertArrayHasKey('hash', $files[13]);
		$this->assertArrayHasKey('blocks', $files[13]);

		// SFV file list item
		$this->assertSame('test001.sfv', $files[23]['name']);
		$this->assertSame('main', $files[23]['source']);
		$this->assertFalse($archive->getArchive($files[23]['name'])->allowsRecursion());
		$this->assertSame('testrar.r00', $files[25]['name']);
		$this->assertSame('main > test001.sfv', $files[25]['source']);
		$this->assertArrayHasKey('checksum', $files[25]);
	}

	/**
	 * Provides info for sample files containing all supported archive types.
	 */
	public function providerSampleFiles()
	{
		return array(
			array('/misc/misc_in_rar.rar', ArchiveInfo::TYPE_RAR),
			array('/misc/misc_in_zip.zip', ArchiveInfo::TYPE_ZIP),
		);
	}

	/**
	 * This class should work identically to RarInfo, except we should also be able
	 * to handle any embedded RAR archives, either as chainable objects or within
	 * flat file lists. The enhanced summary output should display the full nested
	 * tree of archive contents.
	 *
	 * @depends  testHandlesEmbeddedArchiveTypes
	 */
	public function testListsAllRarFilesRecursively()
	{
		$rar = new ArchiveInfo;
		$rar->open($this->fixturesDir.'/rar/embedded_rars.rar');

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
		$this->assertSame($rar->file, $file['file_name']);
		$this->assertSame(7893, $file['file_size']);
		$this->assertSame('7420-7878', $file['use_range']);
		$this->assertContains('archive is compressed', $file['error']);
		unset($summary);

		// Method chaining
		$rar2 = $rar->getArchive('embedded_1_rar.rar');
		$this->assertInstanceof('ArchiveInfo', $rar2);
		$files = $rar2->getArchive('store_method.rar')->getFileList();
		$this->assertCount(1, $files);
		$this->assertSame('tese.txt', $files[0]['name']);
		unset($rar2);

		// Archive list as objects or summaries
		$archives = $rar->getArchiveList();
		$this->assertCount(3, $archives);
		$this->assertArrayHasKey('embedded_1_rar.rar', $archives);
		$this->assertInstanceof('ArchiveInfo', $archives['embedded_1_rar.rar']);
		$archives = $rar->getArchiveList(true);
		$this->assertNotInstanceof('ArchiveInfo', $archives['embedded_1_rar.rar']);
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
	 * If the RAR files are packed with the Store method, we should just be able
	 * to extract the file data and use it as is, and we should be able to retrieve
	 * the contents of embedded files via chaining or by specifying the source.
	 *
	 * @depends  testListsAllRarFilesRecursively
	 */
	public function testExtractsEmbeddedRarFileDataPackedWithStoreMethod()
	{
		$rar = new ArchiveInfo;
		$rar->open($this->fixturesDir.'/rar/embedded_rars.rar');
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
		$rar->setData(file_get_contents($this->fixturesDir.'/rar/embedded_rars.rar'));
		$files = $rar->getArchiveFileList(true);
		$this->assertArrayHasKey(12, $files);
		$file = $files[12];
		$this->assertSame('7228-7239', $file['range']);

		$this->assertSame($content, $rar->getArchive('commented.rar')->getFileData('file.txt'));
		$this->assertSame($content, $rar->getFileData('file.txt', $file['source']));
		$this->assertSame($content, $rar->getFileData('file.txt', 'commented.rar'));

		// And with a more deeply embedded file
		$content = 'contents of file 1';
		$rar->open($this->fixturesDir.'/rar/embedded_rars.rar');
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

		$rar->setData(file_get_contents($this->fixturesDir.'/rar/embedded_rars.rar'));
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

	/**
	 * We should be able to handle embedded RAR 5.0 format archives without fuss.
	 *
	 * @depends  testListsAllRarFilesRecursively
	 */
	public function testHandlesEmbeddedRar50Archives()
	{
		$rar = new ArchiveInfo;

		// RAR 5.0 format archive within RAR 5.0 archive
		$rar->open($this->fixturesDir.'/rar/rar50_embedded_rar.rar');
		$this->assertSame(RarInfo::FMT_RAR50, $rar->format);
		$this->assertEmpty($rar->error);

		$files = $rar->getArchiveFileList(true);
		$this->assertCount(6, $files);
		$file = $files[1];
		$this->assertSame('encrypted_files.rar', $file['name']);
		$this->assertSame('main', $file['source']);
		$this->assertSame(0, $file['compressed']);
		$this->assertSame(0, $file['pass']);
		$this->assertSame('593-4195342', $file['range']);
		$file = $files[4];
		$this->assertSame('testdir/bar.txt', $file['name']);
		$this->assertSame('main > encrypted_files.rar', $file['source']);
		$this->assertSame(1, $file['compressed']);
		$this->assertSame(1, $file['pass']);
		$this->assertSame('4195152-4195183', $file['range']);
		$file = $files[5];
		$this->assertSame('foo.txt', $file['name']);
		$this->assertSame('main > encrypted_files.rar', $file['source']);
		$this->assertSame(0, $file['compressed']);
		$this->assertSame(0, $file['pass']);
		$this->assertSame('4195240-4195252', $file['range']);

		$content = 'foo test text';
		$this->assertSame(RarInfo::FMT_RAR50, $rar->getArchive('encrypted_files.rar')->format);
		$this->assertSame($content, $rar->getArchive('encrypted_files.rar')->getFileData('foo.txt'));
		$this->assertSame($content, $rar->getFileData('foo.txt', $file['source']));
		$this->assertSame($content, $rar->getFileData('foo.txt', 'encrypted_files.rar'));

		$files = $rar->getArchive('encrypted_files.rar')->getQuickOpenFileList();
		$this->assertCount(1, $files);
		$this->assertSame('testdir/4mb.txt', $files[0]['name']);
		$this->assertArrayNotHasKey('range', $files[0]);

		// RAR 1.5 - 4.x format archive within RAR 5.0 archive
		$rar->open($this->fixturesDir.'/rar/rar50_embedded_rar15.rar');
		$this->assertSame(RarInfo::FMT_RAR50, $rar->format);
		$this->assertEmpty($rar->error);

		$files = $rar->getArchiveFileList(true);
		$this->assertCount(4, $files);
		$file = $files[1];
		$this->assertSame('encrypted_only_files.rar', $file['name']);
		$this->assertSame(RarInfo::FMT_RAR15, $rar->getArchive($file['name'])->format);
		$file = $files[2];
		$this->assertSame('encfile1.txt', $file['name']);
		$this->assertSame('main > encrypted_only_files.rar', $file['source']);
		$this->assertSame(1, $file['compressed']);
		$this->assertSame(1, $file['pass']);
		$this->assertSame('4194509-4194556', $file['range']);

		// RAR 5.0 format archive within 1.5 - 4.x archive
		$rar->open($this->fixturesDir.'/rar/embedded_rar50.rar');
		$this->assertSame(RarInfo::FMT_RAR15, $rar->format);
		$this->assertEmpty($rar->error);

		$files = $rar->getArchiveFileList(true);
		$this->assertCount(5, $files);
		$file = $files[0];
		$this->assertSame('rar50_encrypted_files.rar', $file['name']);
		$this->assertSame(RarInfo::FMT_RAR50, $rar->getArchive($file['name'])->format);
		$file = $files[3];
		$this->assertSame('testdir/bar.txt', $file['name']);
		$this->assertSame('main > rar50_encrypted_files.rar', $file['source']);
		$this->assertSame(1, $file['compressed']);
		$this->assertSame(1, $file['pass']);
		$this->assertSame('4194641-4194672', $file['range']);
	}

} // End ArchiveInfoTest
