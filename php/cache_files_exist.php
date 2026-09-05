<?php
/**
 * DeepSID
 *
 * Check whether multiple cached CSDb release files exist.
 *
 * @uses        $_POST['ids']
 *
 * @used-by     browser.js
 */

if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
	$_SERVER['HTTP_X_REQUESTED_WITH'] != 'XMLHttpRequest') {
	die("Direct access not permitted.");
}

if (!isset($_POST['ids']) || !is_array($_POST['ids'])) {
	die(json_encode(array(
		'status' => 'error',
		'message' => 'Missing ID numbers.'
	)));
}

$cached = array();

foreach ($_POST['ids'] as $id) {

	$id = (int)$id;
	if (!$id)
		continue;

	$fullname = '../cache/csdb/release_'.$id.'.cache.gz';
	$cached[$id] = file_exists($fullname);
}

echo json_encode(array(
	'status' => 'ok',
	'cached' => $cached
));
?>