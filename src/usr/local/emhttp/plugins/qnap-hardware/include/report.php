<?php
/* report.php - the Report tab of qnap-hardware. POST only (Unraid's prologue checks the token; a GET gets 405). action=collect
 * runs scripts/qnaphw-report (fixed path, no argument, nothing from the request reaches it), adds the plugin's own view (units,
 * bays, links, known issues), anonymises the whole text (include/qnaphw-anon.php) and returns only the anonymised text.
 * Nothing is written or sent anywhere; the raw text exists only in this request's memory. */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/issues.php';
require_once __DIR__ . '/qnaphw-anon.php';

function qnaphw_report_view(): string {
  $v = qnaphw_view();
  $o = "\n### plugin view (qnap-hardware)\n";
  if (!$v['units']) $o .= "no enclosure found\n";
  foreach ($v['candidates'] as $c) $o .= "candidate controller {$c['address']} {$c['id']} subsystem {$c['subsystem']} (profile {$c['profile']}, layout differs)\n";
  foreach ($v['units'] as $u) {
    $o .= "unit {$u['name']} {$u['unit']}: card {$u['card']} switch {$u['switch']} subsystem {$u['subsystem']} controllers " . implode(',', $u['controllers'])
        . ' cabling ' . ($u['cabling'] ? implode(',', $u['cabling']) : '-') . ($u['confirmed'] ? ' (confirmed)' : ' (not confirmed)') . "\n";
    foreach ($u['links'] as $l) $o .= "link {$l['name']} {$l['to']}: " . enclosure_link_text($l['speed'], $l['width']) . ' of ' . enclosure_link_text($l['want_speed'], $l['want_width']) . " {$l['state']}\n";
    foreach ($u['bays'] as $b) $o .= "bay {$b['bay']}: " . ($b['device'] === '' ? 'empty' : "{$b['device']} {$b['model']} | {$b['slot']} | " . enclosure_temp_text($b) . " | {$b['state']} | SMART {$b['smart']['text']}") . "\n";
    foreach ($u['unplaced_rows'] as $r) $o .= "on the card, no bay: {$r['device']} {$r['model']} ({$r['label']})\n";
  }
  foreach (qnaphw_issues($v) as $i) $o .= "issue {$i['id']}: {$i['state']} | {$i['status']}\n";
  return $o;
}

try {
  if (($_POST['action'] ?? '') !== 'collect') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'unknown action']); exit; }
  $script = dirname(__DIR__) . '/scripts/qnaphw-report';
  $raw = (string)shell_exec('timeout 90 /bin/bash ' . escapeshellarg($script) . ' 2>&1') . qnaphw_report_view();
  $r = qnaphw_root();
  $known = qnaphw_anon_known(['disks_ini' => "$r/var/local/emhttp/disks.ini", 'devs_ini' => "$r/var/local/emhttp/devs.ini", 'block' => "$r/sys/block",
                              'usbdev' => "$r/sys/bus/usb/devices", 'dmi' => "$r/sys/class/dmi/id", 'hostname' => "$r/etc/hostname", 'passwd' => "$r/etc/passwd",
                              'bylabel' => "$r/dev/disk/by-label"]);
  [$text, $n] = qnaphw_anonymise($raw, $known);
  if (function_exists('mb_convert_encoding')) $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
  $u = qnaphw_view()['units'];
  echo json_encode(['ok' => true, 'text' => $text, 'replaced' => $n, 'model' => $u ? $u[0]['name'] : 'no enclosure found',
                    'version' => ltrim((string)strtok((string)@file_get_contents(dirname(__DIR__) . '/kit/changes.txt'), "\n"), '#')], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'report failed']);
}
