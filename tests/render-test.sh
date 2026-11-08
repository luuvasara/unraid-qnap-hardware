#!/bin/bash
# shellcheck disable=SC2015
# The pages of the built package with Unraid's real stylesheets in headless Chrome, on the made-up server of mkserver.sh, in
# all four themes; the history and report endpoints; the Main page labels' endpoint. OUT=<dir> keeps the screenshots.
set -uo pipefail
HERE=$(cd "$(dirname "$0")/.." && pwd); NAME=qnap-hardware
OUT=${OUT:-$HERE/tests/.work/shots}; mkdir -p "$OUT"
# shellcheck disable=SC1091
. "$HERE/kit/render/render-lib.sh"
PLUGIN_ROOT=$HERE "$HERE/kit/render/fetch-webgui.sh" >/dev/null
W=$HERE/tests/.work/render; rm -rf "$W"; mkdir -p "$W"
fail=0; ok() { echo "ok   $*"; }; bad() { echo "FAIL $*"; fail=1; }
VER=$(sed -n 's/^ *<!ENTITY version *"\([^"]*\)">.*/\1/p' "$HERE/$NAME.plg")
K=$HERE/tests/.work/server-r; bash "$HERE/tests/mkserver.sh" "$K" || { bad fixture; exit 1; }
export DRIVEMAP_ROOT=$K QNAPHW_SYS=$K/sys QNAPHW_PROC=$K/proc QNAPHW_ETC=$K/etc QNAPHW_KERNEL=6.18.58-Unraid QNAPHW_BIN_LSPCI=$K/bin/lspci QNAPHW_BIN_LSUSB=$K/bin/lsusb QNAPHW_BIN_DMESG=$K/bin/dmesg
TABS="Overview=QnapHWOverview,History=QnapHWHistory,Hardware=QnapHWHardware,Report=QnapHWReport,About=QnapHWAbout"
for theme in black white gray azure; do
  web=$W/web-$theme; stage_web "$web" || exit 1
  port=$(free_port); start_harness "$web" "$port" "$theme" "$TABS"; echo "$port" >"$W/port-$theme"
  for t in 1:overview 2:history 3:hardware 4:report 5:about; do
    shot "http://127.0.0.1:$port/?tab=${t%%:*}" "$OUT/$NAME-${t#*:}-$theme.png"
  done
  dom "http://127.0.0.1:$port/?tab=1" "$W/dom-$theme.html"; D=$W/dom-$theme.html
  [ "$(grep -o 'class="enc-bay ' "$D" | wc -l)" = 16 ] && grep -q 'data-enc-unit="0000:01:00.0"' "$D" && ok "$theme: Overview: live front view, 16 bays" || bad "$theme: front view"
  grep -q '42 °C in HDD9' "$D" && grep -q '13 in 16 bays, 1 in standby' "$D" && grep -q 'HDD6: SMART error' "$D" && ok "$theme: Overview: unit card (drives, hottest, health)" || bad "$theme: unit card"
  grep -q 'Bay map of QNAP TL-D1600S 1' "$D" && grep -q 'name="MAIN_BADGES"' "$D" && grep -q 'name="HISTORY"' "$D" && ok "$theme: Overview: bay map and settings" || bad "$theme: bay map/settings"
  grep -q 'samples kept' "$D" && ok "$theme: Overview: plugin health" || bad "$theme: health"
  grep -q 'QNAP TL-D1600S 1: drives' "$D" && grep -q 'controller 1 port 1 (ata21)' "$D" && grep -q 'ee349f37' "$D" && ok "$theme: Hardware: inventory" || bad "$theme: inventory"
  grep -q 'Cabling not confirmed' "$D" && grep -q 'Drives missing after a cold start' "$D" && grep -q 'Enclosure firmware' "$D" && ok "$theme: Hardware: known issues and firmware" || bad "$theme: issues"
  grep -q 'no way to reach them' "$D" && ok "$theme: Hardware: fans and LEDs said plainly" || bad "$theme: fans text"
  grep -q 'Report unsupported hardware' "$D" && grep -q "$VER" "$D" && grep -q 'QNAP Hardware is by the QNAP Hardware project' "$D" && ok "$theme: Report and About" || bad "$theme: report/about"
  if grep -E "PHP (Warning|Fatal|Parse|Notice|Deprecated)" "$web.log" | head -5; then bad "$theme: PHP errors"; fi
done
port=$(cat "$W/port-black"); E=http://127.0.0.1:$port/plugins/$NAME
[ "$(curl -s -o /dev/null -w '%{http_code}' "$E/include/history.php")" = 405 ] && [ "$(curl -s -o /dev/null -w '%{http_code}' "$E/include/report.php")" = 405 ] && ok "endpoints: GET refused" || bad "GET"
H=$(curl -s -d action=history -d range=3600 "$E/include/history.php")
php -r '$j = json_decode($argv[1], true); exit($j["ok"] && count($j["units"]) === 1 && count($j["units"][0]["series"]) === 13 && count($j["units"][0]["t"]) >= 59 && $j["units"][0]["series"][1]["label"] === "HDD2 (sdj)" ? 0 : 1);' "$H" && ok "history endpoint: 1 h, 13 bays" || bad "history: $(head -c 300 <<<"$H")"
Rj=$(curl -s -d action=collect "$E/include/report.php")
php -r '$j = json_decode($argv[1], true); $t = $j["text"] ?? ""; exit($j["ok"] && strpos($t, "### plugin view (qnap-hardware)") !== false && strpos($t, "bay HDD11: sdl") !== false && strpos($t, "nas-example") === false && !preg_match("/ENC\\d\\d[0-9a-f]{5}/", $t) ? 0 : 1);' "$Rj" && ok "report endpoint: collected, plugin view, no hostname or serial" || bad "report: $(head -c 400 <<<"$Rj")"
curl -s -d action=bays "$E/kit/enclosures.php" | grep -q '"sdl":{"bay":"HDD11"' && ok "Main page labels endpoint" || bad "bays endpoint"
curl -s -d action=view "$E/kit/enclosures.php" | grep -q '"HDD2":{"device":"sdj","slot":"Dev 2","temp":"standby"' && ok "live view endpoint" || bad "view endpoint"
[ -f "$W/web-black/plugins/$NAME/QnapHWBadges.page" ] && ok "Main page labels page in the package" || bad "badges page"

render_cleanup
[ $fail = 0 ] && echo "ALL PASSED" || echo FAILED
exit $fail
