<?php
/*
 * agh_monitor.php - one-shot check, run every cycle by the monitor loop.
 *
 * Alerts via the pfSense notification system (System > Advanced >
 * Notifications channels) when:
 *   - the AdGuard Home service is not running, or
 *   - AdGuard Home protection is disabled while the service runs.
 *
 * Alerts are throttled to one per hour per type; a recovery notice is sent
 * once when a bad state clears. Read-only: never touches AdGuardHome.yaml
 * or the AdGuard Home process.
 */

require_once('/etc/inc/config.inc');
require_once('/etc/inc/notices.inc');
require_once('/usr/local/pfsense_adguardhome/share/agh_api.php');

$state_file = '/var/db/pfsense_adguardhome_monitor.state';
$log_file = '/var/log/pfsense_adguardhome_monitor.log';
$throttle = 3600;
$now = time();

function aghmon_log($msg) {
	global $log_file;
	@file_put_contents($log_file, date('Y-m-d H:i:s') . ' - ' . $msg . "\n", FILE_APPEND);
}

function aghmon_notify($msg) {
	@notify_all_remote($msg);
	aghmon_log('NOTIFY: ' . $msg);
}

$state = json_decode(@file_get_contents($state_file), true);
if (!is_array($state)) {
	$state = array();
}

$set = agh_settings();
if ($set['notifications'] !== 'yes') {
	/* Monitoring off: clear bad-state memory so re-enabling starts clean. */
	if (!empty($state['bad_service']) || !empty($state['bad_protection'])) {
		$state['bad_service'] = false;
		$state['bad_protection'] = false;
		@file_put_contents($state_file, json_encode($state));
	}
	exit(0);
}

$agh = agh_collect(0);
$alerts = array();

if (!$agh['running']) {
	$alerts['service'] = 'AdGuard Home is NOT running on the pfSense firewall - LAN DNS may be failing! Start it with: service AdGuardHome start';
}

$protection = null;
if ($agh['running'] && is_array($agh['status']) && isset($agh['status']['protection_enabled'])) {
	$protection = (bool)$agh['status']['protection_enabled'];
}
if ($agh['running'] && $protection === false) {
	$alerts['protection'] = 'AdGuard Home DNS protection is DISABLED - queries are not being filtered!';
}

foreach (array('service', 'protection') as $type) {
	$bad = isset($alerts[$type]);
	$was = !empty($state['bad_' . $type]);
	if ($bad) {
		if (!$was) {
			aghmon_notify($alerts[$type]);
			$state['alert_' . $type . '_last'] = $now;
		} elseif ((int)$state['alert_' . $type . '_last'] + $throttle < $now) {
			aghmon_notify($alerts[$type] . ' (reminder)');
			$state['alert_' . $type . '_last'] = $now;
		}
		$state['bad_' . $type] = true;
	} elseif ($was) {
		aghmon_notify($type == 'service' ? 'AdGuard Home service is running again.' : 'AdGuard Home DNS protection is enabled again.');
		$state['bad_' . $type] = false;
	}
}

@file_put_contents($state_file, json_encode($state));
