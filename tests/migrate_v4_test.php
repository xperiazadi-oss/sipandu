<?php
/* Uji migrasi v4 → v5: pembelajaran pindah ke subdomain. Tabel pembelajaran lama dibuang & dibuat ulang kosong,
   Fasilitator & sub-akun peserta Si PANDU dinonaktifkan, data Si PANDU lain utuh. */
$db = sys_get_temp_dir() . '/sp_mig4_' . getmypid() . '.sqlite';
@unlink($db);
$dd = sys_get_temp_dir() . '/sp_mig4_data_' . getmypid();
@mkdir($dd . '/surat', 0777, true); @mkdir($dd . '/materi', 0777, true); @mkdir($dd . '/foto', 0777, true);
file_put_contents($dd . '/surat/lama.pdf', '%PDF-1.4'); file_put_contents($dd . '/materi/lama.pdf', '%PDF-1.4'); file_put_contents($dd . '/foto/personel.jpg', 'x');
$cfg = sys_get_temp_dir() . '/sp_mig4_cfg_' . getmypid() . '.php';
file_put_contents($cfg, "<?php return ['db' => ['dsn' => 'sqlite:$db'], 'data_dir' => '$dd'];");
putenv("SIPANDU_CONFIG=$cfg");
$src = file_get_contents(__DIR__ . '/data/schema_v4.php');
$src = preg_replace('/^.*?function sp_schema_sql/s', 'function v4_schema_sql', $src);
$src = substr($src, 0, strpos($src, 'function sp_create_schema'));
eval($src);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/domain.php';
$pdo = sp_db();
foreach (v4_schema_sql('sqlite') as $sql) { try { $pdo->exec($sql); } catch (PDOException $e) {} }
$pdo->exec('ALTER TABLE users ADD COLUMN personel_id INT NULL');
set_setting('installed_at', now_str()); set_setting('schema_v', 4);
$pid = sp_insert('puskesmas', ['kode' => 'P01', 'nama' => 'Puskesmas Baru Ilir', 'aktif' => 1]);
$per = sp_insert('personel', ['puskesmas_id' => $pid, 'nama' => 'Ani', 'aktif' => 1]);
$b = sp_insert('users', ['role' => 'binaan', 'nama' => 'Lab', 'username' => 'pkm01', 'pass_hash' => 'x', 'must_change' => 0, 'puskesmas_id' => $pid, 'aktif' => 1]);
$sub = sp_insert('users', ['role' => 'peserta', 'nama' => 'Ani', 'username' => 'pkm01.ani', 'pass_hash' => 'x', 'must_change' => 1, 'puskesmas_id' => $pid, 'personel_id' => $per, 'aktif' => 1]);
$fa = sp_insert('users', ['role' => 'fasil', 'nama' => 'Fasil', 'username' => 'fasil1', 'pass_hash' => 'x', 'must_change' => 1, 'aktif' => 1]);
$k = sp_insert('bl_keg', ['judul' => 'Lama', 'status' => 'terbit', 'daftar_buka' => 1, 'pembuka' => '[]', 'fasil' => '[]']);
sp_insert('bl_peserta', ['keg_id' => $k, 'puskesmas_id' => $pid, 'personel_id' => $per, 'nama' => 'Ani', 'status' => 'menunggu', 'user_id' => $sub]);
$fail = 0; $n = 0;
$ok = function (bool $c, string $m) use (&$fail, &$n) { $n++; if (!$c) $fail++; echo ($c ? 'ok   ' : 'GAGAL ') . $m . "\n"; };
sp_ensure_schema();
sp_ensure_schema();
$ok((int)setting('schema_v') === 5, 'versi skema 4 → 5');
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
$ok(!array_diff(sp_required_tables(), $tables), 'semua tabel ada (termasuk bl_users, bl_audit)');
$ok((int)qval('SELECT COUNT(*) FROM bl_keg') === 0 && (int)qval('SELECT COUNT(*) FROM bl_peserta') === 0, 'data pembelajaran lama dihapus (mulai kosong)');
$cols = array_column($pdo->query('PRAGMA table_info(bl_peserta)')->fetchAll(), 'name');
$ok(in_array('instansi', $cols, true) && !in_array('personel_id', $cols, true) && in_array('kuota', array_column($pdo->query('PRAGMA table_info(bl_keg)')->fetchAll(), 'name'), true), 'struktur baru: bl_peserta.instansi, bl_keg.kuota; tanpa personel_id');
$ok(!(int)qval('SELECT aktif FROM users WHERE id = ?', [$sub]) && !(int)qval('SELECT aktif FROM users WHERE id = ?', [$fa]) && (int)qval('SELECT aktif FROM users WHERE id = ?', [$b]), 'sub-akun & Fasilitator Si PANDU dinonaktifkan; akun Binaan tetap aktif');
$ok(!is_file($dd . '/surat/lama.pdf') && !is_file($dd . '/materi/lama.pdf') && is_file($dd . '/foto/personel.jpg'), 'berkas pembelajaran lama dihapus; foto personel utuh');
$ok((int)qval('SELECT COUNT(*) FROM personel') === 1 && q1('SELECT nama FROM puskesmas WHERE id = ?', [$pid])['nama'] === 'Puskesmas Baru Ilir', 'data Si PANDU lain utuh');
@unlink($db); @unlink($cfg); @unlink($dd . '/foto/personel.jpg');
echo "pemeriksaan: $n, gagal: $fail\n";
exit($fail ? 1 : 0);
