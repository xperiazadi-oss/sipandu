<?php
/* Si PANDU – inti: konfigurasi, basis data, sesi, keamanan, utilitas */
// Tanpa strict_types: nilai dari basis data selalu berupa string dan dikonversi otomatis oleh PHP.

const SIPANDU_VERSION = '1.1.2-tahap1';
const SIPANDU_MIN_PHP = '8.1.0';

function sp_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $path = getenv('SIPANDU_CONFIG') ?: dirname(__DIR__, 2) . '/sipandu-config.php';
    if (!is_file($path)) {
        http_response_code(503);
        exit('Konfigurasi belum tersedia.');
    }
    $cfg = require $path;
    return $cfg;
}

function sp_teks(): array
{
    static $t = null;
    if ($t === null) $t = require __DIR__ . '/teks.php';
    return $t;
}

/** Ambil teks dengan kunci bertitik, mis. t('notif.kasus_baru', ['jenis' => 'Tertolak']) */
function t(string $key, array $vars = []): string
{
    $node = sp_teks();
    foreach (explode('.', $key) as $p) {
        if (!is_array($node) || !array_key_exists($p, $node)) return $key;
        $node = $node[$p];
    }
    $s = is_string($node) ? $node : $key;
    foreach ($vars as $k => $v) $s = str_replace('{' . $k . '}', (string)$v, $s);
    return $s;
}

function sp_db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = sp_config()['db'];
    $pdo = new PDO($c['dsn'], $c['user'] ?? null, $c['pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        // Nilai kolom selalu dikembalikan sebagai string di semua driver, agar perilaku di hosting (MariaDB) sama persis dengan pengujian lokal
        PDO::ATTR_STRINGIFY_FETCHES => true,
    ]);
    if (sp_driver() === 'sqlite') $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

function sp_driver(): string
{
    return str_starts_with(sp_config()['db']['dsn'], 'sqlite') ? 'sqlite' : 'mysql';
}

function q(string $sql, array $p = []): PDOStatement
{
    $st = sp_db()->prepare($sql);
    $st->execute($p);
    return $st;
}
function q1(string $sql, array $p = []): ?array
{
    $r = q($sql, $p)->fetch();
    return $r === false ? null : $r;
}
function qall(string $sql, array $p = []): array
{
    return q($sql, $p)->fetchAll();
}
function qval(string $sql, array $p = [])
{
    $v = q($sql, $p)->fetchColumn();
    return $v === false ? null : $v;
}
function sp_insert(string $table, array $row): int
{
    $cols = array_keys($row);
    q('INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($row));
    return (int)sp_db()->lastInsertId();
}
function sp_update(string $table, int $id, array $row): void
{
    if (!$row) return;
    $set = implode(',', array_map(fn($c) => "$c = ?", array_keys($row)));
    q("UPDATE $table SET $set WHERE id = ?", [...array_values($row), $id]);
}

function now_str(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar')))->format('Y-m-d H:i');
}
function today_str(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar')))->format('Y-m-d');
}

function jdec(?string $s, $def = [])
{
    if ($s === null || $s === '') return $def;
    $v = json_decode($s, true);
    return $v === null ? $def : $v;
}
function jenc($v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
}

function setting(string $k, $def = null)
{
    $v = qval('SELECT v FROM settings WHERE k = ?', [$k]);
    return $v === null ? $def : jdec($v, $def);
}
function set_setting(string $k, $v): void
{
    if (qval('SELECT COUNT(*) FROM settings WHERE k = ?', [$k])) q('UPDATE settings SET v = ? WHERE k = ?', [jenc($v), $k]);
    else q('INSERT INTO settings (k, v) VALUES (?, ?)', [$k, jenc($v)]);
}
function sp_installed(): bool
{
    try { return (bool)setting('installed_at'); } catch (Throwable $e) { return false; }
}

/** Menjalankan migrasi skema sekali bila versi skema terpasang lebih lama */
function sp_ensure_schema(): void
{
    require_once __DIR__ . '/schema.php';
    if ((int)setting('schema_v', 1) >= SP_SCHEMA_VERSION) return;
    sp_migrate(sp_db(), sp_driver());
    set_setting('schema_v', SP_SCHEMA_VERSION);
}

/* ---------- Sesi & keamanan ---------- */
function sp_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
function sp_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('SIPANDUSESS');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => sp_https(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    $idle = 2 * 3600;
    if (!empty($_SESSION['uid']) && (time() - ($_SESSION['last'] ?? 0)) > $idle) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last'] = time();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function csrf_token(): string
{
    return $_SESSION['csrf'] ?? '';
}
function csrf_ok(?string $tok): bool
{
    return is_string($tok) && $tok !== '' && hash_equals($_SESSION['csrf'] ?? '', $tok);
}
function sp_send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}

function current_user(): ?array
{
    static $u = false;
    if ($u !== false) return $u;
    $id = (int)($_SESSION['uid'] ?? 0);
    $u = $id ? q1('SELECT * FROM users WHERE id = ? AND aktif = 1', [$id]) : null;
    return $u;
}
function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}
function audit(string $action, $detail = null): void
{
    $u = current_user();
    sp_insert('audit_log', ['at' => now_str(), 'user_id' => $u['id'] ?? null, 'action' => $action, 'detail' => $detail === null ? null : (is_string($detail) ? $detail : jenc($detail)), 'ip' => client_ip()]);
}

/* Pembatasan percobaan masuk dalam 15 menit: 5 gagal per nama pengguna, 20 gagal per IP */
function login_locked(string $username): bool
{
    $since = (new DateTimeImmutable('-15 minutes', new DateTimeZone('Asia/Makassar')))->format('Y-m-d H:i:s');
    // per nama pengguna: 5 gagal; per IP: 20 gagal (satu kantor bisa berbagi IP)
    $byUser = (int)qval('SELECT COUNT(*) FROM login_attempts WHERE ok = 0 AND at >= ? AND username = ?', [$since, $username]);
    $byIp = (int)qval('SELECT COUNT(*) FROM login_attempts WHERE ok = 0 AND at >= ? AND ip = ?', [$since, client_ip()]);
    return $byUser >= 5 || $byIp >= 20;
}
function login_record(string $username, bool $ok): void
{
    sp_insert('login_attempts', ['username' => $username, 'ip' => client_ip(), 'at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar')))->format('Y-m-d H:i:s'), 'ok' => $ok ? 1 : 0]);
}
function temp_password(): string
{
    $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < 10; $i++) $s .= $alpha[random_int(0, strlen($alpha) - 1)];
    return $s;
}
function valid_new_password(string $p): bool
{
    return mb_strlen($p) >= 8 && preg_match('/[A-Za-z]/', $p) && preg_match('/\d/', $p);
}

/** Ikon mata untuk kolom kata sandi (sandi terlihat hanya selama ditekan-tahan; lihat assets/pw.js) */
function pw_eye(): string
{
    $l = h(t('masuk.tahan_lihat'));
    return '<button type="button" class="pw-eye" title="' . $l . '" aria-label="' . $l . '"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" fill="currentColor"/></svg></button>';
}
function pw_field(string $attrs): string
{
    return '<span class="pw"><input class="inp" type="password" ' . $attrs . '>' . pw_eye() . '</span>';
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function data_dir(string $sub = ''): string
{
    $d = rtrim(sp_config()['data_dir'], '/') . ($sub ? '/' . $sub : '');
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}
