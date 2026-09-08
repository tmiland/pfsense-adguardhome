<?php
/*
 * adguardhome.widget.php
 *
 * pfSense dashboard widget for the AdGuard Home package. Shows the service
 * state, running version, filtering statistics and protection state.
 * Read-only: AdGuard Home is never modified from here.
 */
$nocsrf = true;

require_once("guiconfig.inc");
require_once("/usr/local/pfsense_adguardhome/share/agh_api.php");

$agh = agh_collect(0);
$set = agh_settings();

$stats = $agh['stats'];
$queries = (is_array($stats) && isset($stats['num_dns_queries'])) ? (int)$stats['num_dns_queries'] : null;
$blocked = (is_array($stats) && isset($stats['num_blocked_filtering'])) ? (int)$stats['num_blocked_filtering'] : null;
$avg = (is_array($stats) && isset($stats['avg_processing_time'])) ? (float)$stats['avg_processing_time'] : null;
$pct = ($queries !== null && $queries > 0 && $blocked !== null) ? round($blocked * 100 / $queries, 1) : null;
$protection = (is_array($agh['status']) && isset($agh['status']['protection_enabled'])) ? (bool)$agh['status']['protection_enabled'] : null;
?>
<style>
	tr.agh-w-muted td {
		opacity: 0.75;
	}
	td.agh-w-bad {
		font-weight: 700;
	}
</style>
<div class="content">
	<table class="table table-striped table-hover table-condensed">
		<tbody>
			<tr>
				<td><?= gettext('Service') ?></td>
				<td class="<?= $agh['running'] ? '' : 'agh-w-bad' ?>">
					<?= $agh['running'] ? sprintf(gettext('Running (%1$s)'), $agh['v_running'] !== '' ? $agh['v_running'] : gettext('unknown')) : gettext('NOT RUNNING') ?>
				</td>
			</tr>
<?php if ($queries !== null): ?>
			<tr>
				<td><?= gettext('DNS queries (24h)') ?></td>
				<td><?= htmlspecialchars(number_format($queries)) ?></td>
			</tr>
			<tr>
				<td><?= gettext('Blocked (24h)') ?></td>
				<td><?= htmlspecialchars(number_format($blocked)) ?><?= $pct !== null ? ' (' . htmlspecialchars($pct) . '%)' : '' ?></td>
			</tr>
			<tr>
				<td><?= gettext('Avg response') ?></td>
				<td><?= $avg !== null ? htmlspecialchars(round($avg, 2)) . ' ms' : '-' ?></td>
			</tr>
<?php else: ?>
			<tr class="agh-w-muted">
				<td colspan="2"><?= $agh['api_state'] == 'unconfigured' ? gettext('Statistics need API credentials - open the package Settings page.') : gettext('No statistics available.') ?></td>
			</tr>
<?php endif ?>
<?php if ($protection !== null): ?>
			<tr>
				<td><?= gettext('Protection') ?></td>
				<td class="<?= $protection ? '' : 'agh-w-bad' ?>"><?= $protection ? gettext('Enabled') : gettext('DISABLED') ?></td>
			</tr>
<?php endif ?>
<?php if ($agh['update']): ?>
			<tr>
				<td><?= gettext('Update') ?></td>
				<td class="agh-w-bad"><?= sprintf(gettext('%1$s available (running %2$s)'), $agh['latest'], $agh['v_running']) ?></td>
			</tr>
<?php endif ?>
			<tr class="agh-w-muted">
				<td colspan="2">
					<a href="/packages/pfsense_adguardhome/status.php"><?= gettext('Status page') ?></a>
					&middot; <a href="<?= htmlspecialchars($set['base']) ?>" target="_blank"><?= gettext('AdGuard Home UI') ?></a>
				</td>
			</tr>
		</tbody>
	</table>
</div>
