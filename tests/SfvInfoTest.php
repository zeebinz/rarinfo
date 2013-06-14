<?php

include_once dirname(__FILE__).'/../sfvinfo.php';

/**
 * Test case for SfvInfo.
 *
 * @group  sfv
 */
class SfvInfoTest extends PHPUnit_Framework_TestCase
{
	protected $fixturesDir;

	/**
	 * This method is called before each test is executed.
	 */
	protected function setUp()
	{
		$this->fixturesDir = realpath(dirname(__FILE__).'/fixtures/sfv');
	}

	/**
	 * SFV files can cover individual files and whole directory trees with simple
	 * CRC32 checksums, and can include comments. Parsing should handle spaces and
	 * different directory separators, and all line ending types.
	 *
	 * @dataProvider  providerSfvFileRecords
	 * @param  string  $filename  sample sfv filename
	 * @param  string  $filelist  parsed file list
	 */
	public function testListsAllFilesWithChecksums($filename, $filelist)
	{
		$source = $this->fixturesDir.DIRECTORY_SEPARATOR.$filename;
		$filecount = count($filelist);

		$sfv = new SfvInfo;
		$sfv->open($source);
		$this->assertEmpty($sfv->error, $sfv->error);
		$this->assertSame($filecount, $sfv->fileCount);

		// Comments should be stored
		$this->assertNotEmpty($sfv->comments);

		// With full file paths, including dirs
		$list = $sfv->getFileList();
		for ($i = 0; $i < $filecount; $i++)
		{
			$this->assertSame($filelist[$i][0], $list[$i]['name']);
			$this->assertSame($filelist[$i][1], $list[$i]['checksum']);
		}

		// With filenames only, ignoring dirs
		$list = $sfv->getFileList(true);
		for ($i = 0; $i < $filecount; $i++)
		{
			$this->assertSame($filelist[$i][2], $list[$i]['name']);
			$this->assertSame($filelist[$i][1], $list[$i]['checksum']);
		}

		// Summary should return the same list with source info
		$summary = $sfv->getSummary(true, true);
		$this->assertSame($source, $summary['file_name']);
		$this->assertSame($list, $summary['file_list']);
		$this->assertSame($filecount, $summary['file_count']);
		$this->assertSame(filesize($source), $summary['file_size']);
		$this->assertSame('0-'.($summary['file_size'] - 1), $summary['use_range']);
		$this->assertEmpty($summary['data_size']);

		// The same results should be returned when data is set directly
		$sfv = new SfvInfo;
		$data = file_get_contents($source);
		$sfv->setData($data);
		$this->assertSame($filecount, $sfv->fileCount);

		$summary = $sfv->getSummary(true, true);
		$this->assertEmpty($summary['file_name']);
		$this->assertSame($list, $summary['file_list']);
		$this->assertSame($filecount, $summary['file_count']);
		$this->assertEmpty($summary['file_size']);
		$this->assertSame(filesize($source), $summary['data_size']);
		$this->assertSame('0-'.($summary['data_size'] - 1), $summary['use_range']);
	}

	/**
	 * Provides test data for comparison with sample files.
	 */
	public function providerSfvFileRecords()
	{
		return array(
			array('test001.sfv', array(
				array('testrar.r00', 'f6d8c75f', 'testrar.r00'),
				array('testrar.r01', '1e9ba708', 'testrar.r01'),
				array('testrar.r02', 'fb171746', 'testrar.r02'),
				array('testrar.r03', '1ddbb63a', 'testrar.r03'),
				array('testrar.rar', '36fbdd27', 'testrar.rar'))
			),
			array('test002.sfv', array(
				array('test 1.txt', 'f6d8c75f', 'test 1.txt'),
				array('subdir\test_2.txt', '1e9ba708', 'test_2.txt'),
				array('subdir\test 3.txt', 'fb171746', 'test 3.txt'),
				array('subdir/test 4.txt', '1ddbb63a', 'test 4.txt'),
				array('subdir1/subdir 2/test 5.txt', '36fbdd27', 'test 5.txt'))
			),
		);
	}

	/**
	 * We should be able to verify simply that any passed data or file only
	 * contains valid SFV info.
	 */
	public function testNonSfvDataShouldReturnError()
	{
		$sfv = new SfvInfo;
		$sfv->setData(";could be a comment\r\ninvalid sfv daT4GHIJ\r\n");
		$this->assertSame('Not a valid SFV file', $sfv->error);

		// RAR
		$source = $this->fixturesDir.'/../rar/4mb.rar';
		$sfv = new SfvInfo;
		$sfv->open($source);
		$this->assertSame('Not a valid SFV file', $sfv->error);

		// PAR2
		$source = $this->fixturesDir.'/../par2/testdata.par2';
		$sfv = new SfvInfo;
		$sfv->open($source);
		$this->assertSame('Not a valid SFV file', $sfv->error);

		// ZIP (contains readable uncompressed SFV file)
		$source = $this->fixturesDir.'/test002.zip';
		$sfv = new SfvInfo;
		$sfv->open($source);
		$this->assertSame('Not a valid SFV file', $sfv->error);
	}

	/**
	 * All line ending types, including mixed types, should be supported.
	 *
	 * @dataProvider  providerSfvMixedLineEndings
	 * @param  string  $data  sample sfv data
	 */
	public function testSupportsAllLineEndingTypes($data)
	{
		$sfv = new SfvInfo;
		$sfv->setData($data);
		$this->assertEmpty($sfv->error, $sfv->error);
		$this->assertSame(2, $sfv->fileCount);

		$this->assertSame("example comment\n", $sfv->comments);
		$this->assertEquals(array(
			array(
				'name' => 'testrar.r00',
				'checksum' => 'f6d8c75f'
			),
			array(
				'name' => 'testrar.r01',
				'checksum' => '1e9ba708'
			),
		), $sfv->getFileList());
	}

	/**
	 * Provides test data with different line ending types.
	 */
	public function providerSfvMixedLineEndings()
	{
		return array(
			// Unix
			array("; example comment\ntestrar.r00 f6d8c75f\ntestrar.r01 1e9ba708\n"),
			// Windows
			array("; example comment\r\ntestrar.r00 f6d8c75f\r\ntestrar.r01 1e9ba708\r\n"),
			// Mac
			array("; example comment\rtestrar.r00 f6d8c75f\rtestrar.r01 1e9ba708\r"),
			// Mixed
			array("; example comment\ntestrar.r00 f6d8c75f\r\ntestrar.r01 1e9ba708\r"),
		);
	}

	/**
	 * We should be able to access any file comments simply, stripped of ; and padding.
	 */
	public function testStoresFileComments()
	{
		$source = $this->fixturesDir.'/test002.sfv';
		$sfv = new SfvInfo;
		$sfv->open($source);

		$comments = "filenames with spaces\nfiles in subdirectories\n";
		$this->assertSame($comments, $sfv->comments);
	}

} // End SfvInfoTest
