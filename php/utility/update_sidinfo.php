<?php
/**
 * DeepSID / HVSC Update / Import SIDInfo
 *
 * Recursively scans the HVSC collection, runs SIDInfo.exe on every
 * SID file, cleans up its CSV output and imports the result directly
 * into the 'files_import' table.
 *
 * @used-by		LOCALHOST only
 */

require_once dirname(__DIR__).'/lib/class.account.php';

set_time_limit(0);
ignore_user_abort(true);

// -----------------------------------------------------------------------------
// Configuration
// -----------------------------------------------------------------------------

// Maximum number of SID files to process; 0 = all
const TEST_LIMIT = 0;

const HVSC_ROOT =
	'C:/Wamp/www/chordian/deepsid/music/_High Voltage SID Collection';

const SIDINFO_EXE =
	'C:/Wamp/www/chordian/deepsid/utility/sidinfo/sidinfo.exe';

// Number of SIDInfo processes allowed to run simultaneously
const MAX_WORKERS = 8;

// Commit database changes this often
const COMMIT_INTERVAL = 1000;

// Clear files_import before starting
const CLEAR_IMPORT_TABLE = true;

// -----------------------------------------------------------------------------
// Initial checks
// -----------------------------------------------------------------------------

if (!is_dir(HVSC_ROOT))
	die('HVSC folder not found: ' . HVSC_ROOT);

if (!is_file(SIDINFO_EXE))
	die('SIDInfo.exe not found: ' . SIDINFO_EXE);

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function output(string $text): void {

	if (PHP_SAPI === 'cli') {
		echo $text . PHP_EOL;
	} else {
		echo htmlspecialchars($text) . '<br>';
	}
}

/**
 * Convert the absolute Windows filename into the path stored by DeepSID.
 */
function getCollectionPath(string $filename): string {

	$filename = str_replace('\\', '/', $filename);
	$root = rtrim(str_replace('\\', '/', HVSC_ROOT), '/');

	$relative = substr($filename, strlen($root));

	return '_High Voltage SID Collection' . $relative;
}

/**
 * Collect output data from the SIDInfo.exe tool.
 */
function parseSidInfo(string $output): ?array {

	$map = [
		'Filename'				=> 'collection_path',
		'Type'					=> 'type',
		'Version'				=> 'version',
		'Player type'			=> 'player_type',
		'Player compatibility'	=> 'player_compat',
		'Video clock speed'		=> 'clock_speed',
		'SID model'				=> 'sid_model',
		'Data offset'			=> 'data_offset',
		'Data size'				=> 'data_size',
		'Load address'			=> 'load_addr',
		'Init address'			=> 'init_addr',
		'Play address'			=> 'play_addr',
		'Songs'					=> 'subtunes',
		'Start song'			=> 'start_subtune',
		'Name'					=> 'name',
		'Author'				=> 'author',
		'Copyright'				=> 'released',
		'Hash'					=> 'hash'
	];

	$data = [];

	foreach (preg_split('/\R/', trim($output)) as $line) {

		if (!preg_match('/^(.+?)\s*:\s*(.*)$/', $line, $match))
			continue;

		$label = trim($match[1]);
		$value = trim($match[2]);

		if (isset($map[$label]))
			$data[$map[$label]] = $value;
	}

	if (count($data) !== 18)
		return null;

	return [
		$data['collection_path'],
		$data['type'],
		$data['version'],
		$data['player_type'],
		$data['player_compat'],
		$data['clock_speed'],
		$data['sid_model'],
		$data['data_offset'],
		$data['data_size'],
		$data['load_addr'],
		$data['init_addr'],
		$data['play_addr'],
		$data['subtunes'],
		$data['start_subtune'],
		$data['name'],
		$data['author'],
		$data['released'],
		$data['hash']
	];
}

/**
 * Start one SIDInfo process.
 */
function startSidInfo(string $filename): array {

	$collectionPath = getCollectionPath($filename);

	/*
	 * SIDInfo used to be run from the directory immediately above
	 * "_High Voltage SID Collection", so retain that arrangement.
	 */
	$workingDirectory = dirname(HVSC_ROOT);

	$command = [
		SIDINFO_EXE,
		$collectionPath
	];

	$descriptorSpec = [
		0 => ['pipe', 'r'],
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w']
	];

	$process = proc_open(
		$command,
		$descriptorSpec,
		$pipes,
		$workingDirectory,
		null,
		['bypass_shell' => true]
	);

	if (!is_resource($process))
		throw new RuntimeException('Could not start SIDInfo for: ' . $collectionPath);

	fclose($pipes[0]);

	stream_set_blocking($pipes[1], false);
	stream_set_blocking($pipes[2], false);

	return [
		'process'	=> $process,
		'pipes'		=> $pipes,
		'path'		=> $collectionPath,
		'stdout'	=> '',
		'stderr'	=> ''
	];
}

// -----------------------------------------------------------------------------
// Prepare database
// -----------------------------------------------------------------------------

$db = $account->getDB();

if (CLEAR_IMPORT_TABLE) {
	$db->exec('TRUNCATE TABLE files_import');
	output('Cleared files_import.');
}

$sql = '
	INSERT INTO files_import (
		collection_path,
		type,
		version,
		player_type,
		player_compat,
		clock_speed,
		sid_model,
		data_offset,
		data_size,
		load_addr,
		init_addr,
		play_addr,
		subtunes,
		start_subtune,
		name,
		author,
		released,
		hash
	) VALUES (
		?,
		?,
		?,
		?,
		?,
		?,
		?,
		?,
		?,
		?,
		?,
		?,
		?,
		?,
		?,
		?,
		?,
		?
	)
';

$insert = $db->prepare($sql);

// -----------------------------------------------------------------------------
// Find SID files
// -----------------------------------------------------------------------------

output('');
output('Scanning HVSC...');

$files = [];

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator(
		HVSC_ROOT,
		FilesystemIterator::SKIP_DOTS
	)
);

foreach ($iterator as $file) {

	if (!$file->isFile())
		continue;

	if (strtolower($file->getExtension()) !== 'sid')
		continue;

	$files[] = $file->getPathname();
}

sort($files, SORT_NATURAL | SORT_FLAG_CASE);

if (TEST_LIMIT > 0)
	$files = array_slice($files, 0, TEST_LIMIT);

$total = count($files);

output('Found ' . number_format($total) . ' SID files.');
output('');
output('Running up to ' . MAX_WORKERS . ' SIDInfo processes simultaneously...');
output('');

// -----------------------------------------------------------------------------
// Process files
// -----------------------------------------------------------------------------

$running	= [];
$nextFile	= 0;
$completed	= 0;
$inserted	= 0;
$errors		= 0;

$db->beginTransaction();

while ($nextFile < $total || $running) {

	/*
	 * Fill all available worker slots.
	 */
	while (
		count($running) < MAX_WORKERS &&
		$nextFile < $total
	) {
		$running[] = startSidInfo($files[$nextFile]);

		$nextFile++;
	}

	/*
	 * Check running SIDInfo processes.
	 */
	foreach ($running as $key => &$job) {

		$job['stdout'] .= stream_get_contents($job['pipes'][1]);
		$job['stderr'] .= stream_get_contents($job['pipes'][2]);

		$status = proc_get_status($job['process']);

		if ($status['running'])
			continue;

		/*
		 * Grab anything left in the pipes before closing them.
		 */
		$job['stdout'] .= stream_get_contents($job['pipes'][1]);
		$job['stderr'] .= stream_get_contents($job['pipes'][2]);

		fclose($job['pipes'][1]);
		fclose($job['pipes'][2]);

		proc_close($job['process']);

		$completed++;

		$fields = parseSidInfo($job['stdout']);		

		if ($fields === null) {
			$errors++;

			output(
				'ERROR [' . $completed . '] ' .
				$job['path']
			);
		} else {

			/*
			 * Do not depend on whatever path spelling SIDInfo returns.
			 * We already know exactly which DeepSID collection path
			 * belongs to this file.
			 */
			$fields[0] = $job['path'];

			$insert->execute($fields);
			$inserted++;
			
			output(
				str_pad($completed, 5, ' ', STR_PAD_LEFT).': '.
				$job['path']
			);
		}

		/*
		 * Commit periodically instead of keeping one enormous
		 * transaction open for the complete HVSC collection.
		 */
		if ($inserted > 0 && $inserted % COMMIT_INTERVAL === 0) {

			$db->commit();
			$db->beginTransaction();
		}

		unset($running[$key]);
	}

	unset($job);

	/*
	 * Re-index because completed workers have been removed.
	 */
	$running = array_values($running);

	/*
	 * Avoid hammering the CPU with the PHP polling loop itself.
	 * SIDInfo processes continue running during this sleep.
	 */
	if ($running)
		usleep(10000);
}

// -----------------------------------------------------------------------------
// Finish
// -----------------------------------------------------------------------------

if ($db->inTransaction())
	$db->commit();

output('');
output('Finished.');
output('SID files processed: ' . number_format($completed));
output('Rows inserted: ' . number_format($inserted));
output('Errors: ' . number_format($errors));
?>