#!/bin/sh
# pfsense_adguardhome package setup.
#
# ADOPT-FIRST: if AdGuard Home already exists (/opt/AdGuardHome/AdGuardHome),
# the package ONLY registers the pfSense service + menu entries, makes sure
# the official rc.d script exists and enables boot persistence via sysrc.
# A running AdGuard Home process is NEVER stopped, restarted or reconfigured,
# and AdGuardHome.yaml is NEVER written - all DNS/filtering config stays in
# AdGuard Home's own web UI.
#
# FRESH install (no existing binary): the bundled, checksum-verified release
# tarball is extracted to /opt/AdGuardHome and the service is started once.
# AdGuard Home then serves its own first-run wizard (http://<firewall>:3000)
# which finishes the configuration.

PKG_BASE="/usr/local/pfsense_adguardhome"
AGH_DIR="/opt/AdGuardHome"
AGH_BIN="${AGH_DIR}/AdGuardHome"
RCD="/usr/local/etc/rc.d/AdGuardHome"
RCD_SRC="${PKG_BASE}/share/AdGuardHome.rcd"

fresh_install() {
	echo "pfSense-pkg-adguardhome: no existing install in /opt, installing bundled AdGuard Home..."
	mkdir -p "$AGH_DIR"
	(cd "$PKG_BASE/share" && sha256 -c AdGuardHome_freebsd_amd64.tar.gz.sha256) || {
		echo "pfSense-pkg-adguardhome: checksum verification FAILED, aborting." >&2
		return 1
	}
	tar -xzf "$PKG_BASE/share/AdGuardHome_freebsd_amd64.tar.gz" -C /opt || return 1
	chmod 0755 "$AGH_BIN"
	return 0
}

ensure_rcd() {
	# Install AdGuard Home's official rc.d script only when it is missing.
	# An existing script (e.g. created by "AdGuardHome -s install") is never
	# replaced so AdGuard Home's own service management keeps working.
	if [ ! -f "$RCD" ]; then
		install -m 0755 "$RCD_SRC" "$RCD"
	fi
}

register_config() {
	php <<'PHP'
<?php
global $config;
require_once("/etc/inc/config.inc");
$config = parse_config(true);

$svc = array(
    'name' => 'AdGuardHome',
    'rcfile' => 'AdGuardHome',
    'description' => 'AdGuard Home DNS filter',
    'custom_php_service_status_command' =>
        '$p = @intval(@file_get_contents("/var/run/AdGuardHome.pid")); exec("/bin/ps -o stat= -p " . (($p > 0) ? $p : 0) . " 2>/dev/null", $o); $rc = (($p > 0) && (count($o) > 0));'
);
$menu = array(
    'name' => 'AdGuard Home',
    'tooltiptext' => 'AdGuard Home status and settings',
    'section' => 'Services',
    'url' => '/packages/pfsense_adguardhome/status.php'
);

$services = config_get_path('installedpackages/service', []);
$found = false;
foreach ($services as $k => $s) {
    if (isset($s['name']) && $s['name'] == $svc['name']) {
        $services[$k] = $svc;
        $found = true;
        break;
    }
}
if (!$found) {
    $services[] = $svc;
}
config_set_path('installedpackages/service', $services);

$menus = config_get_path('installedpackages/menu', []);
$found = false;
foreach ($menus as $k => $m) {
    if (isset($m['name']) && $m['name'] == $menu['name']) {
        $menus[$k] = $menu;
        $found = true;
        break;
    }
}
if (!$found) {
    $menus[] = $menu;
}
config_set_path('installedpackages/menu', $menus);

write_config("Installed pfSense-pkg-adguardhome: registered service and menu");
PHP
}

unregister_config() {
	php <<'PHP'
<?php
global $config;
require_once("/etc/inc/config.inc");
$config = parse_config(true);

$services = config_get_path('installedpackages/service', []);
$out = array();
foreach ($services as $s) {
    if (isset($s['name']) && $s['name'] == 'AdGuardHome') {
        continue;
    }
    $out[] = $s;
}
config_set_path('installedpackages/service', $out);

$menus = config_get_path('installedpackages/menu', []);
$out = array();
foreach ($menus as $m) {
    if (isset($m['name']) && $m['name'] == 'AdGuard Home') {
        continue;
    }
    $out[] = $m;
}
config_set_path('installedpackages/menu', $out);

write_config("Removed pfSense-pkg-adguardhome: unregistered service and menu");
PHP
}

case "$1" in
install)
	if [ ! -x "$AGH_BIN" ]; then
		fresh_install || exit 1
	fi
	ensure_rcd
	# Boot persistence (redundant with any existing earlyshellcmd start:
	# a second start attempt is a no-op thanks to the rc.d pidfile check).
	/usr/sbin/sysrc AdGuardHome_enable=YES >/dev/null 2>&1
	register_config
	# Start only when nothing is running. An already-running AdGuard Home
	# is adopted untouched - no stop, no restart, no config changes.
	if ! /usr/sbin/service AdGuardHome onestatus >/dev/null 2>&1; then
		/usr/sbin/service AdGuardHome onestart >/dev/null 2>&1
	fi
	echo "pfSense-pkg-adguardhome: install complete. AdGuard Home config lives in its own web UI."
	;;
deinstall)
	# Unregister the pfSense menu/service entries only. AdGuard Home itself,
	# its config and its rc.d script stay in place and keep running.
	unregister_config
	echo "pfSense-pkg-adguardhome: package removed; /opt/AdGuardHome and the AdGuardHome rc.d service were left untouched."
	;;
*)
	echo "Usage: $0 install|deinstall"
	exit 1
	;;
esac
