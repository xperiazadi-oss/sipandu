<?php
/* Si PANDU – pemasangan awal. Hanya dapat dijalankan dengan token dari file konfigurasi, dan mengunci diri setelah selesai. */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/schema.php';

sp_send_security_headers();
header('Cache-Control: no-store');
$cfg = sp_config();
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$okToken = !empty($cfg['install_token']) && hash_equals((string)$cfg['install_token'], $token);

function checks(): array
{
    $c = [];
    $c[] = [t('pasang.cek_php', ['v' => PHP_VERSION]), version_compare(PHP_VERSION, SIPANDU_MIN_PHP, '>=')];
    foreach (['pdo', 'gd', 'mbstring', 'json', 'session'] as $ext) $c[] = [t('pasang.cek_ext', ['e' => $ext]), extension_loaded($ext)];
    $drv = sp_driver() === 'sqlite' ? 'pdo_sqlite' : 'pdo_mysql';
    $c[] = [t('pasang.cek_ext', ['e' => $drv]), extension_loaded($drv)];
    $dd = rtrim(sp_config()['data_dir'], '/');
    @mkdir($dd . '/kop', 0750, true);
    $c[] = [t('pasang.cek_data'), is_dir($dd) && is_writable($dd)];
    try { sp_db(); $c[] = [t('pasang.cek_db'), true]; } catch (Throwable $e) { $c[] = [t('pasang.cek_db'), false]; }
    return $c;
}

echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h(t('pasang.judul')) . '</title><link rel="stylesheet" href="assets/app.css"></head><body><div class="verify" style="max-width:760px">';
echo '<h2>' . h(t('pasang.judul')) . '</h2>';
if (!$okToken) { echo '<div class="big-status c-bad">' . h(t('pasang.token_salah')) . '</div></div></body></html>'; exit; }
if (sp_installed()) { echo '<div class="big-status c-ok">' . h(t('pasang.sudah')) . '</div><p><a href="./">' . h(t('pasang.ke_aplikasi')) . '</a></p></div></body></html>'; exit; }

$checks = checks();
$allOk = !in_array(false, array_column($checks, 1), true);
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $allOk) {
    $p1 = (string)($_POST['p1'] ?? ''); $p2 = (string)($_POST['p2'] ?? '');
    if ($p1 !== $p2) $err = t('galat.sandi_tidak_sama');
    elseif (!valid_new_password($p1) || mb_strlen($p1) < 10) $err = t('pasang.sandi_admin_syarat');
    else {
        require __DIR__ . '/app/seed.php';
        $pdo = sp_db();
        sp_create_schema($pdo, sp_driver());
        $accounts = sp_seed_initial($p1);
        set_setting('installed_at', now_str());
        echo '<div class="big-status c-ok">' . h(t('pasang.selesai')) . '</div><p>' . h(t('pasang.catat_sandi')) . '</p>';
        echo '<div class="tbl"><table id="acc"><tr><th>' . h(t('pasang.kol_peran')) . '</th><th>' . h(t('pasang.kol_nama')) . '</th><th>' . h(t('pasang.kol_username')) . '</th><th>' . h(t('pasang.kol_sandi')) . '</th></tr>';
        $csv = "peran,nama,nama_pengguna,kata_sandi_sementara\n";
        foreach ($accounts as $a) {
            echo '<tr><td>' . h(t('peran.' . $a['role'])) . '</td><td>' . h($a['name']) . '</td><td><code>' . h($a['username']) . '</code></td><td><code>' . h($a['password']) . '</code></td></tr>';
            $csv .= '"' . $a['role'] . '","' . str_replace('"', '""', $a['name']) . '","' . $a['username'] . '","' . $a['password'] . "\"\n";
        }
        echo '</table></div><p style="margin-top:12px"><a class="btn" download="akun-sipandu.csv" href="data:text/csv;charset=utf-8,' . rawurlencode($csv) . '">' . h(t('pasang.unduh_csv')) . '</a> <a class="btn o" href="./">' . h(t('pasang.ke_aplikasi')) . '</a></p>';
        echo '</div></body></html>';
        exit;
    }
}
echo '<p>' . h(t('pasang.pengantar')) . '</p><div class="tbl"><table>';
foreach ($checks as [$label, $ok]) echo '<tr><td>' . h($label) . '</td><td>' . ($ok ? '<span class="chip c-ok">OK</span>' : '<span class="chip c-bad">' . h(t('pasang.belum')) . '</span>') . '</td></tr>';
echo '</table></div>';
if (!$allOk) { echo '<div class="note bad">' . h(t('pasang.perbaiki_dulu')) . '</div></div></body></html>'; exit; }
if ($err) echo '<div class="note bad">' . h($err) . '</div>';
echo '<form method="post" class="grid" style="gap:10px;margin-top:14px"><input type="hidden" name="token" value="' . h($token) . '">';
echo '<p><b>' . h(t('pasang.akun_admin')) . '</b> <code>admin</code></p>';
echo '<label class="f">' . h(t('pasang.sandi_admin')) . '<input class="inp" type="password" name="p1" autocomplete="new-password" required></label>';
echo '<label class="f">' . h(t('masuk.sandi_baru2')) . '<input class="inp" type="password" name="p2" autocomplete="new-password" required></label>';
echo '<p class="small muted">' . h(t('pasang.sandi_admin_syarat')) . '</p><button class="btn" type="submit">' . h(t('pasang.tombol')) . '</button></form></div></body></html>';
