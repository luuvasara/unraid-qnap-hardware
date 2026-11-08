<?php
/* Offline tests of qnap-hardware's logic against the made-up server of tests/mkserver.sh. Run: php tests/hw-test.php <server dir> */
$K = $argv[1];
putenv("DRIVEMAP_ROOT=$K");
$P = __DIR__ . '/../src/usr/local/emhttp/plugins/qnap-hardware';
// the package layout: the kit next to the plugin's files
if (!is_dir("$P/kit")) { fwrite(STDERR, "stage the kit first (run-tests.sh does)\n"); exit(2); }
require "$P/include/issues.php";
require "$P/include/qnaphw-anon.php";
$fail = 0;
$is = function (string $what, $got, $want) use (&$fail) {
  if ($got === $want) echo "ok   $what\n"; else { echo "FAIL $what: got " . json_encode($got) . ', want ' . json_encode($want) . "\n"; $fail = 1; }
};
$v = qnaphw_view();
$u = $v['units'][0];
$is('one unit, the card and its controllers', [count($v['units']), $u['card'], count($u['hw']['controllers'])], [1, 'QXP-1600eS-A1164', 4]);
$is('cfg defaults and whitelist', qnaphw_cfg(), ['MAIN_BADGES' => 'yes', 'HISTORY' => 'yes']);
file_put_contents("$K/boot/config/plugins/qnap-hardware/qnap-hardware.cfg", "MAIN_BADGES=\"no\"\nHISTORY=\"\$(touch $K/PWNED)\"\n");
$is('cfg: a bad value falls back, nothing is run', [qnaphw_cfg(), file_exists("$K/PWNED")], [['MAIN_BADGES' => 'no', 'HISTORY' => 'yes'], false]);
copy("$P/default/qnap-hardware.cfg", "$K/boot/config/plugins/qnap-hardware/qnap-hardware.cfg");
// history
$line = qnaphw_sample_line($v, 1000);
$is('sample line: every drive of a bay, standby as s', [substr($line, 0, 5), strpos($line, '1.HDD2=sdj:s') !== false, strpos($line, '1.HDD11=sdl:37') !== false, substr_count($line, '='), strpos($line, 'SSD4')], ['1000 ', true, true, 13, false]);
$h = qnaphw_history();
$is('history: a day of samples parsed', [count($h), count($h[0]['v']), $h[count($h) - 1]['v']['1.HDD2']['standby']], [1440, 13, true]);
file_put_contents("$K/var/run/qnap-hardware/history", "x 1.HDD1=sdk:36\n2000 1.HDD1=sdk:36 1.HDD2=../etc:3 junk 9.HDD3=sdz:999x\n", FILE_APPEND);
$h2 = qnaphw_history();
$is('history: odd lines and fields are skipped', [count($h2), array_keys(end($h2)['v'])], [1441, ['1.HDD1']]);
$is('history: since', count(qnaphw_history(time() - 3600)) <= 61, true);
$st = qnaphw_history_state();
$is('history state', $st['samples'], 1442);
// SMART counters from Unraid's report
$is('SMART counters of a slot', qnaphw_smart_attrs('dev5'), ['realloc' => 0, 'pending' => 0, 'crc' => 14]);
$is('SMART: a slot name that is not one', qnaphw_smart_attrs('../../etc/passwd'), []);
// known issues
$iss = []; foreach (qnaphw_issues($v) as $i) $iss[$i['id']] = $i['state'];
$is('issues on the measured unit: cabling not confirmed, the rest fine', $iss, ['link-trained-down' => 'ok', 'pcie-errors' => 'ok', 'sata-link-slow' => 'ok', 'cabling-not-confirmed' => 'warn', 'cold-start-missing' => 'info', 'hot-swap' => 'info']);
file_put_contents("$K/sys/bus/pci/devices/0000:05:00.0/aer_dev_correctable", "TOTAL_ERR_COR 7\n");
file_put_contents("$K/sys/class/ata_link/link33/sata_spd", "3.0 Gbps\n");
$v2 = enclosure_view(['cabling_file' => "$K/none.json"]); foreach ($v2['units'] as &$x) $x['hw'] = enclosure_hw($x, $x['bays'], $K); unset($x);
$iss2 = []; foreach (qnaphw_issues($v2) as $i) $iss2[$i['id']] = [$i['state'], $i['status']];
$is('issues: AER errors and a slow SATA link are found', [$iss2['pcie-errors'], $iss2['sata-link-slow']], [['warn', 'QNAP TL-D1600S 1, 0000:05:00.0: 7 correctable'], ['warn', 'QNAP TL-D1600S 1, HDD8: 3.0 Gbps of 6.0 Gbps']]);
file_put_contents("$K/sys/bus/pci/devices/0000:05:00.0/aer_dev_correctable", "TOTAL_ERR_COR 0\n");
file_put_contents("$K/sys/class/ata_link/link33/sata_spd", "6.0 Gbps\n");
$fw = qnaphw_firmware($v);
$is('firmware: enclosure and card not readable, each drive\'s revision', [$fw[0]['value'], $fw[1]['value'], count($fw), $fw[2]['value']], ['not readable', 'not readable', 15, '0001']);
// the anonymiser: values of every kind planted, none survives
$known = qnaphw_anon_known(['disks_ini' => "$K/var/local/emhttp/disks.ini", 'devs_ini' => "$K/var/local/emhttp/devs.ini", 'block' => "$K/sys/block",
                            'usbdev' => "$K/none", 'dmi' => "$K/none", 'hostname' => "$K/etc/hostname", 'passwd' => "$K/none", 'bylabel' => "$K/none"]);
$serial = trim(file_get_contents("$K/sys/block/sdk/device/serial"));
$plant = "sdk serial $serial in /dev/disk/by-id/ata-WDC_WD30EFRX-68EUZN0_$serial\nhost nas-example up\nmac 00:11:22:33:44:55 ip 10.0.0.7 ip6 fe80::1ff:fe23:4567:890a\n"
       . "uuid 0a1b2c3d-1111-4000-8000-00000000000a LABEL=\"media disk\" wwn-0x5000c500a1b2c3d4\nSerial Number:    ZXCV1234\n";
[$t, $n] = qnaphw_anonymise($plant, $known);
$left = array_filter([$serial, 'nas-example', '00:11:22:33:44:55', '10.0.0.7', 'fe80::1ff:fe23:4567:890a', '0a1b2c3d-1111', 'media disk', '0x5000c500a1b2c3d4', 'ZXCV1234'], function ($x) use ($t) { return stripos($t, $x) !== false; });
$is('anonymiser: nothing planted survives', array_values($left), []);
$is('anonymiser: the same value, the same placeholder', substr_count($t, '<serial-1>'), 2);
exit($fail);
