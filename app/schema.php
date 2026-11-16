<?php
/* Si PANDU – skema basis data (MariaDB/MySQL untuk hosting, SQLite untuk uji otomatis) */
declare(strict_types=1);

function sp_schema_sql(string $driver): array
{
    $my = $driver === 'mysql';
    $pk = $my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $txt = $my ? 'LONGTEXT' : 'TEXT';
    $eng = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $s = [];
    $s[] = "CREATE TABLE IF NOT EXISTS settings (k VARCHAR(64) PRIMARY KEY, v $txt)$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS puskesmas (id $pk, kode VARCHAR(20) NOT NULL UNIQUE, nama VARCHAR(150) NOT NULL, aktif INT NOT NULL DEFAULT 1)$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS users (id $pk, role VARCHAR(10) NOT NULL, nama VARCHAR(150) NOT NULL, username VARCHAR(60) NOT NULL UNIQUE, pass_hash VARCHAR(255) NOT NULL, must_change INT NOT NULL DEFAULT 1, puskesmas_id INT NULL, nip VARCHAR(40) NULL, jabatan VARCHAR(120) NULL, aktif INT NOT NULL DEFAULT 1, created_at VARCHAR(19) NULL, last_login VARCHAR(19) NULL)$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS params (id $pk, nama VARCHAR(120) NOT NULL, kategori VARCHAR(20) NOT NULL, satuan VARCHAR(30) NULL, spec_src VARCHAR(10) NOT NULL DEFAULT 'lain', level VARCHAR(12) NOT NULL DEFAULT 'Desirable', cvi DOUBLE NULL, cvg DOUBLE NULL, bv_src VARCHAR(120) NULL, tea DOUBLE NULL, tea_src VARCHAR(120) NULL, tea_ok INT NOT NULL DEFAULT 0)$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS alat (id $pk, puskesmas_id INT NOT NULL, nama VARCHAR(150) NOT NULL, merk VARCHAR(150) NULL, kategori VARCHAR(20) NOT NULL, aktif INT NOT NULL DEFAULT 1)$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS sheets (id $pk, puskesmas_id INT NOT NULL, param_id INT NOT NULL, alat_id INT NOT NULL, kategori VARCHAR(20) NOT NULL, max_levels INT NOT NULL, levels $txt NOT NULL, metode VARCHAR(120) NULL, reagen VARCHAR(150) NULL, reagen_lot VARCHAR(60) NULL, satuan VARCHAR(30) NULL, status VARCHAR(10) NOT NULL DEFAULT 'aktif', created_at VARCHAR(19) NULL, closed_at VARCHAR(19) NULL, close_reason VARCHAR(150) NULL, prev_id INT NULL, reagen_events $txt NULL)$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS runs (id $pk, sheet_id INT NOT NULL, tgl VARCHAR(10) NOT NULL, seq INT NOT NULL DEFAULT 0, vals $txt NOT NULL, excluded INT NOT NULL DEFAULT 0, reason $txt NULL, note $txt NULL, by_user INT NULL, by_name VARCHAR(150) NULL, created_at VARCHAR(19) NULL, eval $txt NULL)$eng";
    $s[] = "CREATE INDEX idx_runs_sheet ON runs (sheet_id, tgl)";
    $s[] = "CREATE TABLE IF NOT EXISTS comments (id $pk, target VARCHAR(10) NOT NULL, target_id INT NOT NULL, user_id INT NULL, role VARCHAR(10) NULL, nama VARCHAR(150) NULL, at VARCHAR(19) NULL, body $txt NOT NULL)$eng";
    $s[] = "CREATE INDEX idx_comments_target ON comments (target, target_id)";
    $s[] = "CREATE TABLE IF NOT EXISTS cases (id $pk, sheet_id INT NOT NULL, run_id INT NOT NULL, puskesmas_id INT NOT NULL, run_date VARCHAR(10) NOT NULL, type VARCHAR(12) NOT NULL, indication VARCHAR(30) NULL, note VARCHAR(120) NULL, violations $txt NULL, scope $txt NULL, priority $txt NULL, mode VARCHAR(12) NULL, state VARCHAR(12) NOT NULL, findings $txt NULL, corrections $txt NULL, verify_run_id INT NULL, verify_date VARCHAR(10) NULL, log $txt NULL, created_at VARCHAR(19) NULL)$eng";
    $s[] = "CREATE INDEX idx_cases_pkm ON cases (puskesmas_id, state)";
    $s[] = "CREATE TABLE IF NOT EXISTS notifs (id $pk, to_key VARCHAR(20) NOT NULL, at VARCHAR(19) NOT NULL, body $txt NOT NULL, link VARCHAR(60) NULL)$eng";
    $s[] = "CREATE INDEX idx_notifs_to ON notifs (to_key)";
    $s[] = "CREATE TABLE IF NOT EXISTS notif_reads (notif_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (notif_id, user_id))$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS risks (id $pk, puskesmas_id INT NOT NULL, aspect VARCHAR(12) NOT NULL, alat_id INT NULL, tgl VARCHAR(10) NOT NULL, by_name VARCHAR(150) NULL, items $txt NOT NULL)$eng";
    $s[] = "CREATE INDEX idx_risks_pkm ON risks (puskesmas_id, aspect)";
    $s[] = "CREATE TABLE IF NOT EXISTS kops (owner_key VARCHAR(20) PRIMARY KEY, file VARCHAR(100) NOT NULL, info $txt NULL, updated_at VARCHAR(19) NULL)$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS audit_log (id $pk, at VARCHAR(19) NOT NULL, user_id INT NULL, action VARCHAR(60) NOT NULL, detail $txt NULL, ip VARCHAR(45) NULL)$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS login_attempts (id $pk, username VARCHAR(60) NULL, ip VARCHAR(45) NULL, at VARCHAR(19) NOT NULL, ok INT NOT NULL)$eng";
    $s[] = "CREATE INDEX idx_login_at ON login_attempts (at)";
    return $s;
}

function sp_create_schema(PDO $pdo, string $driver): void
{
    foreach (sp_schema_sql($driver) as $sql) {
        try { $pdo->exec($sql); }
        catch (PDOException $e) {
            // indeks yang sudah ada diabaikan (pemasangan ulang aman)
            if (!str_starts_with($sql, 'CREATE INDEX')) throw $e;
        }
    }
}

/** Tabel yang wajib ada (dipakai uji mandiri) */
function sp_required_tables(): array
{
    return ['settings', 'puskesmas', 'users', 'params', 'alat', 'sheets', 'runs', 'comments', 'cases', 'notifs', 'notif_reads', 'risks', 'kops', 'audit_log', 'login_attempts'];
}
