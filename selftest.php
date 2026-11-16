<?php
/* Si PANDU – uji mandiri di server (butuh token dari konfigurasi). Tidak menampilkan data rahasia. */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/schema.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$cfg = sp_config();
if (empty($cfg['selftest_token']) || !hash_equals((string)$cfg['selftest_token'], (string)($_GET['token'] ?? ''))) { http_response_code(403); exit('403'); }

$rows = [];
$add = function (string $label, bool $ok, string $detail = '') use (&$rows) { $rows[] = [$label, $ok, $detail]; };
$add('PHP ' . PHP_VERSION, version_compare(PHP_VERSION, SIPANDU_MIN_PHP, '>='), 'minimal ' . SIPANDU_MIN_PHP);
foreach (['pdo', 'pdo_mysql', 'gd', 'mbstring', 'json', 'session', 'openssl'] as $e) $add('ekstensi ' . $e, extension_loaded($e));
$add('HTTPS', sp_https());
$dd = rtrim($cfg['data_dir'], '/');
$add('folder data di luar web root', is_dir($dd) && is_writable($dd) && !str_starts_with(realpath($dd) ?: $dd, realpath(__DIR__) ?: __DIR__), basename($dd));
try {
    $pdo = sp_db();
    $ver = (string)$pdo->query(sp_driver() === 'sqlite' ? 'SELECT sqlite_version()' : 'SELECT VERSION()')->fetchColumn();
    $add('koneksi basis data', true, $ver);
    $tables = [];
    foreach ($pdo->query(sp_driver() === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table'" : 'SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tn) $tables[] = $tn;
    $missing = array_diff(sp_required_tables(), $tables);
    $add('tabel lengkap', !$missing, $missing ? 'belum ada: ' . implode(', ', $missing) : count(sp_required_tables()) . ' tabel');
    // uji tulis-baca transaksi (dibatalkan)
    $pdo->beginTransaction();
    try {
        $pdo->exec("CREATE TEMPORARY TABLE sp_selftest (id INT, v VARCHAR(20))");
        $pdo->exec("INSERT INTO sp_selftest VALUES (1, 'ok')");
        $add('tulis-baca basis data', $pdo->query('SELECT v FROM sp_selftest')->fetchColumn() === 'ok');
    } finally { $pdo->rollBack(); }
    $add('status pemasangan', true, sp_installed() ? 'sudah terpasang' : 'belum terpasang');
} catch (Throwable $e) {
    $add('koneksi/uji basis data', false, 'gagal: ' . get_class($e));
}
require __DIR__ . '/tests/engine_tests.php';
$r = sipandu_engine_tests();
$add('mesin Westgard: setara Excel + skenario paper', $r['fail'] === 0, $r['checks'] . ' pemeriksaan (Excel ' . $r['excel_checks'] . '), gagal ' . $r['fail']);
$all = !in_array(false, array_column($rows, 1), true);
echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Uji mandiri</title><link rel="stylesheet" href="assets/app.css"></head><body><div class="verify" style="max-width:760px"><h2>Uji mandiri Si PANDU ' . SIPANDU_VERSION . '</h2>';
echo '<div class="big-status ' . ($all ? 'c-ok' : 'c-bad') . '">' . ($all ? 'SEMUA LULUS' : 'ADA YANG GAGAL') . '</div><div class="tbl"><table>';
foreach ($rows as [$l, $ok, $dt]) echo '<tr><td>' . h($l) . '</td><td>' . ($ok ? '<span class="chip c-ok">OK</span>' : '<span class="chip c-bad">GAGAL</span>') . '</td><td class="small">' . h($dt) . '</td></tr>';
echo '</table></div></div></body></html>';
