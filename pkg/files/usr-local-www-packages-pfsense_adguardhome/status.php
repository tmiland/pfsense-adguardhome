<?php
/*
 * status.php
 *
 * Status page for the pfSense AdGuard Home package. Shows the service
 * state, running vs latest AdGuard Home version, live filtering statistics
 * (via AdGuard Home's local HTTP API, read-only) and a recent query-log
 * tail. AdGuard Home itself is never modified from here - all DNS and
 * filtering configuration lives in AdGuard Home's own web UI.
 */
require_once("guiconfig.inc");
require_once("service-utils.inc");

$pgtitle = array(gettext("Services"), gettext("AdGuard Home"), gettext("Status"));
include("head.inc");

require_once("/usr/local/pfsense_adguardhome/share/agh_api.php");

$set = agh_settings();
$api_base = $set['base'];
$querylog_limit = $set['qlog_limit'];

/* Read-only gather: service state, versions, stats, querylog. */
$agh = agh_collect($querylog_limit);
$running = $agh['running'];
$v_running = $agh['v_running'];
$latest = $agh['latest'];
$update = $agh['update'];
$api_state = $agh['api_state'];
$agh_status = $agh['status'];
$agh_stats = $agh['stats'];
$agh_qlog = $agh['qlog'];

$reason_labels = array(
	-1 => 'Allowed (whitelist)',
	0 => 'Allowed',
	1 => 'Blocked (blocklist)',
	2 => 'Blocked (SafeBrowsing)',
	3 => 'Blocked (Parental)',
	4 => 'Blocked (SafeSearch)',
	5 => 'Blocked (invalid)',
	6 => 'Blocked (service)',
	7 => 'Blocked (custom)',
	10 => 'Rewrite',
	11 => 'Rewrite (hosts)',
	12 => 'Rewrite (DDR)'
);

$queries = is_array($agh_stats) && isset($agh_stats['num_dns_queries']) ? (int)$agh_stats['num_dns_queries'] : null;
$blocked = is_array($agh_stats) && isset($agh_stats['num_blocked_filtering']) ? (int)$agh_stats['num_blocked_filtering'] : null;
$blocked_pct = ($queries !== null && $queries > 0 && $blocked !== null) ? round($blocked * 100 / $queries, 1) : null;
$avg_time = is_array($agh_stats) && isset($agh_stats['avg_processing_time']) ? $agh_stats['avg_processing_time'] : null;
$protection = is_array($agh_status) && isset($agh_status['protection_enabled']) ? (bool)$agh_status['protection_enabled'] : null;

$update = ($v_running !== '' && $latest !== '' && version_compare($v_running, $latest, '<'));

/* Theme-agnostic styling: inherit colors so both light and dark themes work. */
?>
<style>
	pre.agh-log {
		background: transparent;
		color: inherit;
		border: 1px solid currentColor;
		border-radius: 4px;
		overflow-wrap: anywhere;
		white-space: pre-wrap;
	}
	td.agh-blocked {
		font-weight: 700;
	}
	td.agh-muted {
		opacity: 0.65;
	}
	a.agh-card, a.agh-card:hover {
		text-decoration: none;
		color: inherit;
	}
</style>
<?php

$tab_array = array();
$tab_array[] = array(gettext("Status"), true, "/packages/pfsense_adguardhome/status.php");
$tab_array[] = array(gettext("Settings"), false, "/packages/pfsense_adguardhome/settings.php");
display_top_tabs($tab_array);

if (!$running) {
	print_info_box(gettext('The AdGuard Home service is not running.') . ' ' .
		sprintf(gettext('Start it from %1$sStatus > Services%2$s or with %3$sservice AdGuardHome start%4$s.'),
			'<a href="status_services.php">', '</a>', '<code>', '</code>'), 'warning');
} else {
	print_info_box(sprintf(gettext('AdGuard Home is running (version %1$s). Manage DNS filtering in its own web UI: %2$s'),
		$v_running !== '' ? $v_running : gettext('unknown'),
		'<a href="' . htmlspecialchars($api_base) . '" target="_blank">' . htmlspecialchars($api_base) . '</a>'), 'success');
}

if ($update) {
	print_info_box(sprintf(gettext('A newer AdGuard Home release is available: %1$s (running %2$s). Update from AdGuard Home\'s own web UI, which self-updates the binary safely.'),
		$latest, $v_running), 'info');
}

if ($api_state == 'unconfigured') {
	print_info_box(gettext('Statistics need AdGuard Home credentials. Set them on the Settings tab - they are used only to read the local API on this page.') . ' ' .
		'<a href="settings.php">' . gettext('Open Settings') . '</a>', 'info');
} elseif ($api_state == 'failed') {
	print_info_box(gettext('Could not read the AdGuard Home API. Check the API URL, username and password in Settings.'), 'warning');
}
?>

	<div class="panel panel-default">
		<div class="panel-heading"><h2 class="panel-title"><?= gettext('Filtering statistics (last 24 hours)') ?></h2></div>
		<div class="panel-body">
			<div class="row">
				<div class="col-md-3">
					<div class="panel panel-default">
						<div class="panel-body text-center">
							<h3><?= $queries !== null ? htmlspecialchars(number_format($queries)) : '-' ?></h3>
							<span><?= gettext('DNS queries') ?></span>
						</div>
					</div>
				</div>
				<div class="col-md-3">
					<div class="panel panel-default">
						<div class="panel-body text-center">
							<h3><?= $blocked !== null ? htmlspecialchars(number_format($blocked)) : '-' ?></h3>
							<span><?= gettext('Blocked / filtered') ?></span>
						</div>
					</div>
				</div>
				<div class="col-md-3">
					<div class="panel panel-default">
						<div class="panel-body text-center">
							<h3><?= $blocked_pct !== null ? htmlspecialchars($blocked_pct . '%') : '-' ?></h3>
							<span><?= gettext('Blocked share') ?></span>
						</div>
					</div>
				</div>
				<div class="col-md-3">
					<div class="panel panel-default">
						<div class="panel-body text-center">
							<h3><?= $avg_time !== null ? htmlspecialchars(round((float)$avg_time, 1) . ' ms') : '-' ?></h3>
							<span><?= gettext('Average response time') ?></span>
						</div>
					</div>
				</div>
			</div>
<?php if ($protection !== null): ?>
			<p class="<?= $protection ? '' : 'agh-blocked' ?>">
				<?= $protection ? gettext('Protection is enabled.') : gettext('Protection is DISABLED - queries are not being filtered.') ?>
			</p>
<?php endif ?>
		</div>
	</div>

	<div class="row">
		<div class="col-md-4">
			<div class="panel panel-default">
				<div class="panel-heading"><h2 class="panel-title"><?= gettext('Top queried domains') ?></h2></div>
				<div class="panel-body">
<?php $top = agh_pairs(is_array($agh_stats) ? $agh_stats['top_queried_domains'] ?? [] : []); $n = 0; ?>
					<table class="table table-condensed">
<?php foreach ($top as $name => $count): $n++; if ($n > 10) { break; } ?>
						<tr><td><?= htmlspecialchars((string)$name) ?></td><td class="text-right"><?= htmlspecialchars(number_format((int)$count)) ?></td></tr>
<?php endforeach; if ($n === 0): ?>
						<tr><td class="text-muted"><?= gettext('No data (API not configured or no queries yet).') ?></td></tr>
<?php endif ?>
					</table>
				</div>
			</div>
		</div>
		<div class="col-md-4">
			<div class="panel panel-default">
				<div class="panel-heading"><h2 class="panel-title"><?= gettext('Top blocked domains') ?></h2></div>
				<div class="panel-body">
<?php $top = agh_pairs(is_array($agh_stats) ? $agh_stats['top_blocked_domains'] ?? [] : []); $n = 0; ?>
					<table class="table table-condensed">
<?php foreach ($top as $name => $count): $n++; if ($n > 10) { break; } ?>
						<tr><td class="agh-blocked"><?= htmlspecialchars((string)$name) ?></td><td class="text-right"><?= htmlspecialchars(number_format((int)$count)) ?></td></tr>
<?php endforeach; if ($n === 0): ?>
						<tr><td class="text-muted"><?= gettext('No data.') ?></td></tr>
<?php endif ?>
					</table>
				</div>
			</div>
		</div>
		<div class="col-md-4">
			<div class="panel panel-default">
				<div class="panel-heading"><h2 class="panel-title"><?= gettext('Top clients') ?></h2></div>
				<div class="panel-body">
<?php $top = agh_pairs(is_array($agh_stats) ? $agh_stats['top_clients'] ?? [] : []); $n = 0; ?>
					<table class="table table-condensed">
<?php foreach ($top as $name => $count): $n++; if ($n > 10) { break; } ?>
						<tr><td><?= htmlspecialchars((string)$name) ?></td><td class="text-right"><?= htmlspecialchars(number_format((int)$count)) ?></td></tr>
<?php endforeach; if ($n === 0): ?>
						<tr><td class="text-muted"><?= gettext('No data.') ?></td></tr>
<?php endif ?>
					</table>
				</div>
			</div>
		</div>
	</div>

	<div class="panel panel-default">
		<div class="panel-heading">
			<h2 class="panel-title"><?= sprintf(gettext('Recent query log (last %1$d)'), $querylog_limit) ?>
				&mdash; <a href="<?= htmlspecialchars($api_base) ?>" target="_blank"><?= gettext('full log in AdGuard Home UI') ?></a>
			</h2>
		</div>
		<div class="panel-body">
			<div class="table-responsive">
				<table class="table table-striped table-hover table-condensed">
					<thead>
						<tr>
							<th><?= gettext('Time') ?></th>
							<th><?= gettext('Client') ?></th>
							<th><?= gettext('Domain') ?></th>
							<th><?= gettext('Result') ?></th>
							<th><?= gettext('Status') ?></th>
						</tr>
					</thead>
					<tbody>
<?php $shown = 0; if (is_array($agh_qlog)): foreach (array_reverse($agh_qlog) as $e): $shown++; $reason = isset($e['reason']) ? (int)$e['reason'] : null; $is_blocked = ($reason !== null && $reason >= 1 && $reason <= 7); ?>
						<tr>
							<td style="white-space: nowrap;" class="agh-muted"><?= htmlspecialchars(isset($e['time']) ? substr((string)$e['time'], 11, 8) : '') ?></td>
							<td><?= htmlspecialchars(isset($e['client']) ? (string)$e['client'] : (isset($e['IP']) ? (string)$e['IP'] : '')) ?></td>
							<td><?= htmlspecialchars(isset($e['name']) ? (string)$e['name'] : '') ?></td>
							<td class="<?= $is_blocked ? 'agh-blocked' : '' ?>"><?= $reason !== null && isset($reason_labels[$reason]) ? gettext($reason_labels[$reason]) : (($reason === 0) ? gettext('Allowed') : '') ?></td>
							<td class="agh-muted"><?= htmlspecialchars(isset($e['status']) ? (string)$e['status'] : '') ?></td>
						</tr>
<?php endforeach; endif; if ($shown === 0): ?>
						<tr><td colspan="5" class="text-muted"><?= gettext('No query-log entries (API not configured, or the log is empty).') ?></td></tr>
<?php endif ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

<script>
	setTimeout(function() { location.reload(); }, 60000);
</script>

<?php include("foot.inc");
