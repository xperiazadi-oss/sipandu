<?php
/* Uji migrasi: basis data skema versi 1 (Tahap 1 awal) → versi terbaru tanpa kehilangan data. Argumen: berkas skema v1. */
declare(strict_types=1);
$old = $argv[1] ?? __DIR__ . '/data/schema_v1.php';
$db = sys_get_temp_dir() . '/sp_mig_' . getmypid() . '.sqlite';
@unlink($db);
$cfg = sys_get_temp_dir() . '/sp_mig_cfg_' . getmypid() . '.php';
file_put_contents($cfg, "<?php return ['db' => ['dsn' => 'sqlite:$db'], 'data_dir' => '" . sys_get_temp_dir() . "/sp_mig_data'];");
putenv("SIPANDU_CONFIG=$cfg");
// skema v1 dengan nama fungsi diganti agar tidak bentrok
$src = str_replace(['sp_schema_sql', 'sp_create_schema', 'sp_required_tables', '<?php', 'declare(strict_types=1);'], ['v1_schema_sql', 'v1_create_schema', 'v1_required_tables', '', ''], file_get_contents($old));
eval($src);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/domain.php';
$pdo = sp_db();
v1_create_schema($pdo, 'sqlite');
set_setting('installed_at', now_str());
$pid = sp_insert('puskesmas', ['kode' => 'P01', 'nama' => 'PKM Lama', 'aktif' => 1]);
$aid = sp_insert('alat', ['puskesmas_id' => $pid, 'nama' => 'Fotometer Lama', 'merk' => 'M', 'kategori' => 'Kimia', 'aktif' => 1]);
$sid = sp_insert('sheets', ['puskesmas_id' => $pid, 'param_id' => 1, 'alat_id' => $aid, 'kategori' => 'Kimia', 'max_levels' => 2, 'levels' => jenc([['name' => 'L1', 'control' => 'Ketik', 'lot' => 'L-OLD', 'mean' => 1, 'sd' => 1]]), 'status' => 'aktif', 'reagen' => 'R', 'reagen_lot' => 'RL', 'reagen_events' => '[]']);
$fail = 0; $n = 0;
$ok = function (bool $c, string $m) use (&$fail, &$n) { $n++; if (!$c) $fail++; echo ($c ? 'ok   ' : 'GAGAL ') . $m . "\n"; };
$ok((int)setting('schema_v', 1) === 1, 'basis data awal versi 1');
sp_ensure_schema();
sp_ensure_schema(); // diulang: aman
$ok((int)setting('schema_v', 1) === SP_SCHEMA_VERSION, 'versi skema menjadi ' . SP_SCHEMA_VERSION);
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
$ok(!array_diff(sp_required_tables(), $tables), 'semua tabel ada');
foreach (sp_added_columns('sqlite') as $t => $cols) foreach ($cols as $c => $_) {
    try { $pdo->query("SELECT $c FROM $t LIMIT 0"); $ok(true, "kolom $t.$c"); } catch (PDOException $e) { $ok(false, "kolom $t.$c"); }
}
$a = q1('SELECT * FROM alat WHERE id = ?', [$aid]); $s = q1('SELECT * FROM sheets WHERE id = ?', [$sid]);
$ok($a['nama'] === 'Fotometer Lama' && $a['kondisi'] === null && jdec($s['levels'])[0]['lot'] === 'L-OLD' && $s['reagen_lot'] === 'RL', 'data lama utuh');
$st = setup_status($pid); $ok(!$st['done'] && !$st['sdm'] && $st['alat'], 'status alur wajib terbaca pada data lama');
$ok(out_sheet($s)['reagenBhpId'] === null && bhp_used_ids($pid) === [], 'lembar lama tanpa tautan Bahan Habis Pakai tetap terbaca');
@unlink($db); @unlink($cfg);
echo "pemeriksaan: $n, gagal: $fail\n";
exit($fail ? 1 : 0);
