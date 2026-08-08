<?php
/**
 * DeepSID / Import HVSC STIL
 *
 * Reads DOCUMENTS/STIL.txt from the installed HVSC collection and
 * imports all SID-specific STIL entries into files_import.
 *
 * Line breaks inside each STIL entry are converted to <br />.
 */

require_once dirname(__DIR__).'/class.account.php'; // php/class.account.php

const HVSC_FULL_PATH	= __DIR__.'/../../music/_High Voltage SID Collection';
const COLLECTION_NAME	= '_High Voltage SID Collection';

// Clear files_import before starting
const CLEAR_IMPORT_TABLE = true;

$stil_file = HVSC_FULL_PATH.'/DOCUMENTS/STIL.txt';

if (!is_file($stil_file))
	exit('STIL.txt not found: '.$stil_file);

$handle = fopen($stil_file, 'r');

if (!$handle)
	exit('Could not open STIL.txt');

$db = $account->getDB();

if (CLEAR_IMPORT_TABLE) {
	$db->exec('TRUNCATE TABLE files_import');
	echo "Cleared files_import.\n\n";
}

$insert = $db->prepare('
	INSERT INTO files_import (
		collection_path,
		stil
	) VALUES (
		:collection_path,
		:stil
	)
	ON DUPLICATE KEY UPDATE
		stil = VALUES(stil)
');

$fullname	= null;
$stil_lines	= [];
$count		= 0;

/**
 * Store the currently collected STIL block
 */
function saveStilBlock($insert, &$fullname, &$stil_lines, &$count): void
{
	if ($fullname === null)
		return;

	$stil = implode('<br />', $stil_lines);

	$insert->execute([
		':collection_path'	=> COLLECTION_NAME.'/'.$fullname,
		':stil'				=> $stil
	]);

	echo COLLECTION_NAME.'/'.$fullname.','.$stil."\n\n";

	$count++;
	$fullname	= null;
	$stil_lines	= [];
}

/**
 * Make sure the text has the proper UTF-8 encoding.
 */
function toUtf8(string $text): string
{
	if (mb_check_encoding($text, 'UTF-8'))
		return $text;

	return mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
}

while (($line = fgets($handle)) !== false) {

	// Remove only the physical line ending
	$line = toUtf8(rtrim($line, "\r\n"));

	// A line beginning with "/" starts a new SID STIL block
	if (str_starts_with($line, '/')) {

		// Flush previous block, should STIL.txt ever omit the blank separator
		saveStilBlock($insert, $fullname, $stil_lines, $count);

		$fullname	= ltrim($line, '/');
		$stil_lines	= [];

		continue;
	}

	// We're currently inside a SID STIL block
	if ($fullname !== null) {

		// Blank line marks the end of the block
		if (trim($line) === '') {
			saveStilBlock($insert, $fullname, $stil_lines, $count);
			continue;
		}

		// Strip surrounding whitespace
		$stil_lines[] = trim($line);
	}
}

// Handle the final entry even if STIL.txt has no trailing blank line
saveStilBlock($insert, $fullname, $stil_lines, $count);

fclose($handle);

echo "Imported ".$count." STIL entries.\n";
?>