<?php
/* Si PANDU – data awal saat pemasangan (sesuai kesepakatan: 1 Administrator, 2 Pembina, 27 Puskesmas binaan).
   Jumlah akun tidak dibatasi: Administrator dapat menambah Pembina, Binaan, dan Puskesmas kapan saja. */
declare(strict_types=1);

function sp_seed_initial(string $adminPassword): array
{
    $pdo = sp_db();
    $pdo->beginTransaction();
    $acc = [];
    $now = now_str();
    set_setting('rules', QCEngine_default_rules());
    set_setting('k', 2);
    set_setting('tampilan', ['appName' => t('app.nama'), 'longName' => t('app.kepanjangan'), 'tagline' => t('app.slogan'), 'color' => '#0d5c63']);
    sp_insert('users', ['role' => 'admin', 'nama' => t('seed.nama_admin'), 'username' => 'admin', 'pass_hash' => password_hash($adminPassword, PASSWORD_DEFAULT), 'must_change' => 0, 'aktif' => 1, 'created_at' => $now]);
    for ($i = 1; $i <= 2; $i++) {
        $pw = temp_password();
        sp_insert('users', ['role' => 'pembina', 'nama' => t('seed.nama_pembina', ['n' => $i]), 'username' => 'pembina' . $i, 'pass_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change' => 1, 'jabatan' => t('seed.jabatan_pembina'), 'aktif' => 1, 'created_at' => $now]);
        $acc[] = ['role' => 'pembina', 'name' => t('seed.nama_pembina', ['n' => $i]), 'username' => 'pembina' . $i, 'password' => $pw];
    }
    for ($i = 1; $i <= 27; $i++) {
        $kode = sprintf('P%02d', $i);
        $nama = t('seed.nama_pkm', ['n' => sprintf('%02d', $i)]);
        $pid = sp_insert('puskesmas', ['kode' => $kode, 'nama' => $nama, 'aktif' => 1]);
        $pw = temp_password();
        $un = sprintf('pkm%02d', $i);
        sp_insert('users', ['role' => 'binaan', 'nama' => t('seed.nama_lab', ['pkm' => $nama]), 'username' => $un, 'pass_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change' => 1, 'puskesmas_id' => $pid, 'aktif' => 1, 'created_at' => $now]);
        $acc[] = ['role' => 'binaan', 'name' => t('seed.nama_lab', ['pkm' => $nama]), 'username' => $un, 'password' => $pw];
    }
    // Parameter awal; TEa belum diisi kecuali nilai lama dari Excel (belum terverifikasi)
    $params = [['Glukosa', 'Kimia', 'mg/dL', 6.96, 'Nilai lama dari Excel'], ['Kolesterol total', 'Kimia', 'mg/dL', null, ''], ['Asam urat', 'Kimia', 'mg/dL', null, ''], ['SGOT', 'Kimia', 'U/L', null, ''], ['SGPT', 'Kimia', 'U/L', null, ''], ['Kreatinin', 'Kimia', 'mg/dL', null, ''], ['Hemoglobin', 'Hematologi', 'g/dL', null, ''], ['Leukosit', 'Hematologi', '10³/µL', null, ''], ['Trombosit', 'Hematologi', '10³/µL', null, ''], ['Hematokrit', 'Hematologi', '%', null, '']];
    foreach ($params as [$n, $k, $s, $tea, $src]) sp_insert('params', ['nama' => $n, 'kategori' => $k, 'satuan' => $s, 'spec_src' => 'lain', 'level' => 'Desirable', 'tea' => $tea, 'tea_src' => $src, 'tea_ok' => 0]);
    $pdo->commit();
    return $acc;
}

function QCEngine_default_rules(): array
{
    require_once __DIR__ . '/engine.php';
    return QCEngine::defaultEnabled();
}
