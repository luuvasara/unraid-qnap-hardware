<?php
/* issues.php - known issues and firmware (Hardware tab and the report). The data ships with the plugin (data/known-issues.json);
 * each issue with a check is evaluated against the live view (qnaphw_view()), the others are shown as information.
 * READ-ONLY. qnaphw_issues() -> [['title', 'state' => ok|warn|info, 'status' => text, 'text', 'source'], ...] */
require_once __DIR__ . '/qnaphw.php';

function qnaphw_issue_data(): array {
  $j = json_decode((string)@file_get_contents(dirname(__DIR__) . '/data/known-issues.json'), true);
  return is_array($j) ? $j + ['issues' => [], 'firmware' => []] : ['issues' => [], 'firmware' => []];
}

function qnaphw_issues(?array $view = null): array {
  $v = $view ?? qnaphw_view();
  $out = [];
  foreach (qnaphw_issue_data()['issues'] as $i) {
    $hits = [];
    foreach ($v['units'] as $u) {
      $n = "{$u['name']} {$u['unit']}";
      switch ($i['check'] ?? '') {
        case 'links':
          foreach ($u['links'] as $l) if ($l['state'] !== 'ok' && $l['state'] !== 'unknown') $hits[] = "$n, {$l['name']}: " . enclosure_link_text($l['speed'], $l['width']) . ' of ' . enclosure_link_text($l['want_speed'], $l['want_width']);
          break;
        case 'aer':
          foreach (array_merge([['address' => $u['switch'], 'aer' => $u['hw']['switch_aer']]], $u['hw']['controllers']) as $c)
            foreach ($c['aer'] as $k => $x) if ($x) $hits[] = "$n, {$c['address']}: $x $k";
          break;
        case 'sata':
          foreach ($u['hw']['controllers'] as $c) foreach ($c['ports'] as $p)
            if ($p['device'] !== '' && $p['limit'] !== '' && $p['speed'] !== $p['limit']) $hits[] = "$n, " . ($p['bay'] ?: $p['device']) . ": {$p['speed']} of {$p['limit']}";
          break;
        case 'cabling':
          if ($u['cabling'] && $u['ports_known'] && !$u['confirmed']) $hits[] = $n;
          break;
      }
    }
    $check = ($i['check'] ?? 'info') !== 'info';
    $state = !$check ? 'info' : ($hits ? 'warn' : 'ok');
    $status = !$check ? 'Information' : ($hits ? implode('; ', $hits) : ($v['units'] ? 'Not seen on this server' : 'No enclosure to check'));
    $out[] = ['id' => (string)$i['id'], 'title' => (string)$i['title'], 'state' => $state, 'status' => $status, 'text' => (string)$i['text'], 'source' => (string)($i['source'] ?? '')];
  }
  return $out;
}

/* firmware: the parts with what can and cannot be read; drive firmware from the controllers' ports */
function qnaphw_firmware(?array $view = null): array {
  $v = $view ?? qnaphw_view();
  $rows = array_map(function ($f) { return ['part' => (string)$f['part'], 'value' => 'not readable', 'text' => (string)$f['text'], 'source' => (string)($f['source'] ?? '')]; }, qnaphw_issue_data()['firmware']);
  foreach ($v['units'] as $u) foreach ($u['hw']['controllers'] as $c) foreach ($c['ports'] as $p) if ($p['device'] !== '') {
    $model = ''; foreach ($u['bays'] as $b) if ($b['device'] === $p['device']) $model = $b['model'];
    $rows[] = ['part' => "Drive in {$p['bay']} ({$p['device']})", 'value' => $p['rev'] !== '' ? $p['rev'] : 'unknown', 'text' => $model, 'source' => 'sysfs (the drive\'s identify data, read by the kernel at boot)'];
  }
  return $rows;
}
