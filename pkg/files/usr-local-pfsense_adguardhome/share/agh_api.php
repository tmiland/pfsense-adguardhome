<?php
/*
 * agh_api.php - shared read-only helpers for the pfSense AdGuard Home
 * package (status page, dashboard widget, status monitor).
 *
 * Requires the pfSense config machinery to be loaded by the caller
 * (guiconfig.inc in web context, /etc/inc/config.inc from CLI).
 *
 * This library never writes AdGuardHome.yaml and never stops or restarts
 * the AdGuard Home process.
 */

function agh_settings() {
	$s = config_get_path('installedpackages/pfsense_adguardhome/settings', []);
	$base = isset($s['api_url']) ? trim((string)$s['api_url']) : '';
	if ($base === '') {
		if (!empty($_SERVER['SERVER_ADDR'])) {
			$base = 'http://' . $_SERVER['SERVER_ADDR'] . ':8088';
		} elseif (config_get_path('interfaces/lan/ipaddr')) {
			$base = 'http://' . config_get_path('interfaces/lan/ipaddr') . ':8088';
		} else {
			$base = 'http://127.0.0.1:8088';
		}
	}
	return array(
		'base' => rtrim($base, '/'),
		'user' => isset($s['api_user']) ? (string)$s['api_user'] : '',
		'pass' => isset($s['api_pass']) ? (string)$s['api_pass'] : '',
		'qlog_limit' => (isset($s['querylog_limit']) && ctype_digit((string)$s['querylog_limit'])) ? (int)$s['querylog_limit'] : 25,
		'notifications' => (isset($s['notifications']) && $s['notifications'] === 'no') ? 'no' : 'yes'
	);
}

function agh_service_running() {
	exec('/usr/sbin/service AdGuardHome onestatus >/dev/null 2>&1', $o, $rc);
	return ($rc === 0);
}

function agh_running_version() {
	$v = '';
	if (@is_executable('/opt/AdGuardHome/AdGuardHome')) {
		exec('/opt/AdGuardHome/AdGuardHome --version 2>/dev/null', $o);
		if (!empty($o) && preg_match('/version\s+v?([0-9][0-9a-zA-Z.\-]*)/i', $o[0], $m)) {
			$v = $m[1];
		}
	}
	return $v;
}

function agh_latest_version() {
	/* Latest upstream release, cached for an hour so page loads stay snappy. */
	$latest = '';
	$latest_file = '/tmp/pfsense_adguardhome_latest.json';
	if (file_exists($latest_file)) {
		$c = json_decode(@file_get_contents($latest_file), true);
		if (is_array($c) && isset($c['tag'], $c['ts']) && (time() - (int)$c['ts']) < 3600) {
			$latest = $c['tag'];
		}
	}
	if ($latest === '') {
		$ch = curl_init('https://api.github.com/repos/AdguardTeam/AdGuardHome/releases/latest');
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 5,
			CURLOPT_CONNECTTIMEOUT => 3,
			CURLOPT_USERAGENT => 'pfsense-pkg-adguardhome'
		));
		$b = curl_exec($ch);
		curl_close($ch);
		$j = is_string($b) ? json_decode($b, true) : null;
		if (is_array($j) && isset($j['tag_name'])) {
			$latest = ltrim($j['tag_name'], 'v');
			@file_put_contents($latest_file, json_encode(array('tag' => $latest, 'ts' => time())));
		}
	}
	return $latest;
}

/* AGH local API (read-only). Login responses may be plain "OK" - only the
   HTTP status matters; the session lives in the cookie jar. */
function agh_call($base, $path, $jar, $post = null) {
	$ch = curl_init($base . $path);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 5,
		CURLOPT_CONNECTTIMEOUT => 3,
		CURLOPT_COOKIEJAR => $jar,
		CURLOPT_COOKIEFILE => $jar,
		CURLOPT_HTTPHEADER => array('Content-Type: application/json')
	));
	if ($post !== null) {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post));
	}
	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if ($code != 200 || !is_string($body)) {
		return null;
	}
	if ($post !== null) {
		return array();
	}
	$j = json_decode($body, true);
	return is_array($j) ? $j : null;
}

/* Top-list shapes across AGH versions:
   - 0.107.x: [{"name": count}, {"name": count}, ...] (single-key objects)
   - some versions: [["name", count], ...] pairs
   - very old: {"name": count, ...} plain map */
function agh_pairs($arr) {
	if (!is_array($arr)) {
		return array();
	}
	$out = array();
	foreach ($arr as $k => $v) {
		if (is_array($v)) {
			if (count($v) >= 2 && isset($v[0]) && is_string($v[0]) && isset($v[1])) {
				$out[$v[0]] = $v[1];
			} else {
				foreach ($v as $kk => $vv) {
					if (is_string($kk) && is_numeric($vv)) {
						$out[$kk] = $vv;
					}
				}
			}
		} elseif (is_string($k) && is_numeric($v)) {
			$out[$k] = $v;
		}
	}
	return $out;
}

/* Gather everything the UI needs in one call. $qlog_limit > 0 also fetches
   the recent querylog. */
function agh_collect($qlog_limit = 0) {
	$running = agh_service_running();
	$out = array(
		'running' => $running,
		'v_running' => agh_running_version(),
		'latest' => agh_latest_version(),
		'api_state' => 'down',
		'status' => null,
		'stats' => null,
		'qlog' => null
	);
	$out['update'] = ($out['v_running'] !== '' && $out['latest'] !== '' && version_compare($out['v_running'], $out['latest'], '<'));
	if (!$running) {
		return $out;
	}
	$set = agh_settings();
	if ($set['user'] === '' || $set['pass'] === '') {
		$out['api_state'] = 'unconfigured';
		return $out;
	}
	$jar = tempnam('/tmp', 'aghck');
	$login = agh_call($set['base'], '/control/login', $jar, array('name' => $set['user'], 'password' => $set['pass']));
	if ($login === null) {
		@unlink($jar);
		$out['api_state'] = 'failed';
		return $out;
	}
	$out['status'] = agh_call($set['base'], '/control/status', $jar);
	$out['stats'] = agh_call($set['base'], '/control/stats', $jar);
	if ($qlog_limit > 0) {
		$q = agh_call($set['base'], '/control/querylog?limit=' . (int)$qlog_limit . '&response_status=all', $jar);
		/* AGH 0.107 wraps the entries: {"data": [...], "oldest": "..."}. */
		if (is_array($q) && isset($q['data']) && is_array($q['data'])) {
			$q = $q['data'];
		}
		$out['qlog'] = is_array($q) ? $q : null;
	}
	@unlink($jar);
	$out['api_state'] = ($out['stats'] !== null) ? 'ok' : 'failed';
	return $out;
}
