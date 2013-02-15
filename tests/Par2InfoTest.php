<?php

include_once dirname(__FILE__).'/../par2info.php';

/**
 * Test case for Par2Info.
 *
 * @group  par2
 */
class Par2InfoTest extends PHPUnit_Framework_TestCase
{
	protected $fixturesDir;

	/**
	 * This method is called before each test is executed.
	 */
	protected function setUp()
	{
		$this->fixturesDir = realpath(dirname(__FILE__).'/fixtures/par2');
	}

	/**
	 * PAR2 files consist of self-contained packets with their own checksums, of
	 * various types. Redundancy means that details of the recovery set are repeated
	 * across multiple files, and within files. Packets should only be added to the
	 * list if they pass the internal checksum test.
	 *
	 * @dataProvider  providerTestFixtures
	 * @param  string  $filename  sample par2 filename
	 * @param  string  $packets   expected list of valid packets
	 */
	public function testStoresListOfAllValidPackets($filename, $packets)
	{
		$par2 = new Par2Info;
		$par2->open($filename);

		$this->assertEmpty($par2->error, $par2->error);
		$packetList = $par2->getPackets(true);
		$this->assertEquals(count($packets), count($packetList));
		$this->assertEquals($packets, $packetList);
	}

	/**
	 * Provides test data from sample files.
	 */
	public function providerTestFixtures()
	{
		$ds = DIRECTORY_SEPARATOR;
		$fixturesDir = realpath(dirname(__FILE__).'/fixtures/par2');
		$fixtures = array();

		foreach (glob($fixturesDir.$ds.'*.par2') as $par2file) {
			$fname = pathinfo($par2file, PATHINFO_BASENAME).'.packets';
			$fpath = $fixturesDir.$ds.$fname;
			if (file_exists($fpath)) {
				$packets = include $fpath;
				$fixtures[] = array('filename' => $par2file, 'packets' => $packets);
			}
		}

		return $fixtures;
	}

	/**
	 * PAR2 files can be inspected to provide a full list of the files included
	 * in their recovery set, with hashes for checking file integrity. Because
	 * of redundancy, the same File Description packets can be repeated within a
	 * single file, so we need to ignore duplicates.
	 */
	public function testListsAllRecoverySetFilesWithHashes()
	{
		$par2 = new Par2Info;
		$par2->open($this->fixturesDir.'/testdata.vol01+02.par2');
		$this->assertEmpty($par2->error, $par2->error);

		$files = $par2->getFileList();
		$this->assertEquals(2, $par2->blockCount);
		$this->assertEquals(10, $par2->fileCount);
		$this->assertCount(10, $files);

		$this->assertArrayHasKey('4631d494bc34ae0b3131291eeb3238f6', $files);
		$this->assertEquals($files['4631d494bc34ae0b3131291eeb3238f6'], array(
			'name' => 'test-3.data',
			'size' => 142129,
			'hash' => '9e44a776f7a1fac4a3569bf734bacb01',
			'hash_16K' => 'bd1a58ae2f233491c450623596c322eb',
			'blocks' => 27,
			'next_offset' => 5576
		));

		// Check the hash of the whole file contents
		$data = file_get_contents($this->fixturesDir.'/test-3.data');
		$this->assertSame($files['4631d494bc34ae0b3131291eeb3238f6']['hash'], md5($data));

		// Check the hash of the first 16KB of the file
		$data = substr($data, 0, 16384);
		$this->assertSame($files['4631d494bc34ae0b3131291eeb3238f6']['hash_16K'], md5($data));
	}

	/**
	 * We should be able to report on the client used to create the PAR2 file.
	 */
	public function testReportsClientInfo()
	{
		$par2 = new Par2Info;
		$par2->open($this->fixturesDir.'/testdata.par2');
		$this->assertSame('Created by QuickPar 0.4', $par2->client);
	}

} // End Par2InfoTest
