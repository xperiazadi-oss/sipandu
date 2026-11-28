<?php
/* Si PANDU – skema basis data (MariaDB/MySQL untuk hosting, SQLite untuk uji otomatis) */
// Tanpa strict_types: nilai dari basis data selalu berupa string dan dikonversi otomatis oleh PHP.

function sp_schema_sql(string $driver): array
{
    $my = $driver === 'mysql';
    $pk = $my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $txt = $my ? 'LONGTEXT' : 'TEXT';
    $eng = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $s = [];
    $s[] = "CREATE TABLE IF NOT EXISTS settings (k VARCHAR(64) PRIMARY KEY, v $txt)$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS puskesmas (id $pk, kode VARCHAR(20) NOT NULL UNIQUE, nama VARCHAR(150) NOT NULL, aktif INT NOT NULL DEFAULT 1, setup_at VARCHAR(19) NULL)$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS users (id $pk, role VARCHAR(10) NOT NULL, nama VARCHAR(150) NOT NULL, username VARCHAR(60) NOT NULL UNIQUE, pass_hash VARCHAR(255) NOT NULL, must_change INT NOT NULL DEFAULT 1, puskesmas_id INT NULL, nip VARCHAR(40) NULL, jabatan VARCHAR(120) NULL, aktif INT NOT NULL DEFAULT 1, created_at VARCHAR(19) NULL, last_login VARCHAR(19) NULL)$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS params (id $pk, nama VARCHAR(120) NOT NULL, kategori VARCHAR(20) NOT NULL, satuan VARCHAR(30) NULL, spec_src VARCHAR(10) NOT NULL DEFAULT 'lain', level VARCHAR(12) NOT NULL DEFAULT 'Desirable', cvi DOUBLE NULL, cvg DOUBLE NULL, bv_src VARCHAR(120) NULL, tea DOUBLE NULL, tea_src VARCHAR(120) NULL, tea_ok INT NOT NULL DEFAULT 0)$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS alat (id $pk, puskesmas_id INT NOT NULL, nama VARCHAR(150) NOT NULL, merk VARCHAR(150) NULL, kategori VARCHAR(20) NOT NULL, aktif INT NOT NULL DEFAULT 1, tipe VARCHAR(150) NULL, no_seri VARCHAR(80) NULL, prinsip VARCHAR(150) NULL, tahun VARCHAR(4) NULL, kondisi VARCHAR(12) NULL, kal_terakhir VARCHAR(10) NULL, kal_berikut VARCHAR(10) NULL, created_at VARCHAR(19) NULL)$eng";
    // Sumber Daya: puskesmas_id = 0 berarti Labkesmas (akun Pembina)
    $s[] = "CREATE TABLE IF NOT EXISTS personel (id $pk, puskesmas_id INT NOT NULL, nama VARCHAR(150) NOT NULL, nip VARCHAR(40) NULL, jabatan VARCHAR(150) NULL, pendidikan VARCHAR(150) NULL, str_no VARCHAR(80) NULL, str_berlaku VARCHAR(40) NULL, sip_no VARCHAR(80) NULL, pelatihan $txt NULL, foto_id INT NULL, foto_at VARCHAR(19) NULL, aktif INT NOT NULL DEFAULT 1, created_at VARCHAR(19) NULL)$eng";
    $s[] = "CREATE INDEX idx_personel_pkm ON personel (puskesmas_id)";
    $s[] = "CREATE TABLE IF NOT EXISTS personel_foto (id $pk, personel_id INT NOT NULL, file VARCHAR(100) NOT NULL, at VARCHAR(19) NOT NULL, by_name VARCHAR(150) NULL)$eng";
    $s[] = "CREATE INDEX idx_pfoto_personel ON personel_foto (personel_id)";
    $s[] = "CREATE TABLE IF NOT EXISTS bhp (id $pk, puskesmas_id INT NOT NULL, jenis VARCHAR(10) NOT NULL, nama VARCHAR(150) NOT NULL, produsen VARCHAR(150) NULL, kategori VARCHAR(20) NOT NULL, level VARCHAR(60) NULL, kemasan VARCHAR(80) NULL, lot VARCHAR(60) NOT NULL, ed VARCHAR(10) NULL, suhu VARCHAR(60) NULL, status VARCHAR(10) NOT NULL DEFAULT 'tersedia', prev_id INT NULL, created_at VARCHAR(19) NULL)$eng";
    $s[] = "CREATE INDEX idx_bhp_pkm ON bhp (puskesmas_id, jenis)";
    // Subdomain pembelajaran (Peningkatan Kapasitas Kompetensi SDM): akun, log, dan data terpisah dari Si PANDU
    $s[] = "CREATE TABLE IF NOT EXISTS bl_users (id $pk, role VARCHAR(10) NOT NULL, nama VARCHAR(150) NOT NULL, username VARCHAR(60) NOT NULL UNIQUE, pass_hash VARCHAR(255) NOT NULL, must_change INT NOT NULL DEFAULT 0, email VARCHAR(150) NULL, hp VARCHAR(30) NULL, instansi VARCHAR(200) NULL, aktif INT NOT NULL DEFAULT 1, created_at VARCHAR(19) NULL, last_login VARCHAR(19) NULL)$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS bl_audit (id $pk, at VARCHAR(19) NOT NULL, user_id INT NULL, action VARCHAR(60) NOT NULL, detail $txt NULL, ip VARCHAR(45) NULL)$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS bl_keg (id $pk, judul VARCHAR(200) NOT NULL, penyelenggara VARCHAR(200) NULL, lokasi VARCHAR(200) NULL, tgl_mulai VARCHAR(10) NULL, tgl_selesai VARCHAR(10) NULL, deskripsi $txt NULL, kuota INT NOT NULL DEFAULT 0, status VARCHAR(10) NOT NULL DEFAULT 'draf', daftar_buka INT NOT NULL DEFAULT 0, pembuka $txt NULL, fasil $txt NULL, pengawas $txt NULL, created_at VARCHAR(19) NULL, arsip_at VARCHAR(19) NULL)$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS bl_seg (id $pk, keg_id INT NOT NULL, urut INT NOT NULL, jenis VARCHAR(10) NOT NULL, judul VARCHAR(200) NULL, pic_id INT NULL, pic_nama VARCHAR(150) NULL, tanggal VARCHAR(10) NULL, jam_mulai VARCHAR(5) NULL, jam_selesai VARCHAR(5) NULL, sesi $txt NULL, status VARCHAR(10) NOT NULL DEFAULT 'belum', dibuka_at VARCHAR(19) NULL, ditutup_at VARCHAR(19) NULL)$eng";
    $s[] = "CREATE INDEX idx_blseg_keg ON bl_seg (keg_id, urut)";
    $s[] = "CREATE TABLE IF NOT EXISTS bl_peserta (id $pk, keg_id INT NOT NULL, user_id INT NOT NULL, nama VARCHAR(200) NOT NULL, jabatan VARCHAR(150) NULL, nip VARCHAR(60) NULL, instansi VARCHAR(200) NULL, email VARCHAR(150) NULL, hp VARCHAR(30) NULL, surat VARCHAR(100) NULL, status VARCHAR(10) NOT NULL DEFAULT 'menunggu', pesan $txt NULL, kirim_at VARCHAR(19) NULL, verif_at VARCHAR(19) NULL, verif_by VARCHAR(150) NULL, UNIQUE (keg_id, user_id))$eng";
    $s[] = "CREATE INDEX idx_blpes_user ON bl_peserta (user_id)";
    $s[] = "CREATE TABLE IF NOT EXISTS bl_soal (id $pk, keg_id INT NOT NULL, urut INT NOT NULL, teks $txt NOT NULL, a $txt NOT NULL, b $txt NOT NULL, c $txt NOT NULL, d $txt NOT NULL, kunci VARCHAR(1) NOT NULL)$eng";
    $s[] = "CREATE INDEX idx_blsoal_keg ON bl_soal (keg_id, urut)";
    $s[] = "CREATE TABLE IF NOT EXISTS bl_jawab (id $pk, peserta_id INT NOT NULL, seg_id INT NOT NULL, soal_id INT NOT NULL, pilih VARCHAR(1) NOT NULL, at VARCHAR(19) NOT NULL, UNIQUE (peserta_id, seg_id, soal_id))$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS bl_selesai (id $pk, peserta_id INT NOT NULL, seg_id INT NOT NULL, at VARCHAR(19) NOT NULL, UNIQUE (peserta_id, seg_id))$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS bl_aspek (id $pk, keg_id INT NOT NULL, jenis VARCHAR(10) NOT NULL, seg_id INT NULL, nama VARCHAR(200) NOT NULL, bawaan INT NOT NULL DEFAULT 70)$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS bl_nilai (id $pk, peserta_id INT NOT NULL, aspek_id INT NOT NULL, nilai INT NOT NULL, UNIQUE (peserta_id, aspek_id))$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS bl_materi (id $pk, keg_id INT NOT NULL, seg_id INT NULL, judul VARCHAR(200) NOT NULL, file VARCHAR(100) NOT NULL, ukuran INT NOT NULL, at VARCHAR(19) NOT NULL, by_name VARCHAR(150) NULL)$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS bl_eval (id $pk, peserta_id INT NOT NULL, narsum_id INT NOT NULL, aspek INT NOT NULL, nilai INT NOT NULL, UNIQUE (peserta_id, narsum_id, aspek))$eng";
    // Laporan Kegiatan: satu laporan per Fasilitator per kegiatan
    $s[] = "CREATE TABLE IF NOT EXISTS bl_laporan (id $pk, keg_id INT NOT NULL, user_id INT NOT NULL, pelaksana VARCHAR(200) NULL, tor VARCHAR(100) NULL, pendahuluan $txt NULL, mekanisme $txt NULL, kendala $txt NULL, kesimpulan $txt NULL, rekomendasi $txt NULL, pembina_id INT NULL, ttd_tempat VARCHAR(100) NULL, ttd_tanggal VARCHAR(10) NULL, updated_at VARCHAR(19) NULL, UNIQUE (keg_id, user_id))$eng";
    // dokumen terbit yang dapat diverifikasi di VeriDoc (isi dikunci sebagai snapshot) dan pengaturan jenis dokumen
    $s[] = "CREATE TABLE IF NOT EXISTS bl_dok (id $pk, lap_id INT NOT NULL, keg_id INT NOT NULL, jenis VARCHAR(40) NOT NULL, revisi INT NOT NULL DEFAULT 0, nomor VARCHAR(120) NOT NULL, kode VARCHAR(14) NOT NULL UNIQUE, akses VARCHAR(9) NULL, sifat VARCHAR(8) NOT NULL, kendali VARCHAR(12) NOT NULL, judul VARCHAR(400) NOT NULL, penerbit VARCHAR(200) NOT NULL, pengesah_id INT NULL, pengesah_nama VARCHAR(150) NOT NULL, pengesah_jabatan VARCHAR(150) NULL, fasil_nama VARCHAR(150) NULL, berlaku_sampai VARCHAR(10) NULL, terbit VARCHAR(10) NOT NULL, status VARCHAR(12) NOT NULL, status_sejak VARCHAR(10) NOT NULL, alasan $txt NULL, menggantikan_id INT NULL, pengganti_id INT NULL, snapshot $txt NOT NULL, isi $txt NOT NULL, sync VARCHAR(8) NOT NULL DEFAULT 'tunda', created_at VARCHAR(19) NULL)$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS bl_jenis_dok (id $pk, kode VARCHAR(40) NOT NULL UNIQUE, nama VARCHAR(150) NOT NULL, sifat VARCHAR(8) NOT NULL, format VARCHAR(120) NOT NULL, next_no INT NOT NULL DEFAULT 1, tahun INT NOT NULL, penerbit VARCHAR(200) NOT NULL)$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS bl_profil (user_id INT PRIMARY KEY, instansi VARCHAR(200) NULL, jabatan VARCHAR(200) NULL, ringkasan $txt NULL, foto VARCHAR(100) NULL, updated_at VARCHAR(19) NULL)$eng";
    $s[] = "CREATE TABLE IF NOT EXISTS sheets (id $pk, puskesmas_id INT NOT NULL, param_id INT NOT NULL, alat_id INT NOT NULL, kategori VARCHAR(20) NOT NULL, max_levels INT NOT NULL, levels $txt NOT NULL, metode VARCHAR(120) NULL, reagen VARCHAR(150) NULL, reagen_lot VARCHAR(60) NULL, satuan VARCHAR(30) NULL, status VARCHAR(10) NOT NULL DEFAULT 'aktif', created_at VARCHAR(19) NULL, closed_at VARCHAR(19) NULL, close_reason VARCHAR(150) NULL, prev_id INT NULL, reagen_events $txt NULL, reagen_bhp_id INT NULL)$eng";
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

/** Versi skema saat ini; dinaikkan setiap ada kolom/tabel baru */
const SP_SCHEMA_VERSION = 8;

/** Kolom yang ditambahkan setelah versi 1 (untuk basis data yang sudah terpasang) */
function sp_added_columns(string $driver): array
{
    $txt = $driver === 'mysql' ? 'LONGTEXT' : 'TEXT';
    return [
        'puskesmas' => ['setup_at' => 'VARCHAR(19) NULL'],
        'alat' => ['tipe' => 'VARCHAR(150) NULL', 'no_seri' => 'VARCHAR(80) NULL', 'prinsip' => 'VARCHAR(150) NULL', 'tahun' => 'VARCHAR(4) NULL', 'kondisi' => 'VARCHAR(12) NULL', 'kal_terakhir' => 'VARCHAR(10) NULL', 'kal_berikut' => 'VARCHAR(10) NULL', 'created_at' => 'VARCHAR(19) NULL'],
        'sheets' => ['reagen_bhp_id' => 'INT NULL'],
        'users' => ['personel_id' => 'INT NULL'],
        'bl_laporan' => ['status' => "VARCHAR(12) NOT NULL DEFAULT 'draf'", 'kendali' => 'VARCHAR(12) NULL', 'berlaku_mode' => 'VARCHAR(8) NULL', 'berlaku_sampai' => 'VARCHAR(10) NULL', 'catatan_kembali' => "$txt NULL", 'ganti_dok_id' => 'INT NULL', 'diajukan_at' => 'VARCHAR(19) NULL'],
        'personel' => ['pin_hash' => 'VARCHAR(255) NULL', 'pin_at' => 'VARCHAR(19) NULL', 'pin_gagal' => 'INT NOT NULL DEFAULT 0', 'pin_kunci' => 'INT NULL'],
    ];
}

/** Migrasi aman (dapat diulang): membuat tabel baru dan menambah kolom yang belum ada */
function sp_migrate(PDO $pdo, string $driver): void
{
    sp_create_schema($pdo, $driver);
    foreach (sp_added_columns($driver) as $table => $cols) {
        foreach ($cols as $col => $def) {
            try { $pdo->query("SELECT $col FROM $table LIMIT 0"); continue; } catch (PDOException $e) { /* kolom belum ada */ }
            try { $pdo->exec("ALTER TABLE $table ADD COLUMN $col $def"); }
            catch (PDOException $e) {
                // permintaan lain yang berjalan bersamaan mungkin sudah menambahkannya
                try { $pdo->query("SELECT $col FROM $table LIMIT 0"); } catch (PDOException $e2) { throw $e; }
            }
        }
    }
}

/** Tabel yang wajib ada (dipakai uji mandiri) */
function sp_required_tables(): array
{
    return ['settings', 'puskesmas', 'users', 'params', 'alat', 'sheets', 'runs', 'comments', 'cases', 'notifs', 'notif_reads', 'risks', 'kops', 'audit_log', 'login_attempts', 'personel', 'personel_foto', 'bhp', 'bl_users', 'bl_audit', 'bl_laporan', 'bl_dok', 'bl_jenis_dok', 'bl_keg', 'bl_seg', 'bl_peserta', 'bl_soal', 'bl_jawab', 'bl_selesai', 'bl_aspek', 'bl_nilai', 'bl_materi', 'bl_eval', 'bl_profil'];
}

/** Nama resmi 27 Puskesmas (urut abjad = kode P01–P27) */
const SP_PKM_NAMA = ['Baru Ilir', 'Baru Tengah', 'Baru Ulu', 'Batu Ampar', 'Damai', 'Graha Indah', 'Gunung Bahagia', 'Gunung Samarinda', 'Gunung Sari Ilir',
    'Gunung Sari Ulu', 'Karang Jati', 'Karang Joang', 'Karang Rejo', 'Kariangau', 'Klandasan Ilir', 'Lamaru', 'Manggar', 'Manggar Baru', 'Marga Sari',
    'Margo Mulyo', 'Mekar Sari', 'Muara Rapak', 'Prapatan', 'Sepinggan Baru', 'Sumber Rejo', 'Telaga Sari', 'Teritip'];

/** Langkah sebelum tabel dibuat: tabel pembelajaran versi 4 (terikat akun Si PANDU) dibuang agar dibuat ulang dengan struktur subdomain */
function sp_migrate_pre(int $from): void
{
    if ($from >= 4 && $from < 5) {
        foreach (['bl_keg', 'bl_seg', 'bl_peserta', 'bl_soal', 'bl_jawab', 'bl_selesai', 'bl_aspek', 'bl_nilai', 'bl_materi', 'bl_eval', 'bl_profil'] as $tb) sp_db()->exec("DROP TABLE IF EXISTS $tb");
    }
}

/** Perubahan data sekali jalan saat naik versi skema (aman diulang) */
function sp_migrate_data(int $from): void
{
    if ($from < 3) {
        // nama sementara "Puskesmas Binaan NN" → nama resmi; hanya bila belum diganti Admin
        foreach (SP_PKM_NAMA as $i => $nm) {
            $kode = sprintf('P%02d', $i + 1);
            $tmp = t('seed.nama_pkm', ['n' => sprintf('%02d', $i + 1)]);
            $p = q1('SELECT * FROM puskesmas WHERE kode = ? AND nama = ?', [$kode, $tmp]);
            if (!$p) continue;
            $baru = 'Puskesmas ' . $nm;
            q('UPDATE puskesmas SET nama = ? WHERE id = ?', [$baru, $p['id']]);
            q('UPDATE users SET nama = ? WHERE puskesmas_id = ? AND role = ? AND nama = ?', [t('seed.nama_lab', ['pkm' => $baru]), $p['id'], 'binaan', t('seed.nama_lab', ['pkm' => $tmp])]);
        }
    }
    if ($from >= 4 && $from < 5) {
        // pembelajaran pindah ke subdomain dengan akun tersendiri: Fasilitator & sub-akun personel di Si PANDU dinonaktifkan,
        // berkas pembelajaran lama dihapus (tabelnya sudah dibuat ulang kosong oleh sp_migrate_pre)
        q("UPDATE users SET aktif = 0 WHERE role IN ('fasil', 'peserta')");
        $dd = rtrim(sp_config()['data_dir'], '/');
        foreach (['surat', 'materi', 'profil'] as $sub) foreach (glob($dd . '/' . $sub . '/*') ?: [] as $f) if (is_file($f)) @unlink($f);
    }
}
