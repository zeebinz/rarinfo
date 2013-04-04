<?php

include_once dirname(__FILE__).'/../srrinfo.php';

/**
 * Test case for SrrInfo.
 *
 * @group  srr
 */
class SrrInfoTest extends PHPUnit_Framework_TestCase
{
	protected $fixturesDir;

	/**
	 * This method is called before each test is executed.
	 */
	protected function setUp()
	{
		$this->fixturesDir = realpath(dirname(__FILE__).'/fixtures/srr');
	}

	/**
	 * SRR files include both specialised SRR blocks alongside RAR blocks that
	 * follow the RAR specification, apart from File blocks and certain Subblock
	 * types lacking bodies.
	 *
	 * @dataProvider  providerTestFixtures
	 * @param  string  $filename  sample srr filename
	 * @param  string  $blocks    expected list of valid blocks
	 */
	public function testStoresListOfAllValidBlocks($filename, $blocks)
	{
		$srr = new SrrInfo;
		$srr->open($filename);

		$this->assertEmpty($srr->error, $srr->error);
		$blockList = $srr->getBlocks();
		$this->assertEquals(count($blocks), count($blockList));
		$this->assertEquals($blocks, $blockList);
	}

	/**
	 * Provides test data from sample files.
	 */
	public function providerTestFixtures()
	{
		$ds = DIRECTORY_SEPARATOR;
		$fixturesDir = realpath(dirname(__FILE__).'/fixtures/srr');
		$fixtures = array();

		foreach (glob($fixturesDir.$ds.'*.srr') as $srrfile) {
			$fname = pathinfo($srrfile, PATHINFO_BASENAME).'.blocks';
			$fpath = $fixturesDir.$ds.$fname;
			if (file_exists($fpath)) {
				$blocks = include $fpath;
				$fixtures[] = array('filename' => $srrfile, 'blocks' => $blocks);
			}
		}

		return $fixtures;
	}

	/**
	 * SRR files can include their own Stored File blocks, and we should be able
	 * to extract their file contents.
	 */
	public function testExtractsStoredFiles()
	{
		$srr = new SrrInfo;
		$srr->open($this->fixturesDir.'/utf8_filename_added.srr');

		$stored = $srr->getStoredFiles();
		$this->assertCount(1, $stored);
		$this->assertSame(65, $stored[0]['size']);
		$this->assertSame('Κείμενο στην ελληνική γλώσσα.txt', $stored[0]['name']);
		$this->assertSame("Κείμενο στην ελληνική γλώσσα\nGreek text\n", $stored[0]['data']);
	}

	/**
	 * SRR files are mostly useful for providing a full list of the archive files
	 * that they cover, including details of the archive contents.
	 */
	public function testListsAllArchiveFiles()
	{
		$srr = new SrrInfo;
		$srr->open($this->fixturesDir.'/store_rr_solid_auth.part1.srr');

		$rars = $srr->getFileList();
		$this->assertCount(3, $rars);

		$this->assertSame('store_rr_solid_auth.part1.rar', $rars[0]['name']);
		$this->assertCount(3, $rars[0]['files']);
		$this->assertSame('empty_file.txt', $rars[0]['files'][0]['name']);
		$this->assertSame('little_file.txt', $rars[0]['files'][1]['name']);
		$this->assertSame('users_manual4.00.txt', $rars[0]['files'][2]['name']);

		$this->assertSame('store_rr_solid_auth.part2.rar', $rars[1]['name']);
		$this->assertCount(1, $rars[1]['files']);
		$this->assertSame('users_manual4.00.txt', $rars[1]['files'][0]['name']);

		$this->assertSame('store_rr_solid_auth.part3.rar', $rars[2]['name']);
		$this->assertCount(2, $rars[2]['files']);
		$this->assertSame('users_manual4.00.txt', $rars[2]['files'][0]['name']);
		$this->assertSame('Κείμενο στην ελληνική γλώσσα.txt', $rars[2]['files'][1]['name']);
	}

	/**
	 * We should be able to report on the client used to create the SRR file.
	 */
	public function testReportsClientInfo()
	{
		$srr = new SrrInfo;
		$srr->open($this->fixturesDir.'/store_rr_solid_auth.part1.srr');
		$this->assertSame('ReScene .NET 1.2', $srr->client);
	}

	/**
	 * SRR files should not contain any file data in the File blocks, but we
	 * should fail to read it gracefully.
	 */
	public function testFileDataCannotBeExtracted()
	{
		$srr = new SrrInfo;
		$srr->open($this->fixturesDir.'/store_rr_solid_auth.part1.srr');
		$this->assertFalse($srr->getFileData('users_manual4.00.txt'));
		foreach ($srr->getFileList() as $vol) {
			foreach ($vol['files'] as $file) {
				$this->assertArrayNotHasKey('range', $file);
			}
		}
	}

} // End SrrInfoTest
