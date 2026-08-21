<?php
/**
 * DeepSID / Infinity Radio
 *
 * Returns a random SID file from a randomly selected
 * high-quality HVSC composer folder.
 */

require_once("lib/class.account.php"); // Includes setup

const RATINGS_USER_ID	= 3;
const MIN_RATING		= 2;

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

	// Get a random SID file directly inside that folder
	$query = $db->prepare("
		SELECT collection_path, subtunes
		FROM files
		WHERE collection_path LIKE :folder
			AND collection_path NOT LIKE :subfolder
			AND collection_path LIKE '%.sid'
		ORDER BY RAND()
		LIMIT 1
	");

	$query->execute([
		'folder'	=> $folder . '/%',
		'subfolder'	=> $folder . '/%/%',
	]);

	$file = $query->fetch(PDO::FETCH_ASSOC);

	if (!$file)
		die(json_encode(array('status' => 'error', 'message' => 'Infinity Radio: Could not retrieve a random file.')));

	$collection_path = $file['collection_path'];
	$subtunes = (int)$file['subtunes'];

	// Pick a random subtune
	$subtune = $subtunes > 1 ? random_int(1, $subtunes) : 1;

	echo json_encode(array(
		'status'	=> 'ok',
		'path'		=> $collection_path,
		'subtune'	=> $subtune
	));

} catch(PDOException $e) {
	$account->logActivityError(basename(__FILE__), $e->getMessage());
	die(json_encode(array('status' => 'error', 'message' => DB_ERROR)));
}
?>