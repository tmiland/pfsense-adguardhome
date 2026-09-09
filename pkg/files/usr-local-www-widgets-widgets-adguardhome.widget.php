<?php
/*
 * adguardhome.widget.php
 *
 * Dashboard widget for the AdGuard Home package: service state, version,
 * 24h filtering stats and protection state, linking into the package
 * Status page and the AdGuard Home web UI. Same look as the
 * pfSense-pkg-abuseipdb dashboard widget.
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
<div class="content">
	<table class="table table-striped table-hover">
		<tbody>
			<tr>
				<td><?= gettext('Service') ?></td>
				<td>
<?php if ($agh['running']): ?>
					<span class="text-success"><i class="fa-solid fa-circle-check"></i> <?= sprintf(gettext('running (%1$s)'), $agh['v_running'] !== '' ? $agh['v_running'] : gettext('unknown')) ?></span>
<?php else: ?>
					<span class="text-danger"><i class="fa-solid fa-circle-xmark"></i> <?= gettext('not running') ?></span>
<?php endif ?>
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
			<tr>
				<td><?= gettext('Statistics') ?></td>
				<td><?= $agh['api_state'] == 'unconfigured' ? gettext('needs credentials - open Settings') : gettext('not available') ?></td>
			</tr>
<?php endif ?>
<?php if ($protection !== null): ?>
			<tr>
				<td><?= gettext('Protection') ?></td>
				<td>
<?php if ($protection): ?>
					<span class="text-success"><?= gettext('enabled') ?></span>
<?php else: ?>
					<span class="text-danger"><i class="fa-solid fa-circle-xmark"></i> <?= gettext('DISABLED') ?></span>
<?php endif ?>
				</td>
			</tr>
<?php endif ?>
<?php if ($agh['update']): ?>
			<tr>
				<td><?= gettext('Update') ?></td>
				<td><span class="text-warning"><?= sprintf(gettext('%1$s available (running %2$s)'), $agh['latest'], $agh['v_running']) ?></span></td>
			</tr>
<?php endif ?>
		</tbody>
	</table>
	<div class="text-right" style="padding-bottom: 5px;">
		<a href="/packages/pfsense_adguardhome/status.php"><?= gettext('Open AdGuard Home status') ?> <i class="fa-solid fa-arrow-right"></i></a>
		&middot; <a href="<?= htmlspecialchars($set['base']) ?>" target="_blank"><?= gettext('web UI') ?></a>
	</div>
</div>
