<?php
/**
 * DeepSID
 *
 * Read and optionally write settings to the user's account.
 * 
 * If a setting is not specified, the script just returns the current state of
 * the settings for the logged in user.
 * 
 * Settings that can be specified for saving:
 * 
 * @uses		$_POST['firstsubtune']		0 or 1
 * @uses		$_POST['primaryrelease']	0 or 1
 * @uses		$_POST['delaynext']			0 or 1
 * @uses		$_POST['delayduration']		number of milliseconds
 * @uses		$_POST['skiptune']			0 or 1
 * @uses		$_POST['marktune']			0 or 1
 * @uses		$_POST['skipbad']			0 or 1
 * @uses		$_POST['skiplong']			0 or 1
 * @uses		$_POST['skipshort']			0 or 1
 * 
 * @used-by		main.js
 */

require_once("class.account.php"); // Includes setup

if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] != 'XMLHttpRequest')
	die("Direct access not permitted.");

$first_time = array(
	'firstsubtune'		=> 0,
	'primaryrelease'	=> 0,
	'delaynext'			=> 0,
	'delayduration'		=> 1500,
	'skiptune'			=> 1,
	'marktune'			=> 0,
	'skipbad'			=> 0,
	'skiplong'			=> 0,
	'skipshort'			=> 0,
);

$user_id = $account->checkLogin() ? $account->userID() : 0;

// Don't add 'session_write_close()' here

if (!$user_id) die(json_encode(array('status' => 'ok', 'settings' => $first_time)));

try {
	$db = $account->getDB();

	// First get all the user's settings
	$select = $db->query('SELECT flags FROM users WHERE id = '.$user_id);
	$select->setFetchMode(PDO::FETCH_OBJ);
	$settings = unserialize($select->fetch()->flags);

	// If not defined yet for the user
	if (!$settings['firstsubtune'])			$settings['firstsubtune']		= $first_time['firstsubtune'];
	if (!$settings['primaryrelease'])		$settings['primaryrelease']		= $first_time['primaryrelease'];
	if (!$settings['delaynext'])			$settings['delaynext']			= $first_time['delaynext'];
	if (!$settings['delayduration'])		$settings['delayduration']		= $first_time['delayduration'];
	if (!$settings['skiptune'])				$settings['skiptune']			= $first_time['skiptune'];
	if (!$settings['marktune'])				$settings['marktune']			= $first_time['marktune'];
	if (!$settings['skipbad'])				$settings['skipbad']			= $first_time['skipbad'];
	if (!$settings['skiplong'])				$settings['skiplong']			= $first_time['skiplong'];
	if (!$settings['skipshort'])			$settings['skipshort']			= $first_time['skipshort'];

	// Adjust settings
	if (isset($_POST['firstsubtune']))		$settings['firstsubtune']		= (int)$_POST['firstsubtune'];
	if (isset($_POST['primaryrelease']))	$settings['primaryrelease']		= (int)$_POST['primaryrelease'];
	if (isset($_POST['delaynext']))			$settings['delaynext']			= (int)$_POST['delaynext'];
	if (isset($_POST['delayduration']))		$settings['delayduration']		= (int)$_POST['delayduration'];
	if (isset($_POST['skiptune']))			$settings['skiptune']			= (int)$_POST['skiptune'];
	if (isset($_POST['marktune']))			$settings['marktune']			= (int)$_POST['marktune'];
	if (isset($_POST['skipbad']))			$settings['skipbad']			= (int)$_POST['skipbad'];
	if (isset($_POST['skiplong']))			$settings['skiplong']			= (int)$_POST['skiplong'];
	if (isset($_POST['skipshort']))			$settings['skipshort']			= (int)$_POST['skipshort'];

	if ($_POST) {
		// Store the settings
		$serialized = serialize($settings);
		$update = $db->prepare('UPDATE users SET flags = :flags WHERE id = '.$user_id);
		$update->execute(array(':flags' => $serialized));
		$account->logActivity('User "'.$_SESSION['user_name'].'" updated personal settings: '.$serialized);
		if ($update->rowCount() == 0)
			die(json_encode(array('status' => 'error', 'message' => 'Could not update your settings.')));
	}

} catch(PDOException $e) {
	$account->logActivityError(basename(__FILE__), $e->getMessage());
	die(json_encode(array('status' => 'error', 'message' => DB_ERROR)));
}

echo json_encode(array(
	'status'	=> 'ok',
	'settings'	=> $settings
));
?>