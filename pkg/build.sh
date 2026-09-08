#!/bin/sh
# Builds pfSense-pkg-adguardhome and pkg(8) repo metadata.
# Run ON a pfSense host (or matching FreeBSD box): sh pkg/build.sh
# Output: /tmp/pfsense-adguardhome-repo-out/{All/*.pkg, meta.*, digests.*}
set -eu

PKGDIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPO=$(dirname "$PKGDIR")
WORK=$(mktemp -d /tmp/pfsense-adguardhome-build.XXXXXX)
STAGE="$WORK/stage"
META="$WORK/meta"
OUT="/tmp/pfsense-adguardhome-repo-out"
rm -rf "$OUT"
mkdir -p "$STAGE" "$META" "$OUT"

PBASE="usr/local/pfsense_adguardhome"
WBASE="usr/local/www/packages/pfsense_adguardhome"
mkdir -p "$STAGE/$PBASE/sbin" "$STAGE/$PBASE/share" "$STAGE/$WBASE" \
	"$STAGE/usr/local/www/widgets/widgets" "$STAGE/usr/local/www/widgets/include"

install -m 0755 "$PKGDIR/files/usr-local-pfsense_adguardhome/sbin/setup.sh" \
	"$STAGE/$PBASE/sbin/setup.sh"
install -m 0644 "$PKGDIR/files/usr-local-pfsense_adguardhome/share/pfsense_adguardhome.xml" \
	"$STAGE/$PBASE/share/pfsense_adguardhome.xml"
install -m 0644 "$PKGDIR/files/usr-local-etc-rc.d-AdGuardHome" \
	"$STAGE/$PBASE/share/AdGuardHome.rcd"
install -m 0644 "$PKGDIR/files/usr-local-etc-rc.d-pfsense_adguardhome_monitor" \
	"$STAGE/$PBASE/share/pfsense_adguardhome_monitor.rcd"
install -m 0644 "$PKGDIR/files/usr-local-pfsense_adguardhome/share/agh_api.php" \
	"$STAGE/$PBASE/share/agh_api.php"
install -m 0755 "$PKGDIR/files/usr-local-pfsense_adguardhome/sbin/pfsense_adguardhome_monitor.sh" \
	"$STAGE/$PBASE/sbin/pfsense_adguardhome_monitor.sh"
install -m 0755 "$PKGDIR/files/usr-local-pfsense_adguardhome/sbin/agh_monitor.php" \
	"$STAGE/$PBASE/sbin/agh_monitor.php"
install -m 0644 "$PKGDIR/files/usr-local-www-widgets-widgets-adguardhome.widget.php" \
	"$STAGE/usr/local/www/widgets/widgets/adguardhome.widget.php"
install -m 0644 "$PKGDIR/files/usr-local-www-widgets-include-widget-adguardhome.inc" \
	"$STAGE/usr/local/www/widgets/include/widget-adguardhome.inc"
install -m 0644 "$PKGDIR/files/usr-local-pfsense_adguardhome/share/AdGuardHome_freebsd_amd64.tar.gz" \
	"$STAGE/$PBASE/share/AdGuardHome_freebsd_amd64.tar.gz"
install -m 0644 "$PKGDIR/files/usr-local-pfsense_adguardhome/share/AdGuardHome_freebsd_amd64.tar.gz.sha256" \
	"$STAGE/$PBASE/share/AdGuardHome_freebsd_amd64.tar.gz.sha256"
# Stage every www page automatically so new pages cannot be forgotten
for page in "$PKGDIR"/files/usr-local-www-packages-pfsense_adguardhome/*.php; do
	install -m 0644 "$page" "$STAGE/$WBASE/$(basename "$page")"
done

for page in "$STAGE/$WBASE"/*.php; do
	php -l "$page" >/dev/null
done
php -l "$STAGE/$PBASE/share/agh_api.php" >/dev/null
php -l "$STAGE/$PBASE/sbin/agh_monitor.php" >/dev/null
php -l "$STAGE/usr/local/www/widgets/widgets/adguardhome.widget.php" >/dev/null
sh -n "$STAGE/$PBASE/sbin/pfsense_adguardhome_monitor.sh"
sh -n "$STAGE/$PBASE/share/pfsense_adguardhome_monitor.rcd"

VERSION=$(sed -n 's/.*<version>\([^<]*\)<.*/\1/p' "$STAGE/$PBASE/share/pfsense_adguardhome.xml" | head -1)
ABI=$(pkg config abi)
NAME="pfSense-pkg-adguardhome"
ORIGIN="www/pfSense-pkg-adguardhome"

cat > "$META/+POST_INSTALL" <<'EOF'
#!/bin/sh
/usr/local/pfsense_adguardhome/sbin/setup.sh install
exit 0
EOF

cat > "$META/+PRE_DEINSTALL" <<'EOF'
#!/bin/sh
/usr/local/pfsense_adguardhome/sbin/setup.sh deinstall
exit 0
EOF

chmod 0755 "$META/+POST_INSTALL" "$META/+PRE_DEINSTALL"

MANIFEST=$(php -r '
$stage = $argv[1]; $meta = $argv[2]; $abi = $argv[3];
$version = $argv[4]; $name = $argv[5]; $origin = $argv[6];
$files = array(); $flatsize = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = ltrim(str_replace($stage, "", $f->getPathname()), "/");
    $files["/" . $rel] = hash_file("sha256", $f->getPathname());
    $flatsize += $f->getSize();
}
$dirs = array();
foreach (array(
    "usr/local/pfsense_adguardhome",
    "usr/local/pfsense_adguardhome/sbin",
    "usr/local/pfsense_adguardhome/share",
    "usr/local/www/packages/pfsense_adguardhome",
    "usr/local/www/widgets",
    "usr/local/www/widgets/widgets",
    "usr/local/www/widgets/include"
) as $d) {
    $dirs["/" . $d] = "y";
}
$manifest = array(
    "name" => $name,
    "origin" => $origin,
    "version" => $version,
    "comment" => "AdGuard Home manager package for pfSense",
    "desc" => "Adopt-first management wrapper for AdGuard Home: auto-installs the bundled release on fresh systems, registers the pfSense service and menu, adds status and settings pages. Never touches a working install or AdGuardHome.yaml.",
    "maintainer" => "kontakt@tmiland.com",
    "www" => "https://github.com/tmiland/pfsense-adguardhome",
    "abi" => $abi,
    "arch" => $abi,
    "prefix" => "/",
    "categories" => array("pfSense"),
    "licenses" => array("MIT"),
    "flatsize" => $flatsize,
    "deps" => (object) array(),
    "files" => (object) $files,
    "directories" => (object) $dirs,
    "scripts" => array(
        "post-install" => "+POST_INSTALL",
        "pre-deinstall" => "+PRE_DEINSTALL"
    )
);
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
' "$STAGE" "$META" "$ABI" "$VERSION" "$NAME" "$ORIGIN")

echo "$MANIFEST" > "$META/+MANIFEST"

pkg create -m "$META" -r "$STAGE" -o "$OUT" >/dev/null

PKG_FILE=$(find "$OUT" -name "*.pkg" | head -1)
# Flat repo layout: metadata at the repo root, packages in All/
# (pfSense pkg(8) fetches ${url}/meta.conf).
REPO_OUT="$OUT"
mkdir -p "$REPO_OUT/All"
mv "$PKG_FILE" "$REPO_OUT/All/"
(cd "$REPO_OUT" && pkg repo . >/dev/null)

echo "=== Build complete: $REPO_OUT"
find "$REPO_OUT" -type f | sort
rm -rf "$WORK"
