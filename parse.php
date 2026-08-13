<?php
/**
 * DeepSID / Parse Tracking File
 *
 * Loads the 'visitors.txt' produced by 'tracking.php', parses it,
 * and returns formatted HTML for display.
 *
 * Expected CSV format:
 *   visitor_id, ip_address, user_agent, user_name,
 *   time_created, time_updated
 *
 * @used-by		(external)
 */

require_once("php/setup.php");
require_once("php/lib/class.useragent.php");

const TRACKFILE	= 'visitors.txt';
const CHORDIAN	= '87.60.173.201'; // Probably doesn't work anymore

// @link https://www.toms-world.org/blog/parseuseragentstring/
$parser = new parseUserAgentStringClass();

$parser->includeAndroidName	= true;
$parser->includeWindowsName	= true;
$parser->includeMacOSName	= true;

$now = strtotime(date('Y-m-d H:i:s', strtotime(TIME_ADJUST)));

$styling = '
	<style>
		body {
			margin: 0;
		}
		.counts {
			font-family: arial, sans-serif;
			padding: 6px 11px 6px;
			margin-bottom: 6px;
			border-bottom: 1px solid #aaa;
			background: #eee;
		}
			.counts span {
				color: #999;
				margin-left: 32px;
			}
		.tracking {
			border: 1px solid #000;
			margin-bottom: 10px;
			padding: 4px 6px;
			width: 400px;
			font-size: 13px;
			overflow: hidden;
			white-space: nowrap;
			text-overflow: ellipsis;
			border-radius: 3px;
		}
		.bot { background: #fee; }
		.mobile { background: #f1f1ff; }
		.user { background: #ffffe6; }
		.jch { background: #efe; }
		table {
			margin-left: 8px;
		}
			table td {
				vertical-align: top;
				padding-right: 6px;
			}
		.duplicate { color: #d00; }
		.visitor-id {
			color: #999;
			font-size: 11px;
		}
	</style>';

$stacked = array(
	'user'		=> '',
	'mobile'	=> '',
	'bot'		=> '',
	'jch'		=> '',
	'other'		=> '',
);

$count = array(
	'other'		=> 0,
	'bot'		=> 0,
	'mobile'	=> 0,
	'jch'		=> 0,
	'user'		=> 0,
	'pending'	=> 0,
);

function getBotName(string $user_agent, string $parser_name): string {

	$bots = array(
		'meta-externalagent'	=> 'Meta External Agent',
		'meta-externalfetcher'	=> 'Meta External Fetcher',
		'meta-webindexer'		=> 'Meta Web Indexer',
		'facebookexternalhit'	=> 'Facebook',
		'googlebot'				=> 'Googlebot',
		'bingbot'				=> 'Bingbot',
		'applebot'				=> 'Applebot',
		'twitterbot'			=> 'Twitterbot',
		'mediatoolkitbot'		=> 'MediaToolkitBot',
		'python-'				=> 'Python',
	);

	foreach ($bots as $needle => $name) {
		if (stripos($user_agent, $needle) !== false)
			return $name;
	}

	if ($parser_name !== '' && strtolower($parser_name) !== 'unknown')
		return $parser_name;

	return 'Bot';
}

if (($handle = fopen(TRACKFILE, 'r')) !== false) {

	while (($line = fgetcsv($handle)) !== false) {

		/*
		 * Expected columns:
		 *
		 * 0: visitor_id
		 * 1: ip_address
		 * 2: user_agent
		 * 3: user_name
		 * 4: time_created
		 * 5: time_updated
		 */
		if (count($line) < 6) {
			// Ignore malformed rows and rows using the old five-column format
			continue;
		}

		$visitor_id	= $line[0];
		$ip			= $line[1];
		$user_agent	= $line[2];
		$user_name	= $line[3];
		$created	= (int)$line[4];
		$updated	= (int)$line[5];

		$parser->parseUserAgentString($user_agent);

		$duration = $minutes = round(($now - $created) / 60);
		$hours = 0;

		if ($duration > 60) {
			$hours = floor($duration / 60);
			$minutes = $duration % 60;
		}

		$last = round(($now - $updated) / 60);

		$type = ' other';

		if (
			$parser->type == 'bot' ||
			str_starts_with($ip, '43.172.') ||
			str_starts_with($ip, '43.173.') ||
			stripos('x'.$user_agent, 'python-') ||
			stripos('x'.$user_agent, 'bingbot') ||
			stripos('x'.$user_agent, 'googlebot') ||
			stripos('x'.$user_agent, 'applebot') ||
			stripos('x'.$user_agent, 'twitterbot') ||
			stripos('x'.$user_agent, 'facebookexternalhit') ||
			stripos('x'.$user_agent, 'meta-externalagent') ||
			stripos('x'.$user_agent, 'meta-externalfetcher') ||
			stripos('x'.$user_agent, 'meta-webindexer') ||
			stripos('x'.$user_agent, 'mediatoolkitbot')
		) {
			$type = ' bot';

		} elseif ($ip == CHORDIAN) {
			$type = ' jch';

		} elseif ($user_name !== '') {
			// Logged-in visitors can be trusted immediately
			$type = ' user';

		} elseif ($updated <= $created) {
			/*
			* Anonymous visitor has only contacted tracking.php once.
			*
			* Don't count or display it until the same visitor ID returns.
			* This eliminates one-shot crawlers that generate a new UUID
			* for every request.
			*/
			$count['pending']++;
			continue;

		} elseif ($parser->type == 'mobile') {
			$type = ' mobile';
		}

		$count[trim($type)]++;

		/*
		 * Escape values before placing them in HTML.
		 */
		$safe_visitor_id = htmlspecialchars(
			$visitor_id,
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8'
		);

		$safe_ip = htmlspecialchars(
			$ip,
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8'
		);

		$safe_user_agent = htmlspecialchars(
			$user_agent,
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8'
		);

		$safe_user_name = htmlspecialchars(
			$user_name,
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8'
		);

		$safe_parser_name = htmlspecialchars(
			$parser->fullname,
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8'
		);

		/*
		 * Retain support for the old duplicate-IP warning text,
		 * although tracking.php no longer generates it.
		 */
		$safe_ip = str_replace(
			'DUPLICATE IP ADDRESS',
			'<span class="duplicate">DUPLICATE IP ADDRESS</span>',
			$safe_ip
		);

		$browser = $parser->fullname != 'unknown'
			? $safe_parser_name
			: $safe_user_agent;

		$bot_name = '';

		if (trim($type) === 'bot') {
			$bot_name = htmlspecialchars(
				getBotName($user_agent, $parser->fullname),
				ENT_QUOTES | ENT_SUBSTITUTE,
				'UTF-8'
			);
		}

		if ($user_name !== '')
			$text = '<b>'.$safe_user_name.'</b> ('.$safe_ip.')';
		else if ($bot_name !== '')
			$text = $bot_name.' ('.$safe_ip.')';
		else
			$text = $safe_ip;

		$box = '
			<div class="tracking'.$type.'" title="Visitor ID: '.$safe_visitor_id.'">
				'.$text.'<br />
				'.date('H:i', $created).' (
					'.(
						$duration > 2
							? (
								$hours
									? '<b>'.$hours.'</b> hours '
									: ''
							).'<b>'.$minutes.'</b> minutes ago'
							: '<b>just now</b>'
					).'
				) - last updated
				'.(
					$last > 2
						? '<b>'.$last.'</b> minutes ago'
						: '<b>just now</b>'
				).'<br />
				'.$browser.'
			</div>';

		$stacked[ltrim($type)] .= $box;
	}

	fclose($handle);
}

$counts = '
	<div class="counts"><b>DeepSID</b>
		<span><b>Users:</b> '.$count['user'].'</span>
		<span><b>Mobile:</b> '.$count['mobile'].'</span>
		<span><b>Other:</b> '.$count['other'].'</span>
		<span><b>Bots:</b> '.$count['bot'].'</span>
		<span><b>Pending:</b> '.$count['pending'].'</span>
		<span style="color:#000;"><b>Visitors:</b> '.
			($count['other'] + $count['mobile'] + $count['user']).
		'</span>
	</div>';

echo $styling.$counts.
	'<table>
		<tr>
			<td>'.
				$stacked['jch'].
				$stacked['user'].
				$stacked['mobile'].
			'</td>
			<td>'.$stacked['other'].'</td>
			<td>'.$stacked['bot'].'</td>
		</tr>
	</table>';
?>