<?php
/**
 * Script to generate test fixture files, should be run before starting tests
 * and again after each update.
 */
error_reporting(E_ALL);
ini_set('display_startup_errors', 'on');
ini_set('display_errors', 'on');

makeRarFixtures();
makeSrrFixtures();

/**
 * Generates test fixtures from RAR sample files.
 *
 * @param   boolean  $pretend  debug output only?
 * @return  void
 */
function makeRarFixtures($pretend=false)
{
	require_once dirname(__FILE__).'/../../rarinfo.php';
	$rar = new RarInfo;
	foreach(glob(dirname(__FILE__).'/rar/*.rar') as $rarfile) {
		echo "Generating for $rarfile:\n";
		$rar->open($rarfile, true);
		if ($rar->error) {
			echo "Error: {$rar->error}\n";
			continue;
		}
		$fname = pathinfo($rarfile, PATHINFO_BASENAME).'.blocks';
		$data = $rar->getBlocks();
		if (!$pretend) {
			$data = "<?php\nreturn ".var_export($data, true).";\n";
			file_put_contents(dirname(__FILE__)."/rar/$fname", $data);
		}
		echo "-- $fname\n";
	}
}

/**
 * Generates test fixtures from SRR sample files.
 *
 * @param   boolean  $pretend  debug output only?
 * @return  void
 */
function makeSrrFixtures($pretend=false)
{
	require_once dirname(__FILE__).'/../../srrinfo.php';
	$srr = new SrrInfo;
	foreach(glob(dirname(__FILE__).'/srr/*.srr') as $srrfile) {
		echo "Generating for $srrfile:\n";
		$srr->open($srrfile);
		if ($srr->error) {
			echo "Error: {$srr->error}\n";
			continue;
		}
		$fname = pathinfo($srrfile, PATHINFO_BASENAME).'.blocks';
		$data = $srr->getBlocks();
		if (!$pretend) {
			$data = "<?php\nreturn ".var_export($data, true).";\n";
			file_put_contents(dirname(__FILE__)."/srr/$fname", $data);
		}
		echo "-- $fname\n";
	}
}