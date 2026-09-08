#!/bin/sh
# AdGuard Home status monitor loop. Runs agh_monitor.php (one-shot check)
# every $1 seconds. Started under daemon(8) by the package rc.d script.

interval=${1:-300}
case "$interval" in
	''|*[!0-9]*) interval=300 ;;
esac

PHP=/usr/local/bin/php
[ -x "$PHP" ] || PHP=$(command -v php)

while :; do
	"$PHP" /usr/local/pfsense_adguardhome/sbin/agh_monitor.php >/dev/null 2>&1
	sleep "$interval"
done
