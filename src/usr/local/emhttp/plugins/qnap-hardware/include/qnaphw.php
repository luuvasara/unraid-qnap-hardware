<?php
/* qnaphw.php - shared by the pages, endpoints and scripts of qnap-hardware. READ-ONLY: everything comes from sysfs and
 * Unraid's state files through the plugin kit (kit/EnclosureView.php); no command is run and no drive is opened.
 * The cabling of each unit is the one confirmed with Locate, in this plugin or in QNAP Expansion (the kit reads both). */
require_once dirname(__DIR__) . '/kit/EnclosureView.php';

const QNAPHW = 'qnap-hardware';
const QNAPHW_DEFAULTS = ['MAIN_BADGES' => 'yes', 'HISTORY' => 'yes'];
const QNAPHW_HISTORY_LINES = 1440;   // one sample a minute: 24 h

function qnaphw_root(): string { return rtrim((string)(getenv('DRIVEMAP_ROOT') ?: ''), '/'); }
function qnaphw_cfgdir(): string { return qnaphw_root() . '/boot/config/plugins/' . QNAPHW; }
function qnaphw_run(): string { return (string)(getenv('QNAPHW_RUN') ?: (qnaphw_root() . '/var/run/' . QNAPHW)); }
function qnaphw_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* the cfg, whitelisted: a value that is not allowed falls back to the default */
function qnaphw_cfg(): array {
  $c = @parse_ini_file(qnaphw_cfgdir() . '/' . QNAPHW . '.cfg') ?: [];
  $out = QNAPHW_DEFAULTS;
  foreach (['MAIN_BADGES', 'HISTORY'] as $k) if (in_array($c[$k] ?? '', ['yes', 'no'], true)) $out[$k] = $c[$k];
  return $out;
}

/* the units with their bays, links and card facts; cached for this request */
function qnaphw_view(): array {
  static $v = null;
  if ($v !== null) return $v;
  $v = enclosure_view(['cabling_file' => qnaphw_cfgdir() . '/enclosures.json']);
  foreach ($v['units'] as &$u) $u['hw'] = enclosure_hw($u, $u['bays'], qnaphw_root());
  unset($u);
  return $v;
}

/* the history: one line per sample, "<epoch> <unit>.<bay>=<device>:<temp|s|->[,...]" (s = standby, - = no reading).
 * [['t' => epoch, 'v' => ['1.HDD1' => ['dev' => 'sdk', 'temp' => 36|null, 'standby' => bool]]], ...], oldest first */
function qnaphw_history(int $since = 0): array {
  $out = [];
  foreach (@file(qnaphw_run() . '/history', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
    $p = explode(' ', $l);
    if (!ctype_digit($p[0] ?? '') || (int)$p[0] < $since) continue;
    $r = ['t' => (int)$p[0], 'v' => []];
    foreach (array_slice($p, 1) as $kv) {
      if (!preg_match('/^(\d{1,2}\.[A-Za-z0-9]{1,8})=([a-z0-9]{1,16}):(\d{1,3}|s|-)$/', $kv, $m)) continue;   // anything else: a newer format, skipped
      $r['v'][$m[1]] = ['dev' => $m[2], 'temp' => ctype_digit($m[3]) ? (int)$m[3] : null, 'standby' => $m[3] === 's'];
    }
    $out[] = $r;
  }
  return $out;
}

/* one history line from the current view */
function qnaphw_sample_line(array $view, int $now): string {
  $parts = [];
  foreach ($view['units'] as $u) foreach ($u['bays'] as $b) {
    if ($b['device'] === '' || !preg_match('/^[a-z0-9]{1,16}$/', $b['device']) || !preg_match('/^[A-Za-z0-9]{1,8}$/', $b['bay'])) continue;
    $parts[] = "{$u['unit']}.{$b['bay']}={$b['device']}:" . ($b['temp'] !== null ? (int)$b['temp'] : ($b['standby'] ? 's' : '-'));
  }
  return $now . ($parts ? ' ' . implode(' ', $parts) : '');
}

/* when the last sample was taken, and how many there are: for the plugin health line */
function qnaphw_history_state(): array {
  $f = qnaphw_run() . '/history';
  $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
  $last = $lines ? (int)explode(' ', end($lines))[0] : 0;
  return ['samples' => count($lines), 'last' => $last];
}

/* SMART summary of a slot from Unraid's last report (/var/local/emhttp/smart/<slot>): power-on hours and the counters that
 * matter; [] when there is no report. Nothing is read from the drive. */
function qnaphw_smart_attrs(string $slot): array {
  if (!preg_match('/^[A-Za-z0-9_~-]{1,32}$/', $slot)) return [];
  $s = @file_get_contents(qnaphw_root() . "/var/local/emhttp/smart/$slot");
  if ($s === false) return [];
  $want = ['9' => 'hours', '5' => 'realloc', '197' => 'pending', '198' => 'offline_unc', '199' => 'crc', '193' => 'load_cycles'];
  $o = [];
  foreach (explode("\n", $s) as $l) {
    $c = preg_split('/\s+/', trim($l));
    if (count($c) >= 10 && isset($want[$c[0]]) && !isset($o[$want[$c[0]]]) && preg_match('/^\d+/', $c[9], $m)) $o[$want[$c[0]]] = (int)$m[0];
  }
  return $o;
}

/* the page head: kit and plugin styles and scripts, once per page (every tab renders into one DOM) */
function qnaphw_head(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  $d = dirname(__DIR__);
  $v = substr(md5((string)@md5_file("$d/kit/enclosures.js") . @md5_file("$d/kit/enclosures.css") . @md5_file("$d/sheets/qnaphw.css")), 0, 10);
  echo '<link rel="stylesheet" href="/plugins/qnap-hardware/kit/enclosures.css?v=', $v, '">',
       '<link rel="stylesheet" href="/plugins/qnap-hardware/sheets/qnaphw.css?v=', $v, '">';
}

function qnaphw_size(int $b): string { return enclosure_size($b); }
