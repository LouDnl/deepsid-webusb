<?php
/**
 * DeepSID / Import HVSC song lengths
 *
 * Imports song lengths from HVSC's 'DOCUMENTS/Songlengths.md5'
 * into the temporary 'files_import' table.
 *
 * The path comment preceding each MD5 entry is used as collection_path,
 * while everything after '=' is inserted unchanged into lengths.
 */

if (PHP_SAPI === 'cli') {
	$_SERVER['HTTP_HOST'] = 'localhost';
}

require_once dirname(__DIR__).'/lib/class.account.php';

const HVSC_FULL_PATH = __DIR__.'/../../music/_High Voltage SID Collection';
const SONGLENGTHS_FILE = HVSC_FULL_PATH.'/DOCUMENTS/Songlengths.md5';
const COLLECTION_PREFIX = '_High Voltage SID Collection';

if (!file_exists(SONGLENGTHS_FILE)) {
	die("Song lengths file not found:\n".SONGLENGTHS_FILE."\n");
}

$db = $account->getDB();

// Start with an empty import table
$db->query("TRUNCATE TABLE files_import");

$insert = $db->prepare("
	INSERT INTO files_import (collection_path, lengths)
	VALUES (:collection_path, :lengths)
");

$file = fopen(SONGLENGTHS_FILE, 'r');

if (!$file) {
	die("Could not open:\n".SONGLENGTHS_FILE."\n");
}

$current_path = '';
$imported = 0;
$skipped = 0;

while (($line = fgets($file)) !== false) {

	$line = trim($line);

	// We're only interested in SID path comments
	if (!str_starts_with($line, ';')) {
		continue;
	}

	$path = trim(substr($line, 1));

	if (!str_ends_with(strtolower($path), '.sid')) {
		continue;
	}

	// Find the next non-empty line, which should contain MD5=lengths
	do {
		$hash_line = fgets($file);

		if ($hash_line === false) {
			break 2;
		}

		$hash_line = trim($hash_line);

	} while ($hash_line === '');

	$equals_pos = strpos($hash_line, '=');

	if ($equals_pos === false) {
		echo "INVALID: ".$path."<br>";
		continue;
	}

	$collection_path = COLLECTION_PREFIX.$path;
	$lengths = substr($hash_line, $equals_pos + 1);
	
	// Only print progress for every 1000th line
	if ($imported > 0 && $imported % 1000 === 0) {
		echo str_pad($imported, 5, ' ', STR_PAD_LEFT).': '.
			htmlspecialchars($collection_path).','.
			htmlspecialchars($lengths).'<br>';
	}

	try {
		$insert->execute([
			':collection_path' => $collection_path,
			':lengths' => $lengths,
		]);
	} catch (PDOException $e) {
		die('Database error: '.$e->getMessage());
	}
		$imported++;	
	}

fclose($file);

echo "\nSong lengths import complete.\n";
echo "Imported: ".$imported."\n";

if ($skipped) {
	echo "Skipped:  ".$skipped."\n";
}
?>