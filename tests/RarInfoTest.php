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
	public function testReturnsListOfValidBlocks($filename, $blocks)
	{
		$rar = new RarInfo;
		$rar->open($filename, true);

		$this->assertNull($rar->error, $rar->error);
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
	public function testReturnsListOfArchiveFiles()
	{
		$rar = new RarInfo;
		$rar->open($this->fixturesDir.'/multi.part1.rar');
		$files = $rar->getFileList();

		$this->assertCount(2, $files);
		$this->assertSame('file1.txt', $files[0]['name']);
		$this->assertSame(0, $files[0]['pass']);

		$this->assertSame('file2.txt', $files[1]['name']);
		$this->assertSame(0, $files[1]['pass']);
		$this->assertArrayHasKey('split', $files[1]);
	}

} // End RarInfoTest
