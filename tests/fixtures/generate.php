<?php
/**
 * Script to generate test fixture files. This should be run before starting tests
 * and again after each update with the -r option to regenerate.
 *
 * Command options:
 *  -t <type>  The type of fixture to generate: rar, srr, par2, zip (default is all)
 *  -r         Regenerate existing fixture files (default is to create only missing ones)
 *  -p         Run in pretend mode, output debug info without making changes
 */
error_reporting(E_ALL | E_STRICT);
ini_set('display_startup_errors', 'on');
ini_set('display_errors', 'on');

$opts = getopt('prt:');
$pretend = isset($opts['p']) ? true : false;
$refresh = isset($opts['r']) ? true : false;

if ($pretend) {echo "*** Running in pretend mode ***\n";}
if (!isset($opts['t']) || $opts['t'] == 'rar')  makeRarFixtures($pretend, $refresh);
if (!isset($opts['t']) || $opts['t'] == 'srr')  makeSrrFixtures($pretend, $refresh);
if (!isset($opts['t']) || $opts['t'] == 'par2') makePar2Fixtures($pretend, $refresh);
if (!isset($opts['t']) || $opts['t'] == 'zip')  makeZipFixtures($pretend, $refresh);

/**
 * Generates test fixtures from RAR sample files.
 *
 * @param   boolean  $pretend  debug output only?
 * @param   boolean  $refresh  regenerate existing files?
 * @return  void
 */
function makeRarFixtures($pretend=false, $refresh=true)
{
	require_once dirname(__FILE__).'/../../rarinfo.php';
	$rar = new RarInfo;
	foreach(glob(dirname(__FILE__).'/rar/*.rar') as $rarfile) {
		$fname = pathinfo($rarfile, PATHINFO_BASENAME).'.blocks';
		$file  = dirname(__FILE__)."/rar/$fname";
		if (!$refresh && file_exists($file)) {continue;}
		echo "Generating for $rarfile:\n";
		$rar->open($rarfile, true);
		if ($rar->error) {
			echo "Error: {$rar->error}\n";
			continue;
		}
		$data = $rar->getBlocks();
		if (!$pretend) {
			$data = "<?php\nreturn ".var_export($data, true).";\n";
			file_put_contents($file, $data);
		}
		echo "-- $fname\n";
	}
}

/**
 * Generates test fixtures from SRR sample files.
 *
 * @param   boolean  $pretend  debug output only?
 * @param   boolean  $refresh  regenerate existing files?
 * @return  void
 */
function makeSrrFixtures($pretend=false, $refresh=true)
{
	require_once dirname(__FILE__).'/../../srrinfo.php';
	$srr = new SrrInfo;
	foreach(glob(dirname(__FILE__).'/srr/*.srr') as $srrfile) {
		$fname = pathinfo($srrfile, PATHINFO_BASENAME).'.blocks';
		$file  = dirname(__FILE__)."/srr/$fname";
		if (!$refresh && file_exists($file)) {continue;}
		echo "Generating for $srrfile:\n";
		$srr->open($srrfile);
		if ($srr->error) {
			echo "Error: {$srr->error}\n";
			continue;
		}
		$data = $srr->getBlocks();
		if (!$pretend) {
			$data = "<?php\nreturn ".var_export($data, true).";\n";
			file_put_contents($file, $data);
		}
		echo "-- $fname\n";
	}
}

/**
 * Generates test fixtures from PAR2 sample files.
 *
 * @param   boolean  $pretend  debug output only?
 * @param   boolean  $refresh  regenerate existing files?
 * @return  void
 */
function makePar2Fixtures($pretend=false, $refresh=true)
{
	require_once dirname(__FILE__).'/../../par2info.php';
	$par2 = new Par2Info;
	foreach(glob(dirname(__FILE__).'/par2/*.par2') as $par2file) {
		$fname = pathinfo($par2file, PATHINFO_BASENAME).'.packets';
		$file  = dirname(__FILE__)."/par2/$fname";
		if (!$refresh && file_exists($file)) {continue;}
		echo "Generating for $par2file:\n";
		$par2->open($par2file);
		if ($par2->error) {
			echo "Error: {$par2->error}\n";
			continue;
		}
		$data = $par2->getPackets(true);
		if (!$pretend) {
			$data = "<?php\nreturn ".var_export($data, true).";\n";
			file_put_contents($file, $data);
		}
		echo "-- $fname\n";
	}
}

/**
 * Generates test fixtures from ZIP sample files.
 *
 * @param   boolean  $pretend  debug output only?
 * @param   boolean  $refresh  regenerate existing files?
 * @return  void
 */
function makeZipFixtures($pretend=false, $refresh=true)
{
	require_once dirname(__FILE__).'/../../zipinfo.php';
	$zip = new ZipInfo;
	foreach(glob(dirname(__FILE__).'/zip/*.zip') as $zipfile) {
		$fname = pathinfo($zipfile, PATHINFO_BASENAME).'.records';
		$file  = dirname(__FILE__)."/zip/$fname";
		if (!$refresh && file_exists($file)) {continue;}
		echo "Generating for $zipfile:\n";
		$zip->open($zipfile);
		if ($zip->error) {
			echo "Error: {$zip->error}\n";
			continue;
		}
		$data = $zip->getRecords();
		if (!$pretend) {
			$data = "<?php\nreturn ".var_export($data, true).";\n";
			file_put_contents($file, $data);
		}
		echo "-- $fname\n";
	}
}
