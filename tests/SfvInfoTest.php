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
	 * SFV files can cover individual files and whole directory trees
	 * with simple CRC32 checksums, and can include comments. Parsing
	 * should handle spaces and different directory separators.
	 *
	 * @dataProvider  providerSfvFileRecords
	 * @param  string  $filename  sample sfv filename
	 * @param  string  $filelist  parsed file list
	 */
	public function testReturnsFilelistWithChecksums($filename, $filelist)
	{
		$source = $this->fixturesDir.DIRECTORY_SEPARATOR.$filename;
		$filecount = count($filelist);

		$sfv = new SfvInfo;
		$sfv->open($source);
		$this->assertSame($filecount, $sfv->fileCount);

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
		$summary = $sfv->getSummary(true);
		$this->assertSame($source, $summary['sfv_file']);
		$this->assertSame($list, $summary['file_list']);
		$this->assertSame($filecount, $summary['file_count']);
		$this->assertSame(filesize($source), $summary['file_size']);
		$this->assertNull($summary['data_size']);

		// The same results should be returned from passing data by reference
		$sfv = new SfvInfo;
		$data = file_get_contents($source);
		$sfv->setData($data);
		$this->assertSame($filecount, $sfv->fileCount);

		$summary = $sfv->getSummary(true);
		$this->assertNull($summary['sfv_file']);
		$this->assertSame($list, $summary['file_list']);
		$this->assertSame($filecount, $summary['file_count']);
		$this->assertNull($summary['file_size']);
		$this->assertSame(filesize($source), $summary['data_size']);
	}

	/**
	 * Provides test data from sample files.
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

} // End SfvInfoTest
