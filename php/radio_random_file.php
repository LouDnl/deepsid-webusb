<?php
/**
 * DeepSID / Infinity Radio
 *
 * Returns a random SID file from a randomly selected high-quality HVSC
 * composer folder. Also, very small tunes or SFX are skipped.
 */

require_once("lib/class.account.php"); // Includes setup

const RATINGS_USER_ID	= 3;
const MIN_RATING		= 2;	// Remember, the 'Ratings' user still use -1 rating system

const MIN_SECONDS		= 15;	// Minimum acceptable length of a tune

try {
    $db = $account->getDB();

	// Get a random high-quality HVSC composer folder
	$query = $db->prepare("
		SELECT f.collection_path
		FROM ratings r
		INNER JOIN folders f ON f.id = r.table_id
		WHERE r.user_id = :user_id
			AND r.type = 'FOLDER'
			AND r.rating >= :min_rating
			AND f.collection_path REGEXP '^_High Voltage SID Collection/MUSICIANS/([A-Z]|0-9)/[^/]+$'
		ORDER BY RAND()
		LIMIT 1
	");

	$query->execute([
		'user_id'		=> RATINGS_USER_ID,
		'min_rating'	=> MIN_RATING,
	]);

	$folder = $query->fetchColumn();

	if ($folder === false)
		die(json_encode(array('status' => 'error', 'message' => 'Infinity Radio: Could not retrieve a random folder.')));

	// Get all SID files directly inside that folder
	$query = $db->prepare("
		SELECT collection_path, subtunes, lengths
		FROM files
		WHERE collection_path LIKE :folder
			AND collection_path NOT LIKE :subfolder
			AND collection_path LIKE '%.sid'
	");

	$query->execute([
		'folder'	=> $folder . '/%',
		'subfolder'	=> $folder . '/%/%',
	]);

	$files = $query->fetchAll(PDO::FETCH_ASSOC);

	$choices = [];
	foreach ($files as $db_file) {
		$db_lengths = preg_split('/\s+/', trim($db_file['lengths']));
		foreach ($db_lengths as $index => $db_length) {
			if (!preg_match('/^(\d+):(\d+(?:\.\d+)?)$/', $db_length, $matches))
				continue;

			$seconds = ((int)$matches[1] * 60) + (float)$matches[2];

			// It must be sufficiently long; small tunes or SFX won't work well in radio mode
			if ($seconds > MIN_SECONDS) {
				$choices[] = [
					'path'		=> $db_file['collection_path'],
					'subtune'	=> $index + 1,
					'length'	=> $db_length,
					'seconds'	=> $seconds
				];
			}
		}
	}

	if (!$choices)
		die(json_encode(array(
			'status'	=> 'retry', // Not 'error' because that shows an alert box
			'message'	=> 'Infinity Radio: Could not retrieve a suitable random file.',
			'path'		=> 'N/A',
			'subtune'	=> 1
		)));

	// Pick one complete path + subtune + length combination
	$choice = $choices[array_rand($choices)];

	echo json_encode(array(
		'status'	=> 'ok',
		'path'		=> $choice['path'],
		'subtune'	=> $choice['subtune']
	));

} catch(PDOException $e) {
	$account->logActivityError(basename(__FILE__), $e->getMessage());
	die(json_encode(array('status' => 'error', 'message' => DB_ERROR)));
}
?>