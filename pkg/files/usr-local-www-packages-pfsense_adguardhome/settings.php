<?php
/*
 * settings.php
 *
 * Settings page for the pfSense AdGuard Home package. Values are stored in
 * config.xml (installedpackages/pfsense_adguardhome/settings) and are used
 * ONLY by the Status page to read AdGuard Home's local HTTP API.
 *
 * This page NEVER modifies AdGuardHome.yaml and NEVER restarts AdGuard
 * Home - all DNS and filtering configuration lives in AdGuard Home's own
 * web UI. The API password is stored in the pfSense config (masked here,
 * never echoed back); leaving the field blank keeps the stored value.
 */
require_once("guiconfig.inc");

/* key => array(label, type, help) */
$fields = array(
	'api_url' => array('AdGuard Home API URL', 'text', 'Base URL of the AdGuard Home web UI/API, e.g. http://192.168.1.1:8088. Leave empty to use the firewall LAN address on port 8088. Note: if AdGuard Home only binds the LAN IP, a localhost URL will not work.'),
	'api_user' => array('API username', 'text', 'AdGuard Home administrator username. Used only to read statistics for the Status page - the package never changes AdGuard Home settings.'),
	'api_pass' => array('API password', 'secret', 'AdGuard Home administrator password. Stored in the pfSense config (masked, never echoed back). Leave blank to keep the stored value.'),
	'querylog_limit' => array('Query log lines', 'number', 'How many recent query-log entries to show on the Status page.'),
	'notifications' => array('pfSense notifications', 'select', 'Send a pfSense notification (System > Advanced > Notifications channels) when the AdGuard Home service is not running or protection is disabled. Checked every 5 minutes by the package monitor service; one reminder per hour while a problem persists.')
);

$vals = array();
foreach ($fields as $key => $f) {
	$v = config_get_path("installedpackages/pfsense_adguardhome/settings/{$key}");
	$vals[$key] = ($v === null) ? '' : (string)$v;
}
if ($vals['querylog_limit'] === '') {
	$vals['querylog_limit'] = '25';
}
if ($vals['notifications'] === '') {
	$vals['notifications'] = 'yes';
}

$input_errors = array();
$saved = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	$new = array();
	foreach ($fields as $key => $f) {
		$val = trim($_POST[$key] ?? '');
		$val = str_replace(array("\r", "\n", "\0"), '', $val);
		if ($f[1] == 'select') {
			if ($val === '' || !in_array($val, array('yes', 'no'))) {
				$val = 'yes';
			}
		}
		if ($f[1] == 'number' && $val !== '' && !ctype_digit($val)) {
			$input_errors[] = sprintf(gettext('%s must be a number.'), $f[0]);
		}
		$new[$key] = $val;
	}
	/* Secrets: empty input keeps the stored value. */
	if ($new['api_pass'] === '') {
		$new['api_pass'] = $vals['api_pass'];
	}
	if ($new['api_url'] !== '' && !preg_match('#^https?://#i', $new['api_url'])) {
		$input_errors[] = gettext('The AdGuard Home API URL must start with http:// or https://.');
	}
	if ($new['querylog_limit'] === '' || (int)$new['querylog_limit'] < 1 || (int)$new['querylog_limit'] > 500) {
		$input_errors[] = gettext('Query log lines must be between 1 and 500.');
	}
	if (empty($input_errors)) {
		config_set_path('installedpackages/pfsense_adguardhome/settings', $new);
		write_config("AdGuard Home package settings updated");
		$saved = true;
		$vals = $new;
	}
}

$pgtitle = array(gettext("Services"), gettext("AdGuard Home"), gettext("Settings"));
include("head.inc");

/* Theme-agnostic styling: inherit colors so both light and dark themes work. */
?>
<style>
	code.agh-key {
		background: transparent;
		color: inherit;
		padding: 0;
		font-size: 85%;
	}
</style>
<?php

$tab_array = array();
$tab_array[] = array(gettext("Status"), false, "/packages/pfsense_adguardhome/status.php");
$tab_array[] = array(gettext("Settings"), true, "/packages/pfsense_adguardhome/settings.php");
display_top_tabs($tab_array);

if ($saved) {
	print_info_box(gettext('Settings saved. They take effect the next time the Status page loads - AdGuard Home itself is not restarted.'), 'success');
}
if (!empty($input_errors)) {
	print_input_errors($input_errors);
}
?>

<form action="settings.php" method="post">
	<div class="panel panel-default">
		<div class="panel-heading"><h2 class="panel-title"><?= gettext('AdGuard Home package settings') ?></h2></div>
		<div class="panel-body">
			<div class="table-responsive">
				<table class="table table-striped table-hover">
					<tbody>
<?php foreach ($fields as $key => $f): ?>
						<tr>
							<td style="width: 30%;">
								<strong><?= htmlspecialchars($f[0]) ?></strong><br />
								<code class="agh-key"><?= htmlspecialchars($key) ?></code>
							</td>
							<td>
<?php if ($f[1] == 'secret'): ?>
								<input class="form-control" type="password" name="<?= htmlspecialchars($key) ?>" value="" autocomplete="new-password" placeholder="<?= ($vals[$key] !== '') ? gettext('(stored - leave blank to keep)') : gettext('(not set)') ?>" />
<?php elseif ($f[1] == 'select'): ?>
								<select class="form-control" name="<?= htmlspecialchars($key) ?>">
									<option value="yes" <?= ($vals[$key] == 'yes') ? 'selected' : '' ?>><?= gettext('yes') ?></option>
									<option value="no" <?= ($vals[$key] != 'yes') ? 'selected' : '' ?>><?= gettext('no') ?></option>
								</select>
<?php else: ?>
								<input class="form-control" type="text" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($vals[$key]) ?>" autocomplete="off" />
<?php endif ?>
<?php if (!empty($f[2])): ?>
								<span class="help-block"><?= htmlspecialchars($f[2]) ?></span>
<?php endif ?>
							</td>
						</tr>
<?php endforeach ?>
					</tbody>
				</table>
			</div>
		</div>
		<div class="panel-footer">
			<button type="submit" class="btn btn-primary" name="save" value="save">
				<i class="fa fa-save icon-embed-btn"></i><?= gettext('Save') ?>
			</button>
			<span class="help-block">
				<?= gettext('These settings only feed the Status page. The package never writes AdGuardHome.yaml and never restarts AdGuard Home; all DNS and filtering configuration lives in the AdGuard Home web UI. The password is stored in the pfSense config and never echoed back (AutoConfigBackup will include it).') ?>
			</span>
		</div>
	</div>
</form>

<?php include("foot.inc");
