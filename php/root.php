<?php
/**
 * DeepSID
 *
 * Build an HTML welcome page for the root.
 * 
 *  - Left and right top lists boxes
 */

// require_once("setup.php");
require_once("root_generate.php");

if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] != 'XMLHttpRequest')
	die("Direct access not permitted.");

$important = '<b>NOTE:</b> The <b>SOASC handlers</b> are currently ineffective as Stoneoak Valley have
	<span class="bad">server problems</span>. They are working on the problem. Please use the emulators
	for the time being until this is fixed.';

$available_lists = ['maxfiles', 'longest', 'mostgames', 'countries', 'startaddr', 'maxtime'];
$dropdown_options =
	'<option value="'.$available_lists[0].'">Most SID tunes produced</option>'.
	'<option value="'.$available_lists[1].'">The longest SID tunes</option>'.
	'<option value="'.$available_lists[2].'">Most games covered</option>'.
	'<option value="'.$available_lists[3].'">Composers in countries</option>'.
	'<option value="'.$available_lists[4].'">Most popular start address</option>'.
	'<option value="'.$available_lists[5].'">Total playing time produced</option>'.
	'';

$row_options =
	'<option value="10">10</option>'.
	'<option value="25">25</option>'.
	'<option value="50">50</option>'.
	'<option value="100">100</option>'.
	'<option value="250">250</option>';

// Randomly choose two lists while also making sure they're not the same one
$choices = array_rand($available_lists, 2);
$choice_left = $available_lists[$choices[0]];
$choice_right = $available_lists[$choices[1]];

$html =
	'<div style="position:relative;top:-2px;left:135px;margin-bottom:150px;font:normal 12px Montserrat,sans-serif;color:#d3d4c6;"><i>Behold!&nbsp;&nbsp;You now find yourself in &ndash;</i></div>'.
	'<div class="root-wide">'.$important.'</div>'.
	'<table class="root"><tr>'.
		'<td style="max-width:300px;">'.
			'<select class="dropdown-top-list dropdown-top-list-left" name="select-top-list-left">'.
				$dropdown_options.
			'</select>'.
			'<label>Rows</label>'.
			'<select class="dropdown-top-rows dropdown-top-rows-left" name="select-top-rows-left">'.
				$row_options.
			'</select>'.
			'<table class="top-list-left tight compo" style="max-width:100%;font-size:14px;padding:8px 12px;">'.
				GenerateList(10, $choice_left).
			'</table>'.
		'</td>'.
		'<td style="width:10px;"></td>'.
		'<td style="max-width:300px;">'.
			'<select class="dropdown-top-list dropdown-top-list-right" name="select-top-list-right">'.
				$dropdown_options.
			'</select>'.
			'<label>Rows</label>'.
			'<select class="dropdown-top-rows dropdown-top-rows-right" name="select-top-rows-right">'.
				$row_options.
			'</select>'.
			'<table class="top-list-right tight compo" style="max-width:100%;font-size:14px;padding:8px 12px;">'.
				GenerateList(10, $choice_right).
			'</table>'.
		'</td>'.
	'</tr></table>';

echo json_encode(array('status' => 'ok', 'html' => $html, 'left' => $choice_left, 'right' => $choice_right));
?>