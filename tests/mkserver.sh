#!/bin/bash
# mkserver.sh <dir> - a made-up server with a TL-D1600S for the tests: the kit's enclosure fixture (PCI ids, links, cabling and
# contents of a measured unit, invented serials), Unraid's state files, a day of temperature history, /etc and /proc bits for
# the report, and stub lspci/lsusb/dmesg in <dir>/bin that print the card's lspci output as read on the unit.
set -euo pipefail
HERE=$(cd "$(dirname "$0")/.." && pwd); K=$1
rm -rf "$K"; mkdir -p "$K"
php "$HERE/kit/render/enclosure-fixture.php" "$K"
php "$HERE/kit/render/enclosure-state-fixture.php" "$K"
mkdir -p "$K/etc" "$K/proc/sys/kernel" "$K/bin" "$K/var/run/qnap-hardware" "$K/boot/config/plugins/qnap-hardware"
echo 'version="7.3.4"' >"$K/etc/unraid-version"; echo 'nas-example' >"$K/etc/hostname"
echo '6.18.58-Unraid' >"$K/proc/sys/kernel/osrelease"
printf 'processor\t: 0\nmodel name\t: Example CPU @ 3.00GHz\nprocessor\t: 1\nmodel name\t: Example CPU @ 3.00GHz\n' >"$K/proc/cpuinfo"
printf 'MemTotal:       32768000 kB\n' >"$K/proc/meminfo"
cp "$HERE/src/usr/local/emhttp/plugins/qnap-hardware/default/qnap-hardware.cfg" "$K/boot/config/plugins/qnap-hardware/"
F=$HERE/tests/fixtures/unit
printf '#!/bin/bash\ncase "$*" in *-t*) cat %q ;; *) cat %q ;; esac\n' "$F/lspci-tvnn.txt" "$F/lspci-nn.txt" >"$K/bin/lspci"
printf '#!/bin/bash\necho "Bus 001 Device 001: ID 1d6b:0002 Linux Foundation 2.0 root hub"\n' >"$K/bin/lsusb"
printf '#!/bin/bash\necho "[    1.234567] ahci 0000:03:00.0: AHCI vers 0001.0301, 32 command slots, 6 Gbps, SATA mode"\necho "[    2.345678] ata21: SATA link up 6.0 Gbps (SStatus 133 SControl 300)"\necho "[    2.400000] ata21.00: ATA-8: ST32000641AS, 0001, max UDMA/133"\n' >"$K/bin/dmesg"
chmod 755 "$K/bin/"*
# a day of history ending now: one line a minute, temperatures that drift; HDD2 (sdj) in standby for the last 6 hours
php -r '
$k = $argv[1]; $now = time(); $now -= $now % 60; $L = [];
$base = ["HDD1" => ["sdk", 36], "HDD2" => ["sdj", 38], "HDD4" => ["sde", 40], "HDD5" => ["sdp", 38], "HDD6" => ["sdo", 41], "HDD8" => ["sdf", 39],
         "HDD9" => ["sdn", 42], "HDD10" => ["sdm", 39], "HDD11" => ["sdl", 37], "HDD12" => ["sdi", 40], "SSD1" => ["sdh", 31], "SSD2" => ["sdg", 30], "SSD3" => ["sdd", 29]];
for ($i = 1439; $i >= 0; $i--) {
  $t = $now - 60 * $i; $p = [];
  $j = 0;
  foreach ($base as $bay => [$d, $c]) { $j++;
    $v = $bay === "HDD2" && $i < 360 ? "s" : (string)round($c + 2.5 * sin(($t / 3600 + $j) / 2) + ($i < 120 && $bay === "HDD9" ? 3 : 0));
    $p[] = "1.$bay=$d:$v"; }
  $L[] = $t . " " . implode(" ", $p);
}
file_put_contents("$k/var/run/qnap-hardware/history", implode("\n", $L) . "\n");' "$K"
