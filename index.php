<?php
/* Si PANDU – halaman masuk, ganti kata sandi wajib, dan kerangka aplikasi */
// Tanpa strict_types: nilai dari basis data selalu berupa string dan dikonversi otomatis oleh PHP.
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/domain.php';

sp_send_security_headers();
header('Cache-Control: no-store');
if (!sp_installed()) {
    http_response_code(503);
    exit(h(t('galat.belum_terpasang')));
}
sp_ensure_schema();
sp_session_start();
$tampil = array_merge(['appName' => t('app.nama'), 'longName' => t('app.kepanjangan'), 'tagline' => t('app.slogan'), 'color' => '#0d5c63'], array_filter(setting('tampilan', []) ?: []));
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $err = t('galat.csrf');
    } elseif (($_POST['act'] ?? '') === 'login') {
        $un = strtolower(trim((string)($_POST['username'] ?? '')));
        $pw = (string)($_POST['password'] ?? '');
        if (login_locked($un)) {
            $err = t('masuk.terkunci');
        } else {
            $u = $un !== '' ? q1('SELECT * FROM users WHERE username = ?', [$un]) : null;
            if ($u && $u['aktif'] && password_verify($pw, $u['pass_hash'])) {
                login_record($un, true);
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$u['id'];
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                if (password_needs_rehash($u['pass_hash'], PASSWORD_DEFAULT)) q('UPDATE users SET pass_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
                q('UPDATE users SET last_login = ? WHERE id = ?', [now_str(), $u['id']]);
                audit('login');
                header('Location: ./'); exit;
            }
            login_record($un, false);
            $err = t('masuk.gagal');
        }
    } elseif (($_POST['act'] ?? '') === 'change' && current_user()) {
        $u = current_user();
        $old = (string)($_POST['old'] ?? ''); $new = (string)($_POST['new'] ?? ''); $new2 = (string)($_POST['new2'] ?? '');
        if (!password_verify($old, $u['pass_hash'])) $err = t('galat.sandi_lama_salah');
        elseif ($new !== $new2) $err = t('galat.sandi_tidak_sama');
        elseif (!valid_new_password($new)) $err = t('galat.sandi_lemah');
        elseif (hash_equals($old, $new)) $err = t('galat.sandi_sama');
        else {
            q('UPDATE users SET pass_hash = ?, must_change = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            audit('password_change_first');
            header('Location: ./'); exit;
        }
    }
}

$u = current_user();
function page_head(array $tampil, string $title): void
{
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title><link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="assets/app.css?v=' . SIPANDU_VERSION . '"><style>:root{--pri:' . h($tampil['color']) . '}</style></head><body>';
}

if (!$u || $u['must_change']) {
    page_head($tampil, $tampil['appName']);
    echo '<div class="login"><div class="panel"><h1>' . h($tampil['appName']) . '</h1><div class="muted">' . h($tampil['longName']) . '</div><div class="tag">“' . h($tampil['tagline']) . '”</div>';
    if ($err) echo '<div class="note bad">' . h($err) . '</div>';
    if (!$u) {
        echo '<form method="post" class="grid" style="gap:10px"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="act" value="login">';
        echo '<label class="f">' . h(t('masuk.username')) . '<input class="inp" name="username" autocomplete="username" required autofocus></label>';
        echo '<label class="f">' . h(t('masuk.password')) . pw_field('name="password" autocomplete="current-password" required') . '</label>';
        echo '<button class="btn" type="submit">' . h(t('masuk.tombol')) . '</button></form>';
    } else {
        echo '<div class="note info">' . h(t('masuk.wajib_ganti', ['nama' => $u['nama']])) . '</div>';
        echo '<form method="post" class="grid" style="gap:10px"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="act" value="change">';
        echo '<label class="f">' . h(t('masuk.sandi_lama')) . pw_field('name="old" autocomplete="current-password" required') . '</label>';
        echo '<label class="f">' . h(t('masuk.sandi_baru')) . pw_field('name="new" autocomplete="new-password" required') . '</label>';
        echo '<label class="f">' . h(t('masuk.sandi_baru2')) . pw_field('name="new2" autocomplete="new-password" required') . '</label>';
        echo '<p class="small muted">' . h(t('masuk.syarat_sandi')) . '</p><button class="btn" type="submit">' . h(t('masuk.simpan_sandi')) . '</button></form>';
        echo '<p class="small" style="margin-top:12px"><a href="logout.php?t=' . h(csrf_token()) . '">' . h(t('umum.keluar')) . '</a></p>';
    }
    echo '</div></div><script src="assets/pw.js?v=' . SIPANDU_VERSION . '"></script></body></html>';
    exit;
}

/* Kerangka aplikasi (SPA). Semua teks dikirim dari app/teks.php. */
page_head($tampil, $tampil['appName']);
$boot = ['csrf' => csrf_token(), 'T' => sp_teks(), 'state' => app_state($u), 'version' => SIPANDU_VERSION];
echo '<div id="app"></div><script>window.SIPANDU=' . json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRESERVE_ZERO_FRACTION) . ';</script>';
foreach (['engine.js', 'client.js', 'app.js', 'pw.js'] as $js) echo '<script src="assets/' . $js . '?v=' . SIPANDU_VERSION . '"></script>';
echo '</body></html>';
