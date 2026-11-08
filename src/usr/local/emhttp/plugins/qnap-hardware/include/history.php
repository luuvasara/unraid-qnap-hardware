<?php
/* history.php - the History tab's data. POST only (Unraid's prologue checks the token; a GET gets 405). READ-ONLY: reads the
 * sampler's RAM file. action=history&range=3600|21600|86400 ->
 * {"ok":true,"now":epoch,"units":[{"unit":1,"name":"QNAP TL-D1600S 1","t":[epoch,...],"series":[{"key":"1.HDD1","label":"HDD1 sdk","v":[36,null,...],"standby":[0,1,...]}]}]} */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/qnaphw.php';

function qnaphw_history_answer(int $range, int $now): array {
  $rows = qnaphw_history($now - $range);
  $names = [];
  foreach (qnaphw_view()['units'] as $u) $names[$u['unit']] = "{$u['name']} {$u['unit']}";
  $units = [];
  foreach ($rows as $i => $r) foreach ($r['v'] as $key => $x) { [$un] = explode('.', $key, 2); $units[(int)$un][$key] = true; }
  $out = [];
  ksort($units);
  foreach ($units as $un => $keys) {
    $keys = array_keys($keys);
    usort($keys, function ($a, $b) { return strnatcmp($a, $b); });
    $t = []; $series = [];
    foreach ($keys as $k) $series[$k] = ['key' => $k, 'label' => explode('.', $k, 2)[1], 'devs' => [], 'v' => [], 'standby' => []];
    foreach ($rows as $r) {
      $t[] = $r['t'];
      foreach ($keys as $k) {
        $x = $r['v'][$k] ?? null;
        $series[$k]['v'][] = $x['temp'] ?? null;
        $series[$k]['standby'][] = $x && $x['standby'] ? 1 : 0;
        if ($x) $series[$k]['devs'][$x['dev']] = true;
      }
    }
    foreach ($series as &$s) { $s['label'] .= ' (' . implode(', ', array_keys($s['devs'])) . ')'; unset($s['devs']); }
    unset($s);
    $out[] = ['unit' => $un, 'name' => $names[$un] ?? "Enclosure $un", 't' => $t, 'series' => array_values($series)];
  }
  return ['ok' => true, 'now' => $now, 'range' => $range, 'units' => $out];
}

try {
  if (($_POST['action'] ?? '') !== 'history') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'unknown action']); exit; }
  $range = (int)($_POST['range'] ?? 86400);
  if (!in_array($range, [3600, 21600, 86400], true)) $range = 86400;
  echo json_encode(qnaphw_history_answer($range, time()), JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'history failed']);
}
