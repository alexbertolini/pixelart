<?php
/**
 * Pixel Art – PHP page with a locally rendered (JavaScript) canvas.
 *
 * Run with:   php -S localhost:8000 index.php
 *
 * All drawing happens instantly in the browser. The artwork, resolution, selected
 * colour and option toggles are saved to the PHP session in the background
 * (debounced fetch() POST to ?api=save) and are restored on reload.
 *
 * Two user levels:
 *   - everyone can draw;
 *   - the "boss" (unlocked with the boss code) sees the option toggles and a Save
 *     button that stores the image's pixel code in MySQL and returns a 6-digit id.
 *
 * Settings (MySQL connection, table name, boss code) live in ww_config.php next to this
 * file. The table is created automatically on the first save.
 */
declare(strict_types=1);
session_start();

const MIN_SIZE = 4;
const MAX_SIZE = 100;

/* 25 common colours (index 0..24). "No colour" is -1 in the browser / null in the session. */
$PALETTE = [
    ['Black', '#000000'], ['Dark gray', '#595959'], ['Gray', '#a6a6a6'], ['Light gray', '#d9d9d9'], ['White', '#ffffff'],
    ['Red', '#ff0000'], ['Dark red', '#8b0000'], ['Orange', '#ff8000'], ['Yellow', '#ffd800'], ['Lime', '#80ff00'],
    ['Green', '#00a650'], ['Dark green', '#006400'], ['Teal', '#008080'], ['Cyan', '#00ffff'], ['Sky blue', '#87ceeb'],
    ['Blue', '#0000ff'], ['Navy', '#000080'], ['Violet', '#7f00ff'], ['Purple', '#800080'], ['Magenta', '#ff00ff'],
    ['Pink', '#ff8fb8'], ['Brown', '#8b4513'], ['Tan', '#d2b48c'], ['Peach', '#ffcba4'], ['Salmon', '#fa8072'],
];

/*
 * Session state. Pixels are stored as a string, one character per pixel
 * (row by row): '-' = no colour, 'a'..'y' = palette index 0..24.
 */
if (!isset($_SESSION['s']) || !is_string($_SESSION['s']['px'] ?? null)) {
    $_SESSION['s'] = [
        'w' => 32, 'h' => 32,
        'px' => str_repeat('-', 32 * 32),
        'color' => 0,
        'grid' => true,
        'labels' => false,
        'hl' => false,
        'coding' => false,
    ];
}

/* ---------- configuration / helpers ---------- */
/** Read a setting from ww_config.php (falls back to the defaults below if the file or a key is missing). */
function conf(string $key)
{
    static $c = null;
    if ($c === null) {
        $c = [
            'db_host' => '127.0.0.1', 'db_port' => 3306, 'db_name' => 'pixelart',
            'db_user' => 'root', 'db_pass' => '', 'db_charset' => 'utf8mb4',
            'db_table' => 'pixel_art', 'boss_code' => 'changeme',
        ];
        $file = __DIR__ . '/ww_config.php';
        if (is_file($file)) {
            $loaded = require $file;
            if (is_array($loaded)) $c = array_replace($c, $loaded);
        }
    }
    return $c[$key];
}

function out(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

/** Is $d a well-formed editor state? */
function validState($d, int $nColors): bool
{
    return is_array($d)
        && isset($d['w'], $d['h'], $d['px'])
        && is_int($d['w']) && is_int($d['h'])
        && $d['w'] >= MIN_SIZE && $d['w'] <= MAX_SIZE
        && $d['h'] >= MIN_SIZE && $d['h'] <= MAX_SIZE
        && is_string($d['px']) && strlen($d['px']) === $d['w'] * $d['h']
        && preg_match('/^[a-y-]+$/', $d['px']) === 1
        && ($d['color'] === null || (is_int($d['color']) && $d['color'] >= 0 && $d['color'] < $nColors));
}

/** Write a validated state into the session. Option toggles are only accepted from the boss. */
function storeState(array $d, bool $boss): void
{
    $o = $_SESSION['s'];
    if ($boss && !empty($d['coding'])) unset($_SESSION['game']);   // live pixel coding replaces the game's code
    $_SESSION['s'] = [
        'w' => $d['w'], 'h' => $d['h'], 'px' => $d['px'], 'color' => $d['color'],
        'grid'   => $boss ? !empty($d['grid'])   : $o['grid'],
        'labels' => $boss ? !empty($d['labels']) : $o['labels'],
        'hl'     => $boss ? !empty($d['hl'])     : $o['hl'],
        'coding' => $boss ? !empty($d['coding']) : ($o['coding'] ?? false),
    ];
}

/**
 * The pixel code: one line per row, "<count><colour>" runs separated by spaces, e.g. "3a 5- 24f".
 * Colour is 'a'..'y' (palette index 0..24) or '-' for no colour. Every row covers all columns;
 * blank rows at the bottom are omitted.
 */
function pixelCode(string $px, int $w, int $h): string
{
    $last = -1;
    for ($y = $h - 1; $y >= 0; $y--) {
        if (strspn(substr($px, $y * $w, $w), '-') < $w) { $last = $y; break; }
    }
    $lines = [];
    for ($y = 0; $y <= $last; $y++) {
        $row = substr($px, $y * $w, $w);
        $runs = [];
        for ($x = 0; $x < $w;) {
            $c = $row[$x];
            $n = 1;
            while ($x + $n < $w && $row[$x + $n] === $c) $n++;
            $runs[] = $n . $c;
            $x += $n;
        }
        $lines[] = implode(' ', $runs);
    }
    return implode("\n", $lines);
}

/** Connect to MySQL (settings from ww_config.php), make sure the table exists. Returns [PDO, table name]. */
function dbConnect(): array
{
    $table = (string)conf('db_table');
    if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
        throw new RuntimeException('Invalid db_table in ww_config.php');
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        conf('db_host'), (int)conf('db_port'), conf('db_name'), conf('db_charset')
    );
    $pdo = new PDO($dsn, (string)conf('db_user'), (string)conf('db_pass'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS `$table` (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        width TINYINT UNSIGNED NOT NULL,
        height TINYINT UNSIGNED NOT NULL,
        pixel_code MEDIUMTEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return [$pdo, $table];
}

/** Insert the code into MySQL under a fresh random six-digit id and return that id. */
function savePixelCode(int $w, int $h, string $code): int
{
    [$pdo, $table] = dbConnect();
    $st = $pdo->prepare("INSERT INTO `$table` (id, width, height, pixel_code) VALUES (?, ?, ?, ?)");
    for ($i = 0; $i < 20; $i++) {
        $id = random_int(100000, 999999);
        try {
            $st->execute([$id, $w, $h, $code]);
            return $id;
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;     // 1062 = duplicate id: try another
        }
    }
    throw new RuntimeException('Could not find a free id');
}

/** Fetch a stored picture by its six-digit id, or null if there is none. */
function lookupPixelCode(int $id): ?array
{
    [$pdo, $table] = dbConnect();
    $st = $pdo->prepare("SELECT width, height, pixel_code FROM `$table` WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? ['w' => (int)$r['width'], 'h' => (int)$r['height'], 'code' => (string)$r['pixel_code']] : null;
}

/** Is $code a well-formed pixel code for a $w x $h picture? (every line must cover exactly $w columns) */
function validCode(string $code, int $w, int $h): bool
{
    if ($code === '') return true;                                   // a completely blank picture
    $lines = explode("\n", $code);
    if (count($lines) > $h) return false;
    foreach ($lines as $line) {
        if (preg_match('/^\d+[a-y-]( \d+[a-y-])*$/', $line) !== 1) return false;
        preg_match_all('/(\d+)[a-y-]/', $line, $m);
        if (array_sum(array_map('intval', $m[1])) !== $w) return false;
    }
    return true;
}

/* ---------- JSON endpoints (POST ?api=...) ---------- */
$api = $_GET['api'] ?? '';
if ($api !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $d = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($d)) out(400, ['ok' => false]);
    $boss = !empty($_SESSION['boss']);
    $nColors = count($PALETTE);

    switch ($api) {
        case 'auth':                                                 // unlock the boss panel
            $f = $_SESSION['auth_fail'] ?? ['n' => 0, 't' => 0];
            if ($f['n'] >= 5 && time() - $f['t'] < 60) out(429, ['ok' => false]);
            if ($f['n'] >= 5) $f = ['n' => 0, 't' => 0];
            $code = $d['code'] ?? null;
            if (is_string($code) && hash_equals((string)conf('boss_code'), $code)) {
                session_regenerate_id(true);
                $_SESSION['boss'] = true;
                unset($_SESSION['auth_fail']);
                out(200, ['ok' => true]);
            }
            $f['n']++;
            $f['t'] = time();
            $_SESSION['auth_fail'] = $f;
            sleep(1);
            out(403, ['ok' => false]);
            break;

        case 'save':                                                 // background autosave (and "lock")
            if (!validState($d, $nColors)) out(400, ['ok' => false]);
            storeState($d, $boss);
            if ($boss && !empty($d['lock'])) unset($_SESSION['boss']);
            out(200, ['ok' => true]);
            break;

        case 'load':                                                 // load a picture's code as the game (canvas stays blank)
            $f = $_SESSION['load_fail'] ?? ['n' => 0, 't' => 0];
            if ($f['n'] >= 10 && time() - $f['t'] < 60) out(429, ['ok' => false]);
            if ($f['n'] >= 10) $f = ['n' => 0, 't' => 0];
            $id = $d['id'] ?? null;
            if (!is_int($id) || $id < 100000 || $id > 999999) out(400, ['ok' => false]);
            try {
                $rec = lookupPixelCode($id);
            } catch (Throwable $e) {
                error_log('pixel art load failed: ' . $e->getMessage());
                out(500, ['ok' => false, 'error' => 'database']);
            }
            if ($rec === null) {
                $f['n']++;
                $f['t'] = time();
                $_SESSION['load_fail'] = $f;
                out(404, ['ok' => false]);
            }
            if (!validCode($rec['code'], $rec['w'], $rec['h'])) out(500, ['ok' => false, 'error' => 'corrupt']);
            unset($_SESSION['load_fail']);
            $o = $_SESSION['s'];
            $_SESSION['s'] = [                                      // blank canvas at the picture's resolution
                'w' => $rec['w'], 'h' => $rec['h'], 'px' => str_repeat('-', $rec['w'] * $rec['h']),
                'color' => $o['color'], 'grid' => $o['grid'], 'labels' => $o['labels'], 'hl' => $o['hl'],
                'coding' => false,                                  // live coding would clash with the game's code
            ];
            $_SESSION['game'] = ['id' => $id, 'code' => $rec['code'], 'w' => $rec['w'], 'h' => $rec['h']];
            out(200, ['ok' => true, 'id' => $id, 'w' => $rec['w'], 'h' => $rec['h'], 'code' => $rec['code']]);
            break;

        case 'boss-save':                                            // store the pixel code in MySQL
            if (!$boss) out(403, ['ok' => false]);
            if (!validState($d, $nColors)) out(400, ['ok' => false]);
            storeState($d, true);
            try {
                $id = savePixelCode($d['w'], $d['h'], pixelCode($d['px'], $d['w'], $d['h']));
            } catch (Throwable $e) {
                error_log('pixel art save failed: ' . $e->getMessage());
                out(500, ['ok' => false, 'error' => 'database']);
            }
            out(200, ['ok' => true, 'id' => $id]);
            break;

        default:
            out(404, ['ok' => false]);
    }
}

$isBoss = !empty($_SESSION['boss']);
header('Cache-Control: no-store');
$S = $_SESSION['s'];
$S['coding'] = $S['coding'] ?? false;
$game = $_SESSION['game'] ?? null;
if ($game && $S['coding']) { unset($_SESSION['game']); $game = null; }   // never both at once
function onoff(bool $b): string { return $b ? 'ON' : 'OFF'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Pixel Art</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root { --chk1: #d4d4d4; --chk2: #a6a6a6; --hl: #ff2d7a; }
* { box-sizing: border-box; }
[hidden] { display: none !important; }
body { margin: 0; font: 14px/1.4 system-ui, sans-serif; background: #2b2d31; color: #e6e6e6; }
header { padding: 10px 20px; background: #1e1f22; font-weight: 600; letter-spacing: .5px; display: flex; justify-content: space-between; }
#status { font-weight: 400; font-size: 12px; color: #8a8; letter-spacing: 0; }
#status.busy { color: #cc8; } #status.err { color: #e66; }
main { display: flex; gap: 24px; padding: 20px; align-items: flex-start; }
.stage { flex: 1 1 auto; overflow: auto; min-width: 0; display: flex; gap: 16px; align-items: flex-start; }
#wrap { flex: none; }
.coding { flex: 0 1 auto; width: max-content; min-width: 160px; background: #383a40; border-radius: 8px; padding: 10px; }
.coding[hidden] { display: none; }
.coding h2 { margin: 0 0 8px; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: #aab; }
#codelines { overflow: auto; scrollbar-gutter: stable; font: 12px/1.3 ui-monospace, Menlo, Consolas, monospace; }
.cl { display: flex; flex-wrap: nowrap; white-space: nowrap; width: max-content; min-width: 100%; align-items: center; gap: 2px 8px; padding: 2px 0; border-bottom: 1px solid rgba(255,255,255,.06); }
.cl[hidden] { display: none; }
.cl.hot { background: rgba(255, 45, 122, .35); box-shadow: inset 0 0 0 1px var(--hl); }
.cl.hot .cn { color: #fff; }
.cn { flex: none; width: 26px; text-align: right; color: #778; margin-right: 2px; }
.run { display: inline-flex; flex: none; align-items: center; gap: 3px; }
.run i { display: inline-block; width: 12px; height: 12px; border: 1px solid #1e1f22; border-radius: 2px; }
.run i.nc { background: repeating-conic-gradient(var(--chk1) 0 25%, var(--chk2) 0 50%) 0 0 / 6px 6px; }
.codehint { display: block; width: 0; min-width: 100%; margin: 8px 0 0; }   /* wraps to the panel width instead of widening it */
#wrap { position: relative; }
#base, #over { display: block; }
#base { cursor: crosshair; touch-action: none; }
#over { position: absolute; left: 0; top: 0; pointer-events: none; }
aside { flex: 0 0 280px; width: 280px; position: sticky; top: 12px; display: flex; flex-direction: column; gap: 14px; }
.box { background: #383a40; border-radius: 8px; padding: 12px; }
.box h2 { margin: 0 0 8px; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: #aab; }

.gbar { position: relative; height: 28px; background: #1e1f22; border-radius: 6px; overflow: hidden; }
.gfill { height: 100%; width: 0; background: #5865f2; transition: width .15s; }
.gbar span { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,.6); }
#gauge.done .gfill { background: #2e8b57; }
#gauge.done .msg { color: #8c8; }

.palette { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; }
.sw { aspect-ratio: 1; border: 2px solid #1e1f22; border-radius: 4px; cursor: pointer; padding: 0; }
.sw.sel { outline: 3px solid #fff; outline-offset: 1px; }
.sw.none { background: linear-gradient(135deg, transparent 45%, #e33 45%, #e33 55%, transparent 55%),
  repeating-conic-gradient(var(--chk1) 0 25%, var(--chk2) 0 50%) 0 0 / 10px 10px; }
.current { margin-top: 8px; font-size: 12px; color: #aab; }
.row { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; }
.row label { flex: 1; }
input[type=number] { width: 70px; padding: 4px 6px; border-radius: 4px; border: 1px solid #1e1f22; background: #1e1f22; color: #fff; }
.btn { width: 100%; padding: 7px 10px; border: 0; border-radius: 6px; background: #5865f2; color: #fff; cursor: pointer; font: inherit; }
.btn.t { display: flex; justify-content: space-between; background: #4a4d55; margin-bottom: 6px; }
.btn.t b { color: #888; } .btn.t.on b { color: #6f6; }
.btn.ghost { background: #4a4d55; margin-top: 6px; }
small { color: #99a; }
input[type=password], input[type=text] { flex: 1; min-width: 0; padding: 6px 8px; border-radius: 4px; border: 1px solid #1e1f22; background: #1e1f22; color: #fff; font: inherit; }
.btn.inline { width: auto; }
.btn.save { background: #2e8b57; margin-top: 10px; }
.msg { margin-top: 6px; min-height: 1.2em; font-size: 12px; color: #aab; }
.msg.err { color: #e66; } .msg.ok { color: #8c8; }
.msg b { color: #fff; font-size: 15px; letter-spacing: 1px; }
</style>
</head>
<body>
<header><span>🎨 Pixel Art</span><span id="status">Saved</span></header>
<main>
  <section class="stage">
    <div id="wrap">
      <canvas id="base"></canvas>
      <canvas id="over"></canvas>
    </div>
    <div class="coding" id="coding" hidden>
      <h2 id="codeTitle">Pixel coding</h2>
      <div id="codelines"></div>
      <small class="codehint" id="codeHint"></small>
    </div>
  </section>

  <aside>
    <div class="box" id="gauge" hidden>
      <h2>Progress</h2>
      <div class="gbar"><div class="gfill" id="gfill"></div><span id="gtext">0%</span></div>
      <div class="msg" id="gnote"></div>
    </div>

    <div class="box">
      <h2>Resolution</h2>
      <div class="row">
        <label>Width <input type="number" id="inW" min="<?= MIN_SIZE ?>" max="<?= MAX_SIZE ?>"></label>
        <label>Height <input type="number" id="inH" min="<?= MIN_SIZE ?>" max="<?= MAX_SIZE ?>"></label>
      </div>
      <button type="button" class="btn" id="apply">Apply</button>
      <small><?= MIN_SIZE ?>×<?= MIN_SIZE ?> to <?= MAX_SIZE ?>×<?= MAX_SIZE ?>; artwork is kept where it fits.</small>
    </div>

    <div class="box">
      <h2>Palette</h2>
      <div class="palette" id="palette">
        <button type="button" class="sw none" data-c="-1" data-name="No colour (erase)" title="No colour (eraser)"></button>
<?php foreach ($PALETTE as $i => $c): ?>
        <button type="button" class="sw" data-c="<?= $i ?>" data-name="<?= $c[0] ?>" style="background:<?= $c[1] ?>" title="<?= $c[0] ?>"></button>
<?php endforeach; ?>
      </div>
      <div class="current">Pen: <span id="penname"></span></div>
      <button type="button" class="btn ghost" id="clear">Clear canvas</button>
    </div>

    <div class="box" id="game">
      <h2>Code</h2>
      <div class="row">
        <input type="text" id="gameCode" placeholder="6-digit code" inputmode="numeric" maxlength="6" autocomplete="off">
        <button type="button" class="btn inline" id="loadCode">Load</button>
      </div>
      <div class="msg" id="gameMsg"></div>
    </div>

    <div class="box" id="boss">
      <h2>Boss</h2>
      <div id="bossLocked"<?= $isBoss ? ' hidden' : '' ?>>
        <div class="row">
          <input type="password" id="bossCode" placeholder="boss code" autocomplete="off">
          <button type="button" class="btn inline" id="unlock">Unlock</button>
        </div>
        <div class="msg" id="lockMsg"></div>
      </div>
      <div id="bossOpen"<?= $isBoss ? '' : ' hidden' ?>>
        <button type="button" class="btn t" data-t="grid">Grid marks <b></b></button>
        <button type="button" class="btn t" data-t="labels">Row/column numbers <b></b></button>
        <button type="button" class="btn t" data-t="hl">Highlight row/column <b></b></button>
        <button type="button" class="btn t" data-t="coding">Pixel coding <b></b></button>
        <button type="button" class="btn save" id="save">Save</button>
        <div class="msg" id="saveMsg"></div>
        <button type="button" class="btn ghost" id="lock">Lock</button>
      </div>
    </div>
  </aside>
</main>

<script>
(() => {
'use strict';
const MIN = <?= MIN_SIZE ?>, MAX = <?= MAX_SIZE ?>;
const PAL = <?= json_encode(array_column($PALETTE, 1)) ?>;
const init = <?= json_encode([
    'w' => $S['w'], 'h' => $S['h'], 'px' => $S['px'], 'color' => $S['color'],
    'grid' => $S['grid'], 'labels' => $S['labels'], 'hl' => $S['hl'], 'coding' => $S['coding'],
    'game' => $game ? ['id' => $game['id'], 'code' => $game['code'], 'w' => $game['w'] ?? null, 'h' => $game['h'] ?? null] : null,
]) ?>;

const CHK1 = '#d4d4d4', CHK2 = '#a6a6a6', GRID = 'rgba(0,0,0,.3)', HL = '#ff2d7a', LBL_BG = '#2b2d31';
const LABEL_W = 30, LABEL_H = 16;
const $ = s => document.querySelector(s);
const base = $('#base'), over = $('#over'), wrap = $('#wrap');
const bctx = base.getContext('2d'), octx = over.getContext('2d');

/* ---------- state ---------- */
let w = init.w, h = init.h;
let color = init.color === null ? -1 : init.color;
const opt = { grid: init.grid, labels: init.labels, hl: init.hl, coding: init.coding };
let game = null;                                                       // the code to replicate (canvas starts blank)
if (init.game) {
  const rows = parseCode(init.game.code);
  game = { id: init.game.id, rows,
           w: init.game.w || (rows[0] ? rows[0].reduce((t, r) => t + r[0], 0) : init.w),
           h: init.game.h || init.h };
}
let px = new Int8Array(w * h);
for (let i = 0; i < px.length; i++) px[i] = init.px[i] === '-' ? -1 : init.px.charCodeAt(i) - 97;

let cell = 16, offX = 0, offY = 0, cw = 0, ch = 0, hx = -1, hy = -1;

/* ---------- background saving ---------- */
const statusEl = $('#status');
let dirty = false, inflight = false, timer = null;
const payload = (lock) => {
  const a = new Array(px.length);
  for (let i = 0; i < px.length; i++) a[i] = px[i] < 0 ? '-' : String.fromCharCode(97 + px[i]);
  return JSON.stringify({ w, h, px: a.join(''), color: color < 0 ? null : color, grid: opt.grid, labels: opt.labels, hl: opt.hl, coding: opt.coding, lock: !!lock });
};
const setStatus = (t, cls) => { statusEl.textContent = t; statusEl.className = cls || ''; };
function markDirty() {
  dirty = true;
  setStatus('Unsaved…', 'busy');
  clearTimeout(timer);
  timer = setTimeout(flush, 400);
}
function flush() {
  if (inflight || !dirty) return;
  inflight = true; dirty = false;
  let failed = false;
  setStatus('Saving…', 'busy');
  fetch('?api=save', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: payload(), credentials: 'same-origin' })
    .then(r => { if (!r.ok) throw new Error(r.status); })
    .then(() => { if (!dirty) setStatus('Saved'); })
    .catch(() => { failed = true; dirty = true; setStatus('Save failed – retrying', 'err'); clearTimeout(timer); timer = setTimeout(flush, 3000); })
    .finally(() => {
      inflight = false;
      if (dirty && !failed) { clearTimeout(timer); timer = setTimeout(flush, 100); }  // edits made while saving
    });
}
addEventListener('pagehide', () => {
  if (dirty) navigator.sendBeacon('?api=save', new Blob([payload()], { type: 'application/json' }));
});
document.addEventListener('visibilitychange', () => { if (document.hidden && dirty) { clearTimeout(timer); flush(); } });

/* ---------- drawing ---------- */
function labelStep() {
  for (const s of [1, 2, 5, 10]) if (s * cell >= 24) return s;
  return 10;
}
let step = 1;
function labelCol(ctx, i, hot) {
  const X = offX + i * cell;
  ctx.fillStyle = hot ? HL : LBL_BG; ctx.fillRect(X, 0, cell, offY);
  if ((i + 1) % step === 0 || i === 0) {
    ctx.fillStyle = hot ? '#fff' : '#bbb'; ctx.font = '10px system-ui, sans-serif';
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillText(String(i + 1), X + cell / 2, offY / 2);
  }
}
function labelRow(ctx, i, hot) {
  const Y = offY + i * cell;
  ctx.fillStyle = hot ? HL : LBL_BG; ctx.fillRect(0, Y, offX, cell);
  if ((i + 1) % step === 0 || i === 0) {
    ctx.fillStyle = hot ? '#fff' : '#bbb'; ctx.font = '10px system-ui, sans-serif';
    ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
    ctx.fillText(String(i + 1), offX - 4, Y + cell / 2);
  }
}
function drawCell(x, y) {
  const X = offX + x * cell, Y = offY + y * cell, v = px[y * w + x];
  bctx.fillStyle = v < 0 ? (((x + y) & 1) ? CHK2 : CHK1) : PAL[v];
  bctx.fillRect(X, Y, cell, cell);
  if (opt.grid) {
    bctx.fillStyle = GRID;
    bctx.fillRect(X + cell - 1, Y, 1, cell);
    bctx.fillRect(X, Y + cell - 1, cell, 1);
    if (x === 0) bctx.fillRect(X, Y, 1, cell);
    if (y === 0) bctx.fillRect(X, Y, cell, 1);
  }
}
function drawBase() {
  bctx.clearRect(0, 0, cw, ch);
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) drawCell(x, y);
  if (opt.labels) {
    bctx.fillStyle = LBL_BG; bctx.fillRect(0, 0, offX, offY);
    for (let i = 0; i < w; i++) labelCol(bctx, i, false);
    for (let i = 0; i < h; i++) labelRow(bctx, i, false);
  }
}
function drawOver() {
  setCodeHot(hy);
  octx.clearRect(0, 0, cw, ch);
  if (!opt.hl || hx < 0 || hy < 0) return;
  octx.fillStyle = HL;
  octx.fillRect(offX, offY + hy * cell, w * cell, 2);                   // row: top + bottom edges
  octx.fillRect(offX, offY + (hy + 1) * cell - 2, w * cell, 2);
  octx.fillRect(offX + hx * cell, offY, 2, h * cell);                   // column: left + right edges
  octx.fillRect(offX + (hx + 1) * cell - 2, offY, 2, h * cell);
  if (opt.labels) { labelCol(octx, hx, true); labelRow(octx, hy, true); }
}
function layout() {
  cell = Math.max(5, Math.min(32, Math.floor(640 / Math.max(w, h))));
  offX = opt.labels ? LABEL_W : 0; offY = opt.labels ? LABEL_H : 0;
  step = labelStep();
  cw = offX + w * cell; ch = offY + h * cell;
  const dpr = window.devicePixelRatio || 1;
  for (const [c, ctx] of [[base, bctx], [over, octx]]) {
    c.width = Math.round(cw * dpr); c.height = Math.round(ch * dpr);
    c.style.width = cw + 'px'; c.style.height = ch + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }
  wrap.style.width = cw + 'px'; wrap.style.height = ch + 'px';
  codeLines.style.maxHeight = Math.max(120, ch - 60) + 'px';
  $('#inW').value = w; $('#inH').value = h;
}
/* ---------- pixel coding panel ----------
   Shows either the game's code (loaded by id, canvas left blank) or - when the boss
   switches "Pixel coding" on - the live code of whatever is on the canvas. */
const codeEl = $('#coding'), codeLines = $('#codelines');
let rowEls = [], dirtyRows = new Set(), codeRaf = 0, hotEl = null;
const codingOn = () => !!game || opt.coding;
function parseCode(text) {                                             // "3a 5- 24f\n..." -> [[[3,0],[5,-1],[24,5]], ...]
  const rows = [];
  if (!text) return rows;
  for (const line of String(text).split('\n')) {
    const runs = [];
    for (const t of line.split(' ')) {
      const m = /^(\d+)([a-y-])$/.exec(t);
      if (m) runs.push([+m[1], m[2] === '-' ? -1 : m[2].charCodeAt(0) - 97]);
    }
    rows.push(runs);
  }
  return rows;
}
function setCodeHot(y) {                                               // highlight the code line of the hovered row
  let el = null;
  if (opt.hl && codingOn() && y >= 0 && rowEls[y] && !rowEls[y].hidden) el = rowEls[y];
  if (el === hotEl) return;
  if (hotEl) hotEl.classList.remove('hot');
  hotEl = el;
  if (!el) return;
  el.classList.add('hot');
  const top = el.offsetTop - codeLines.offsetTop, bottom = top + el.offsetHeight;   // keep it visible inside the panel
  if (top < codeLines.scrollTop) codeLines.scrollTop = top;
  else if (bottom > codeLines.scrollTop + codeLines.clientHeight) codeLines.scrollTop = bottom - codeLines.clientHeight;
}
function runsHTML(runs) {
  let html = '';
  for (const [n, v] of runs) html += '<span class="run"><b>' + n + '</b><i' + (v < 0 ? ' class="nc"' : ' style="background:' + PAL[v] + '"') + '></i></span>';
  return html;
}
function liveRuns(y) {
  const runs = [], o = y * w;
  for (let x = 0; x < w;) {
    const v = px[o + x];
    let n = 1;
    while (x + n < w && px[o + x + n] === v) n++;
    runs.push([n, v]); x += n;
  }
  return runs;
}
const rowHTML = y => '<span class="cn">' + (y + 1) + '</span>' + runsHTML(game ? game.rows[y] : liveRuns(y));
function lastColouredRow() {
  for (let y = h - 1; y >= 0; y--) {
    const o = y * w;
    for (let x = 0; x < w; x++) if (px[o + x] >= 0) return y;
  }
  return -1;
}
function showRows() {                                                  // live mode: trailing blank rows are not part of the code
  if (game) return;
  const last = lastColouredRow();
  for (let y = 0; y < rowEls.length; y++) rowEls[y].hidden = y > last;
}
function buildCoding() {
  dirtyRows.clear();
  hotEl = null;
  codeLines.textContent = '';
  rowEls = [];
  const frag = document.createDocumentFragment(), n = game ? game.rows.length : h;
  for (let y = 0; y < n; y++) {
    const d = document.createElement('div');
    d.className = 'cl';
    d.innerHTML = rowHTML(y);
    rowEls.push(d); frag.appendChild(d);
  }
  codeLines.appendChild(frag);
  showRows();
  setCodeHot(hy);
}
function updateCoding() {
  codeRaf = 0;
  if (game || !opt.coding) return;
  for (const y of dirtyRows) if (rowEls[y]) rowEls[y].innerHTML = rowHTML(y);
  dirtyRows.clear();
  showRows();
  setCodeHot(hy);
}
function touchRow(y) {
  if (game || !opt.coding) return;
  dirtyRows.add(y);
  if (!codeRaf) codeRaf = requestAnimationFrame(updateCoding);
}
function showCoding() {
  codeEl.hidden = !codingOn();
  if (!codingOn()) { setCodeHot(-1); return; }
  $('#codeTitle').textContent = game ? 'Code to replicate' : 'Pixel coding';
  $('#codeHint').textContent = game
    ? 'Each line is a row, left to right: pixel count + colour. Rows below the last line are blank.'
    : 'Each line is a row: pixel count + colour, left to right. Blank rows at the bottom are left out.';
  buildCoding();
}

/* ---------- progress gauge ----------
   0% on the blank canvas, 100% only when every pixel matches the game's code.
   Pixels that are blank in the code count as correct while they stay blank. */
let target = null, match = 0, baseMatch = 0;
function buildTarget() {
  target = null;
  if (game && w === game.w && h === game.h) {
    target = new Int8Array(w * h).fill(-1);
    game.rows.forEach((runs, y) => {
      let x = 0;
      for (const [n, v] of runs) { for (let k = 0; k < n && x < w; k++, x++) target[y * w + x] = v; }
    });
    baseMatch = 0; match = 0;
    for (let i = 0; i < target.length; i++) { if (target[i] === -1) baseMatch++; if (px[i] === target[i]) match++; }
  }
  renderGauge();
}
function renderGauge() {
  const box = $('#gauge');
  box.hidden = !game;
  if (!game) return;
  let pct = 0;
  if (target) {
    const denom = w * h - baseMatch;
    pct = denom > 0 ? Math.max(0, Math.floor((match - baseMatch) / denom * 100)) : (match === w * h ? 100 : 0);
  }
  $('#gfill').style.width = pct + '%';
  $('#gtext').textContent = pct + '%';
  box.classList.toggle('done', !!target && pct === 100);
  $('#gnote').textContent = !target ? 'Set the resolution to ' + game.w + '×' + game.h + ' to match the code.'
                          : pct === 100 ? 'Complete – every pixel matches the code!' : '';
}

function fullRender() { layout(); drawBase(); drawOver(); showCoding(); buildTarget(); }

/* ---------- pen ---------- */
function paint(x, y) {
  const i = y * w + x, old = px[i];
  if (old === color) return;
  px[i] = color;
  drawCell(x, y);
  touchRow(y);
  if (target) { match += (color === target[i]) - (old === target[i]); renderGauge(); }
  markDirty();
}
function cellAt(e) {
  const r = base.getBoundingClientRect();
  const x = Math.floor((e.clientX - r.left - offX) / cell), y = Math.floor((e.clientY - r.top - offY) / cell);
  return (x >= 0 && x < w && y >= 0 && y < h) ? [x, y] : null;
}
function line(x0, y0, x1, y1) {                                        // Bresenham, so fast drags leave no gaps
  const dx = Math.abs(x1 - x0), dy = -Math.abs(y1 - y0), sx = x0 < x1 ? 1 : -1, sy = y0 < y1 ? 1 : -1;
  let err = dx + dy;
  for (;;) {
    paint(x0, y0);
    if (x0 === x1 && y0 === y1) break;
    const e2 = 2 * err;
    if (e2 >= dy) { err += dy; x0 += sx; }
    if (e2 <= dx) { err += dx; y0 += sy; }
  }
}
let drawing = false, last = null;
base.addEventListener('pointerdown', e => {
  if (e.button !== 0) return;
  const c = cellAt(e); if (!c) return;
  drawing = true; last = c;
  base.setPointerCapture(e.pointerId);
  paint(c[0], c[1]);
});
base.addEventListener('pointermove', e => {
  const c = cellAt(e);
  const nx = c ? c[0] : -1, ny = c ? c[1] : -1;
  if (nx !== hx || ny !== hy) { hx = nx; hy = ny; if (opt.hl) drawOver(); }
  if (drawing && c) { line(last[0], last[1], c[0], c[1]); last = c; }
});
const stop = () => { drawing = false; last = null; };
base.addEventListener('pointerup', stop);
base.addEventListener('pointercancel', stop);
base.addEventListener('pointerleave', () => { if (hx !== -1) { hx = hy = -1; drawOver(); } });

/* ---------- palette / options / resolution ---------- */
function showPen() {
  document.querySelectorAll('#palette .sw').forEach(b => b.classList.toggle('sel', +b.dataset.c === color));
  $('#penname').textContent = document.querySelector('#palette .sw.sel').dataset.name;
}
$('#palette').addEventListener('click', e => {
  const b = e.target.closest('.sw'); if (!b) return;
  color = +b.dataset.c; showPen(); markDirty();
});
function showToggles() {
  document.querySelectorAll('.btn.t').forEach(b => {
    const on = opt[b.dataset.t];
    b.classList.toggle('on', on); b.querySelector('b').textContent = on ? 'ON' : 'OFF';
  });
}
document.querySelectorAll('.btn.t').forEach(b => b.addEventListener('click', () => {
  const k = b.dataset.t; opt[k] = !opt[k];
  if (k === 'coding' && opt.coding) { game = null; renderGauge(); }    // live coding replaces the game's code
  showToggles();
  if (k === 'labels') { fullRender(); } else if (k === 'grid') { drawBase(); } else if (k === 'coding') { showCoding(); } else { drawOver(); }
  markDirty();
}));
$('#clear').addEventListener('click', () => { px.fill(-1); drawBase(); buildTarget(); if (opt.coding) buildCoding(); markDirty(); });
function applySize() {
  const clamp = v => Math.max(MIN, Math.min(MAX, parseInt(v, 10) || MIN));
  const nw = clamp($('#inW').value), nh = clamp($('#inH').value);
  const np = new Int8Array(nw * nh).fill(-1);
  for (let y = 0; y < Math.min(nh, h); y++) for (let x = 0; x < Math.min(nw, w); x++) np[y * nw + x] = px[y * w + x];
  w = nw; h = nh; px = np; hx = hy = -1;
  fullRender(); markDirty();
}
$('#apply').addEventListener('click', applySize);
['#inW', '#inH'].forEach(s => $(s).addEventListener('keydown', e => { if (e.key === 'Enter') applySize(); }));

/* ---------- boss: unlock / lock / save ---------- */
const JSON_HDR = { 'Content-Type': 'application/json' };
function say(el, text, cls) { el.textContent = text; el.className = 'msg' + (cls ? ' ' + cls : ''); }
function setBoss(on) {
  $('#bossLocked').hidden = on;
  $('#bossOpen').hidden = !on;
  say($('#lockMsg'), ''); say($('#saveMsg'), '');
  if (!on) $('#bossCode').value = '';
}
async function unlock() {
  const code = $('#bossCode').value;
  if (!code) return;
  say($('#lockMsg'), 'Checking…');
  try {
    const r = await fetch('?api=auth', { method: 'POST', headers: JSON_HDR, credentials: 'same-origin', body: JSON.stringify({ code }) });
    if (r.ok) setBoss(true);
    else say($('#lockMsg'), r.status === 429 ? 'Too many attempts – wait a minute.' : 'Wrong code.', 'err');
  } catch (e) { say($('#lockMsg'), 'Could not reach the server.', 'err'); }
}
$('#unlock').addEventListener('click', unlock);
$('#bossCode').addEventListener('keydown', e => { if (e.key === 'Enter') unlock(); });

$('#lock').addEventListener('click', async () => {
  clearTimeout(timer); dirty = false;
  try {                                                                // final state goes up with the lock request
    const r = await fetch('?api=save', { method: 'POST', headers: JSON_HDR, credentials: 'same-origin', body: payload(true) });
    if (!r.ok) throw new Error(r.status);
    setStatus('Saved');
    setBoss(false);
  } catch (e) { dirty = true; say($('#saveMsg'), 'Could not lock – try again.', 'err'); }
});

$('#save').addEventListener('click', async () => {
  const btn = $('#save');
  clearTimeout(timer); dirty = false;
  btn.disabled = true; say($('#saveMsg'), 'Saving…');
  try {
    const r = await fetch('?api=boss-save', { method: 'POST', headers: JSON_HDR, credentials: 'same-origin', body: payload() });
    const j = await r.json().catch(() => null);
    if (r.ok && j && j.ok) {
      const m = $('#saveMsg');
      m.className = 'msg ok'; m.textContent = 'Saved. ID: ';
      const b = document.createElement('b'); b.textContent = String(j.id); m.appendChild(b);
      setStatus('Saved');
    } else if (r.status === 403) {
      setBoss(false); say($('#lockMsg'), 'Session expired – enter the boss code again.', 'err');
    } else {
      dirty = true; say($('#saveMsg'), j && j.error === 'database' ? 'Save failed (database error).' : 'Save failed.', 'err');
    }
  } catch (e) { dirty = true; say($('#saveMsg'), 'Could not reach the server.', 'err'); }
  btn.disabled = false;
});

/* ---------- load a code (the "game") ---------- */
async function loadCode() {
  const msg = $('#gameMsg'), btn = $('#loadCode');
  const raw = $('#gameCode').value.replace(/\D/g, '');
  if (raw.length !== 6) { say(msg, 'Enter a 6-digit code.', 'err'); return; }
  btn.disabled = true; say(msg, 'Loading…');
  try {
    const r = await fetch('?api=load', { method: 'POST', headers: JSON_HDR, credentials: 'same-origin', body: JSON.stringify({ id: +raw }) });
    const j = await r.json().catch(() => null);
    if (r.ok && j && j.ok) {
      clearTimeout(timer); dirty = false;                              // the server already holds the blank canvas
      w = j.w; h = j.h; px = new Int8Array(w * h).fill(-1);
      game = { id: j.id, rows: parseCode(j.code), w: j.w, h: j.h };
      opt.coding = false; hx = hy = -1;
      showToggles(); fullRender(); setStatus('Saved');
      $('#gameCode').value = '';
      say(msg, 'Code ' + j.id + ' loaded. Recreate it on the blank canvas.', 'ok');
    } else if (r.status === 404) {
      say(msg, 'No picture with that code.', 'err');
    } else if (r.status === 429) {
      say(msg, 'Too many wrong codes – wait a minute.', 'err');
    } else {
      say(msg, 'Could not load that code.', 'err');
    }
  } catch (e) { say(msg, 'Could not reach the server.', 'err'); }
  btn.disabled = false;
}
$('#loadCode').addEventListener('click', loadCode);
$('#gameCode').addEventListener('keydown', e => { if (e.key === 'Enter') loadCode(); });

/* ---------- go ---------- */
showPen(); showToggles(); fullRender();
})();
</script>
</body>
</html>
