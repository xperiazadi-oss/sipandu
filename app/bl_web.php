<?php
/* Subdomain pembelajaran (belajar.*): halaman masuk & buat akun, kerangka aplikasi, API, berkas, keluar, dan pemasangan Admin.
   Dipanggil dari berkas masuk di akar (index.php, api.php, berkas.php, logout.php, install.php) bila sp_app() === 'bl'. */
// Tanpa strict_types: nilai dari basis data selalu berupa string dan dikonversi otomatis oleh PHP.
require_once __DIR__ . '/belajar.php';
require_once __DIR__ . '/bl_laporan.php';
require_once __DIR__ . '/bl_dok.php';

/** Batas pembuatan akun per alamat IP (perlindungan dari pengisian otomatis) */
const BL_DAFTAR_PER_JAM = 5;

function bl_page_head(string $title): void
{
    $tampil = array_merge(['color' => '#0d5c63'], array_filter(setting('tampilan', []) ?: []));
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title><link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="assets/app.css?v=' . SIPANDU_VERSION . '"><style>:root{--pri:' . h($tampil['color']) . '}</style></head><body>';
}
function bl_login_user(array $u, string $pw = ''): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    if ($pw !== '' && password_needs_rehash($u['pass_hash'], PASSWORD_DEFAULT)) q('UPDATE bl_users SET pass_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
    q('UPDATE bl_users SET last_login = ? WHERE id = ?', [now_str(), $u['id']]);
}
/** Jumlah akun yang dibuat dari IP ini dalam satu jam terakhir */
function bl_daftar_count(): int
{
    $since = (new DateTimeImmutable('-1 hour', new DateTimeZone('Asia/Makassar')))->format('Y-m-d H:i:s');
    return (int)qval("SELECT COUNT(*) FROM login_attempts WHERE username = 'bl:#daftar' AND ip = ? AND at >= ?", [client_ip(), $since]);
}

/* ---------- halaman utama ---------- */
function bl_page_index(): void
{
    sp_send_security_headers();
    header('Cache-Control: no-store');
    sp_watermark();
    if (!sp_installed()) { http_response_code(503); exit(h(t('galat.belum_terpasang'))); }
    sp_ensure_schema();
    sp_session_start();
    $title = t('blapp.judul');
    $err = ''; $old = [];
    $mode = isset($_GET['daftar']) ? 'daftar' : 'masuk';
    if (!qval("SELECT COUNT(*) FROM bl_users WHERE role = 'admin'")) {
        bl_page_head($title);
        echo '<div class="login"><div class="panel"><h1>' . h($title) . '</h1><div class="note info">' . h(t('blapp.belum_dipasang')) . '</div></div></div></body></html>';
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $act = (string)($_POST['act'] ?? '');
        if (!csrf_ok($_POST['csrf'] ?? null)) {
            $err = t('galat.csrf');
        } elseif ($act === 'login') {
            $un = strtolower(trim((string)($_POST['username'] ?? '')));
            $pw = (string)($_POST['password'] ?? '');
            if (login_locked($un)) $err = t('masuk.terkunci');
            else {
                $u = $un !== '' ? q1('SELECT * FROM bl_users WHERE username = ?', [$un]) : null;
                if ($u && $u['aktif'] && password_verify($pw, $u['pass_hash'])) {
                    login_record($un, true);
                    bl_login_user($u, $pw);
                    audit('login');
                    header('Location: ./'); exit;
                }
                login_record($un, false);
                $err = t('masuk.gagal');
            }
        } elseif ($act === 'daftar') {
            $mode = 'daftar';
            $old = ['nama' => trim((string)($_POST['nama'] ?? '')), 'email' => trim((string)($_POST['email'] ?? '')), 'hp' => trim((string)($_POST['hp'] ?? '')),
                'instansi' => trim((string)($_POST['instansi'] ?? '')), 'username' => strtolower(trim((string)($_POST['username'] ?? '')))];
            $p1 = (string)($_POST['p1'] ?? ''); $p2 = (string)($_POST['p2'] ?? '');
            if ((string)($_POST['website'] ?? '') !== '') $err = t('blapp.galat_daftar');           // kolom jebakan bot terisi
            elseif (bl_daftar_count() >= BL_DAFTAR_PER_JAM) $err = t('blapp.galat_terlalu_sering');
            elseif ($old['nama'] === '' || $old['email'] === '' || $old['hp'] === '' || $old['instansi'] === '' || $old['username'] === '') $err = t('blapp.galat_lengkapi');
            elseif (mb_strlen($old['nama']) > 150 || mb_strlen($old['instansi']) > 200) $err = t('galat.data_tidak_valid');
            elseif (!bl_valid_email($old['email']) || mb_strlen($old['email']) > 150) $err = t('bl.galat.email');
            elseif (!bl_valid_hp($old['hp'])) $err = t('bl.galat.hp');
            elseif (!preg_match('/^[a-z0-9._-]{3,40}$/', $old['username'])) $err = t('galat.username_format');
            elseif (qval('SELECT COUNT(*) FROM bl_users WHERE username = ?', [$old['username']])) $err = t('galat.username_dipakai');
            elseif ($p1 !== $p2) $err = t('galat.sandi_tidak_sama');
            elseif (!valid_new_password($p1)) $err = t('galat.sandi_lemah');
            else {
                try {
                    $id = sp_insert('bl_users', ['role' => 'peserta', 'nama' => $old['nama'], 'username' => $old['username'], 'pass_hash' => password_hash($p1, PASSWORD_DEFAULT), 'must_change' => 0,
                        'email' => $old['email'], 'hp' => $old['hp'], 'instansi' => $old['instansi'], 'aktif' => 1, 'created_at' => now_str()]);
                } catch (PDOException $e) { $id = 0; $err = t('galat.username_dipakai'); }
                if ($id) {
                    sp_insert('login_attempts', ['username' => 'bl:#daftar', 'ip' => client_ip(), 'at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar')))->format('Y-m-d H:i:s'), 'ok' => 1]);
                    bl_login_user(q1('SELECT * FROM bl_users WHERE id = ?', [$id]));
                    audit('bl_akun_baru');
                    header('Location: ./'); exit;
                }
            }
        } elseif ($act === 'change' && current_user()) {
            $u = current_user();
            $o = (string)($_POST['old'] ?? ''); $n = (string)($_POST['new'] ?? ''); $n2 = (string)($_POST['new2'] ?? '');
            if (!password_verify($o, $u['pass_hash'])) $err = t('galat.sandi_lama_salah');
            elseif ($n !== $n2) $err = t('galat.sandi_tidak_sama');
            elseif (!valid_new_password($n)) $err = t('galat.sandi_lemah');
            elseif (hash_equals($o, $n)) $err = t('galat.sandi_sama');
            else {
                q('UPDATE bl_users SET pass_hash = ?, must_change = 0 WHERE id = ?', [password_hash($n, PASSWORD_DEFAULT), $u['id']]);
                audit('password_change_first');
                header('Location: ./'); exit;
            }
        }
    }

    $u = current_user();
    if (!$u || $u['must_change']) {
        bl_page_head($title);
        echo '<div class="login"><div class="panel"><h1>' . h(t('app.nama')) . '</h1><div class="muted">' . h(t('blapp.subjudul')) . '</div>';
        if ($err) echo '<div class="note bad">' . h($err) . '</div>';
        $csrf = '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
        if ($u) {
            echo '<div class="note info">' . h(t('masuk.wajib_ganti', ['nama' => $u['nama']])) . '</div>';
            echo '<form method="post" class="grid" style="gap:10px">' . $csrf . '<input type="hidden" name="act" value="change">';
            echo '<label class="f">' . h(t('masuk.sandi_lama')) . pw_field('name="old" autocomplete="current-password" required') . '</label>';
            echo '<label class="f">' . h(t('masuk.sandi_baru')) . pw_field('name="new" autocomplete="new-password" required') . '</label>';
            echo '<label class="f">' . h(t('masuk.sandi_baru2')) . pw_field('name="new2" autocomplete="new-password" required') . '</label>';
            echo '<p class="small muted">' . h(t('masuk.syarat_sandi')) . '</p><button class="btn" type="submit">' . h(t('masuk.simpan_sandi')) . '</button></form>';
            echo '<p class="small" style="margin-top:12px"><a href="logout.php?t=' . h(csrf_token()) . '">' . h(t('umum.keluar')) . '</a></p>';
        } elseif ($mode === 'daftar') {
            $v = fn($k) => h($old[$k] ?? '');
            echo '<h3 style="margin:14px 0 4px">' . h(t('blapp.buat_akun')) . '</h3><p class="small muted">' . h(t('blapp.buat_akun_ket')) . '</p>';
            echo '<form method="post" action="./?daftar=1" class="grid" style="gap:10px" autocomplete="on">' . $csrf . '<input type="hidden" name="act" value="daftar">';
            // kolom jebakan: tidak terlihat oleh manusia, biasanya diisi bot
            echo '<div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>';
            echo '<label class="f">' . h(t('blapp.nama')) . '<input class="inp" name="nama" maxlength="150" required value="' . $v('nama') . '" placeholder="' . h(t('bl.ph_nama_gelar')) . '"></label>';
            echo '<label class="f">' . h(t('blapp.email')) . '<input class="inp" type="email" name="email" maxlength="150" required value="' . $v('email') . '" autocomplete="email"></label>';
            echo '<label class="f">' . h(t('blapp.hp')) . '<input class="inp" type="tel" name="hp" maxlength="20" required value="' . $v('hp') . '" placeholder="' . h(t('blapp.ph_hp')) . '" autocomplete="tel"></label>';
            echo '<label class="f">' . h(t('blapp.instansi')) . '<input class="inp" name="instansi" maxlength="200" required value="' . $v('instansi') . '" placeholder="' . h(t('blapp.ph_instansi')) . '"></label>';
            echo '<label class="f">' . h(t('masuk.username')) . '<input class="inp" name="username" maxlength="40" required value="' . $v('username') . '" autocomplete="username"></label>';
            echo '<p class="small muted" style="margin:-6px 0 0">' . h(t('galat.username_format')) . '</p>';
            echo '<label class="f">' . h(t('masuk.password')) . pw_field('name="p1" autocomplete="new-password" required') . '</label>';
            echo '<label class="f">' . h(t('masuk.sandi_baru2')) . pw_field('name="p2" autocomplete="new-password" required') . '</label>';
            echo '<p class="small muted">' . h(t('masuk.syarat_sandi')) . '</p><button class="btn" type="submit">' . h(t('blapp.tombol_buat')) . '</button></form>';
            echo '<p class="small" style="margin-top:12px">' . h(t('blapp.sudah_punya')) . ' <a href="./">' . h(t('masuk.tombol')) . '</a></p>';
        } else {
            echo '<form method="post" action="./" class="grid" style="gap:10px">' . $csrf . '<input type="hidden" name="act" value="login">';
            echo '<label class="f">' . h(t('masuk.username')) . '<input class="inp" name="username" autocomplete="username" required autofocus></label>';
            echo '<label class="f">' . h(t('masuk.password')) . pw_field('name="password" autocomplete="current-password" required') . '</label>';
            echo '<button class="btn" type="submit">' . h(t('masuk.tombol')) . '</button></form>';
            echo '<p class="small" style="margin-top:12px">' . h(t('blapp.belum_punya')) . ' <a href="./?daftar=1">' . h(t('blapp.buat_akun')) . '</a></p>';
        }
        echo '</div></div><script src="assets/pw.js?v=' . SIPANDU_VERSION . '"></script></body></html>';
        exit;
    }

    bl_page_head($title);
    $boot = ['csrf' => csrf_token(), 'T' => sp_teks(), 'state' => bl_app_state($u), 'version' => SIPANDU_VERSION];
    echo '<div id="app"></div><script>window.SIPANDU=' . json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRESERVE_ZERO_FRACTION) . ';</script>';
    foreach (['client.js', 'bl-shell.js', 'belajar.js', 'pw.js'] as $js) echo '<script src="assets/' . $js . '?v=' . SIPANDU_VERSION . '"></script>';
    echo '</body></html>';
}

/* ---------- API JSON ---------- */
function bl_page_api(): void
{
    require_once __DIR__ . '/apihelp.php';
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    sp_send_security_headers();
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('galat.metode', [], 405);
        if (!sp_installed()) fail('galat.belum_terpasang', [], 503);
        sp_ensure_schema();
        sp_session_start();
        $d = isset($_POST['a']) ? $_POST : (json_decode((string)file_get_contents('php://input'), true) ?: []);
        if (!csrf_ok($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($d['csrf'] ?? null))) fail('galat.csrf', [], 403);
        $u = current_user();
        if (!$u) fail('galat.sesi_habis', [], 401);
        $a = (string)($d['a'] ?? '');
        if ($u['must_change'] && $a !== 'password_change') fail('galat.wajib_ganti_sandi', [], 403);
        $result = null;
        if ($a === 'state') $result = null;
        elseif ($a === 'password_change') {
            $o = (string)($d['old'] ?? ''); $n = (string)($d['new'] ?? '');
            need(password_verify($o, $u['pass_hash']), 'galat.sandi_lama_salah', 400);
            need(valid_new_password($n), 'galat.sandi_lemah', 400);
            need(!hash_equals($o, $n), 'galat.sandi_sama', 400);
            q('UPDATE bl_users SET pass_hash = ?, must_change = 0 WHERE id = ?', [password_hash($n, PASSWORD_DEFAULT), $u['id']]);
            audit('password_change');
            $result = ['ok' => true];
        } elseif (str_starts_with($a, 'bl_')) $result = bl_api($a, $d, $u);
        else fail('galat.aksi_tidak_dikenal');
        $fresh = q1('SELECT * FROM bl_users WHERE id = ?', [$u['id']]);
        echo jenc(['ok' => true, 'data' => $result, 'state' => bl_app_state($fresh)]);
    } catch (ApiError $e) {
        http_response_code($e->getCode() ?: 400);
        echo jenc(['ok' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('SIPANDU BL API: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo jenc(['ok' => false, 'error' => t('galat.server')]);
    }
}

/* ---------- berkas: surat tugas, materi, foto narasumber ---------- */
function bl_page_berkas(): void
{
    sp_session_start();
    $u = current_user();
    $j = (string)($_GET['j'] ?? ''); $id = (int)($_GET['id'] ?? 0);
    $path = ''; $type = 'application/pdf'; $name = 'berkas.pdf';
    if ($u && !$u['must_change']) {
        if ($j === 'surat') {
            // surat tugas: Admin, atau peserta pemiliknya
            $p = q1('SELECT * FROM bl_peserta WHERE id = ?', [$id]);
            if ($p && $p['surat'] && ($u['role'] === 'admin' || ($u['role'] === 'peserta' && (int)$u['id'] === (int)$p['user_id']))) {
                $path = data_dir('surat') . '/' . basename($p['surat']); $name = 'surat-tugas-' . $p['id'] . '.pdf';
            }
        } elseif ($j === 'materi') {
            // materi: staf kegiatan, atau peserta yang sudah menyelesaikan Pretest
            $m = q1('SELECT * FROM bl_materi WHERE id = ?', [$id]);
            $k = $m ? bl_keg((int)$m['keg_id']) : null;
            $ok = false;
            if ($k && bl_is_staff($u, $k)) $ok = true;
            elseif ($k && ($me = bl_me_peserta($u, (int)$k['id']))) $ok = (bool)qval("SELECT COUNT(*) FROM bl_selesai f JOIN bl_seg s ON s.id = f.seg_id WHERE f.peserta_id = ? AND s.keg_id = ? AND s.jenis = 'pretest'", [$me['id'], $k['id']]);
            if ($ok) { $path = data_dir('materi') . '/' . basename($m['file']); $name = preg_replace('/[^A-Za-z0-9 ._-]/', '', $m['judul']) . '.pdf'; }
        } elseif ($j === 'lap') {
            // Laporan Kegiatan: Fasilitator pemilik (id = kegiatan), atau lap = id laporan bagi Pembina/Admin yang berhak; f=pdf|docx
            $lapId = (int)($_GET['lap'] ?? 0);
            $r = $lapId ? bl_lap_by_id($lapId) : null;
            $k = $r ? bl_keg((int)$r['keg_id']) : bl_keg($id);
            if (!$r && $k && bl_lap_can($u, $k)) $r = bl_lap_row($u, $k);
            if ($k && $r && bl_lap_hak($u, $r, $k)['lihat']) {
                $L = bl_lap_cetak($r, $k);
                $safe = trim(preg_replace('/[^A-Za-z0-9 _-]/', '', $k['judul'])) ?: 'kegiatan';
                $docx = ($_GET['f'] ?? '') === 'docx';
                $bin = $docx ? bl_lap_docx($L) : bl_lap_pdf($L);
                audit('bl_lap_unduh', ['keg' => $k['id'], 'lap' => (int)$r['id'], 'f' => $docx ? 'docx' : 'pdf', 'draf' => !empty($L['draf'])]);
                header('Content-Type: ' . ($docx ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/pdf'));
                header('Content-Length: ' . strlen($bin));
                header('Cache-Control: private, no-store');
                header('X-Content-Type-Options: nosniff');
                header('Content-Disposition: attachment; filename="Laporan Kegiatan - ' . $safe . ($docx ? '.docx' : '.pdf') . '"');
                echo $bin;
                return;
            }
        } elseif ($j === 'profil') {
            $f = (string)qval('SELECT foto FROM bl_profil WHERE user_id = ?', [$id]);
            if ($f !== '') { $path = data_dir('profil') . '/' . basename($f); $type = 'image/jpeg'; $name = 'profil.jpg'; }
        }
    }
    if ($path === '' || !is_file($path)) { http_response_code(404); exit; }
    header('Content-Type: ' . $type);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    if ($type === 'application/pdf') header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
    readfile($path);
}

function bl_page_logout(): void
{
    sp_session_start();
    if (csrf_ok($_GET['t'] ?? null) && current_user()) {
        audit('logout');
        $_SESSION = [];
        session_regenerate_id(true);
    }
    header('Location: ./');
}

/* ---------- pemasangan: membuat akun Admin subdomain (token pemasangan dari konfigurasi; mengunci diri setelah selesai) ---------- */
function bl_page_install(): void
{
    sp_send_security_headers();
    header('Cache-Control: no-store');
    sp_watermark();
    $cfg = sp_config();
    $token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
    $okToken = !empty($cfg['install_token']) && hash_equals((string)$cfg['install_token'], $token);
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h(t('blapp.pasang_judul')) . '</title><link rel="stylesheet" href="assets/app.css"></head><body><div class="verify" style="max-width:640px">';
    echo '<h2>' . h(t('blapp.pasang_judul')) . '</h2>';
    $end = '</div></body></html>';
    if (!$okToken) { echo '<div class="big-status c-bad">' . h(t('pasang.token_salah')) . '</div>' . $end; return; }
    if (!sp_installed()) { echo '<div class="big-status c-bad">' . h(t('galat.belum_terpasang')) . '</div>' . $end; return; }
    sp_ensure_schema();
    if (qval("SELECT COUNT(*) FROM bl_users WHERE role = 'admin'")) { echo '<div class="big-status c-ok">' . h(t('pasang.sudah')) . '</div><p><a href="./">' . h(t('pasang.ke_aplikasi')) . '</a></p>' . $end; return; }
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $p1 = (string)($_POST['p1'] ?? ''); $p2 = (string)($_POST['p2'] ?? '');
        if ($p1 !== $p2) $err = t('galat.sandi_tidak_sama');
        elseif (!valid_new_password($p1) || mb_strlen($p1) < 10) $err = t('pasang.sandi_admin_syarat');
        else {
            try {
                sp_insert('bl_users', ['role' => 'admin', 'nama' => t('seed.nama_admin'), 'username' => 'admin', 'pass_hash' => password_hash($p1, PASSWORD_DEFAULT), 'must_change' => 0, 'aktif' => 1, 'created_at' => now_str()]);
                audit('bl_pasang');
            } catch (PDOException $e) { /* pengiriman ganda: akun sudah dibuat */ }
            echo '<div class="big-status c-ok">' . h(t('pasang.selesai')) . '</div><p>' . h(t('blapp.pasang_selesai')) . '</p><p><a class="btn" href="./">' . h(t('pasang.ke_aplikasi')) . '</a></p>' . $end;
            return;
        }
    }
    if ($err) echo '<div class="note bad">' . h($err) . '</div>';
    echo '<form method="post" class="grid" style="gap:10px;margin-top:14px"><input type="hidden" name="token" value="' . h($token) . '">';
    echo '<p><b>' . h(t('pasang.akun_admin')) . '</b> <code>admin</code></p>';
    echo '<label class="f">' . h(t('pasang.sandi_admin')) . pw_field('name="p1" autocomplete="new-password" required') . '</label>';
    echo '<label class="f">' . h(t('masuk.sandi_baru2')) . pw_field('name="p2" autocomplete="new-password" required') . '</label>';
    echo '<p class="small muted">' . h(t('pasang.sandi_admin_syarat')) . '</p><button class="btn" type="submit">' . h(t('pasang.tombol')) . '</button></form><script src="assets/pw.js"></script>' . $end;
}
