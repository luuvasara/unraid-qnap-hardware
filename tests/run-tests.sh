#!/bin/bash
# shellcheck disable=SC2015,SC2016  # "check && ok || bad" is the test idiom; php -r code is single-quoted on purpose
# Offline test suite of qnap-hardware (build host only; touches no server). Unit tests of the logic, the report collector
# against the made-up server, the cron apply script with a stub update_cron, lint, and the render test.
set -u
HERE=$(cd "$(dirname "$0")/.." && pwd); T=$HERE/tests
fail=0; ok() { echo "ok   $*"; }; bad() { echo "FAIL $*"; fail=1; }
W=$T/.work; mkdir -p "$W"; K=$W/server
bash "$T/mkserver.sh" "$K" || { bad "fixture"; exit 1; }
P=$HERE/src/usr/local/emhttp/plugins/qnap-hardware
# the kit as the package lays it out, next to the plugin's files (removed again at the end: it is not part of src/)
rm -rf "$P/kit"; mkdir -p "$P/kit/enclosures"; cp "$HERE"/kit/runtime/drivemap/*.php "$P/kit/"; cp "$HERE"/kit/runtime/drivemap/enclosures/*.json "$P/kit/enclosures/"
trap 'rm -rf "$P/kit"' EXIT
out=$(php "$T/hw-test.php" "$K" 2>&1) && ok "logic: $(grep -c '^ok' <<<"$out") checks" || { bad "logic:"; grep -v '^ok' <<<"$out"; }
# the collector reads only what it is pointed at; its output anonymised has no serial of the fixture
R=$(QNAPHW_SYS=$K/sys QNAPHW_PROC=$K/proc QNAPHW_ETC=$K/etc QNAPHW_KERNEL=6.18.58-Unraid QNAPHW_BIN_LSPCI=$K/bin/lspci QNAPHW_BIN_LSUSB=$K/bin/lsusb QNAPHW_BIN_DMESG=$K/bin/dmesg bash "$P/scripts/qnaphw-report")
grep -q 'ASM2824 PCIe Gen3 Packet Switch' <<<"$R" && grep -q '0000:03:00.0 0x1b21:0x1164 sub 0x1baa:0xc021' <<<"$R" && grep -q 'cap=ee349f37' <<<"$R" && grep -q 'ata21 port_no=1 pci=0000:03:00.0 sata_spd=6.0 Gbps' <<<"$R" && ok "collector: card, ids, capabilities, SATA links" || bad "collector: $(head -c 600 <<<"$R")"
grep -qE 'ENC[0-9]{2}[0-9a-f]{5}' <<<"$R" && bad "collector printed a drive serial" || ok "collector reads no serial (the fixture's serials are not in its output)"
# the read-only promise of the collector: no write, no drive access command
if grep -v '^#' "$P/scripts/qnaphw-report" | grep -nE '\b(smartctl|hdparm|sg_[a-z]+|dd|setpci|tee|mknod)\b|>>? *"?[$/]' | grep -v '/dev/null'; then bad "collector: a command that could touch a device or write"; else ok "collector: no device command, no write"; fi
# qnaphw-apply: cron file written and removed, update_cron called (stubbed)
A=$W/apply; rm -rf "$A"; mkdir -p "$A/boot/config/plugins/qnap-hardware" "$A/bin"
printf '#!/bin/bash\necho called >>%q\n' "$A/update_cron.log" >"$A/bin/update_cron"; chmod 755 "$A/bin/update_cron"
sed -e "s#/boot/config#$A/boot/config#g" -e "s#/usr/local/sbin/update_cron#$A/bin/update_cron#" "$P/scripts/qnaphw-apply" >"$A/apply"
echo 'HISTORY="yes"' >"$A/boot/config/plugins/qnap-hardware/qnap-hardware.cfg"; bash "$A/apply"
grep -q '^\* \* \* \* \* /usr/local/emhttp/plugins/qnap-hardware/scripts/qnaphw-sample' "$A/boot/config/plugins/qnap-hardware/qnap-hardware.cron" && ok "apply: HISTORY=yes writes the cron line" || bad "apply yes"
echo 'HISTORY="no"' >"$A/boot/config/plugins/qnap-hardware/qnap-hardware.cfg"; bash "$A/apply"
[ ! -e "$A/boot/config/plugins/qnap-hardware/qnap-hardware.cron" ] && [ "$(wc -l <"$A/update_cron.log")" = 2 ] && ok "apply: HISTORY=no removes it; update_cron after each" || bad "apply no"
# the sampler: one line appended to the history of the made-up server
DRIVEMAP_ROOT=$K php "$P/scripts/qnaphw-sample" && [ "$(wc -l <"$K/var/run/qnap-hardware/history")" = 1440 ] && tail -1 "$K/var/run/qnap-hardware/history" | grep -qE '^[0-9]+ 1\.HDD9=sdn:42 ' && ok "sampler: one line, the oldest dropped at 24 h" || bad "sampler: $(tail -1 "$K/var/run/qnap-hardware/history" | head -c 200)"
for f in "$P"/scripts/*; do case $(head -1 "$f") in *php*) php -l "$f" >/dev/null ;; *) bash -n "$f" ;; esac || bad "syntax $f"; done
command -v shellcheck >/dev/null && { shellcheck -S warning "$P/scripts/qnaphw-report" "$P/scripts/qnaphw-apply" "$T/mkserver.sh" "$T/run-tests.sh" && ok "shellcheck" || bad "shellcheck"; }
for f in "$P"/js/*.js; do node --check "$f" || bad "js $f"; done; ok "scripts parse"
rm -rf "$P/kit"
bash "$T/render-test.sh" || bad "render test"
[ $fail = 0 ] && echo "ALL PASSED" || echo FAILED
exit $fail
