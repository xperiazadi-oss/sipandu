<?php
/* Subdomain pembelajaran – penerbitan dokumen yang dapat diverifikasi di VeriDoc (veridoc.my.id).
   Alur Laporan Kegiatan: Fasilitator "Terbitkan" → Pembina terpilih "Sahkan" (atau "Kembalikan").
   Saat disahkan: nomor otomatis (format per jenis dokumen), kode verifikasi, kode akses (Rahasia–Terkendali), isi dikunci
   (snapshot), lalu didaftarkan ke VeriDoc. "Cabut" (tanpa pembaruan) dan "Ganti" (dengan pembaruan) oleh pengesah atau Admin.
   Pengiriman ke VeriDoc yang gagal disimpan sebagai antrean dan dikirim ulang otomatis. */

require_once __DIR__ . '/qr.php';

const BL_KODE_ABJAD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';   // tanpa I, L, O, U
const BL_ALASAN_DOK_MIN = 10;
const BL_JENIS_LAPORAN = 'laporan_kegiatan';

/* ---------- jenis dokumen (diatur Admin) ---------- */
function bl_jenis(string $kode): array
{
    $r = q1('SELECT * FROM bl_jenis_dok WHERE kode = ?', [$kode]);
    if ($r) return $r;
    sp_insert('bl_jenis_dok', ['kode' => $kode, 'nama' => t('blapp.menu_laporan'), 'sifat' => 'rahasia', 'format' => 'LAP/{urut}/Labkesda/{tahun}',
        'next_no' => 1, 'tahun' => (int)date('Y'), 'penerbit' => 'UPTD Laboratorium Kesehatan Daerah Kota Balikpapan']);
    return q1('SELECT * FROM bl_jenis_dok WHERE kode = ?', [$kode]);
}
function bl_jenis_out(array $j): array
{
    return ['kode' => $j['kode'], 'nama' => $j['nama'], 'sifat' => $j['sifat'], 'format' => $j['format'], 'next' => (int)$j['next_no'], 'tahun' => (int)$j['tahun'], 'penerbit' => $j['penerbit']];
}
/** Ambil nomor berikutnya (dipanggil di dalam transaksi); nomor urut diulang setiap tahun */
function bl_jenis_nomor(string $kode, string $tglTerbit): string
{
    $j = bl_jenis($kode);
    if (sp_driver() === 'mysql') $j = q1('SELECT * FROM bl_jenis_dok WHERE kode = ? FOR UPDATE', [$kode]);
    $th = (int)substr($tglTerbit, 0, 4);
    $no = (int)$j['tahun'] === $th ? max(1, (int)$j['next_no']) : 1;
    q('UPDATE bl_jenis_dok SET next_no = ?, tahun = ? WHERE kode = ?', [$no + 1, $th, $kode]);
    return strtr((string)$j['format'], ['{urut}' => (string)$no, '{tahun}' => (string)$th]);
}

/* ---------- kode verifikasi & kode akses ---------- */
function bl_kode_acak(int $n): string
{
    $s = '';
    for ($i = 0; $i < $n; $i++) $s .= BL_KODE_ABJAD[random_int(0, 31)];
    return $s;
}
function bl_kode_verifikasi(): string
{
    do { $s = bl_kode_acak(12); $k = substr($s, 0, 4) . '-' . substr($s, 4, 4) . '-' . substr($s, 8); }
    while (qval('SELECT COUNT(*) FROM bl_dok WHERE kode = ?', [$k]));
    return $k;
}
function bl_veridoc_url(): string { return rtrim((string)(sp_config()['veridoc_url'] ?? 'https://veridoc.my.id'), '/'); }

/* ---------- status ---------- */
function bl_hari_ini(): string { return substr(now_str(), 0, 10); }
/** Status efektif dokumen: masa berlaku habis dihitung dari tanggal */
function bl_dok_status(array $d): string
{
    return $d['status'] === 'sah' && $d['berlaku_sampai'] && bl_hari_ini() > $d['berlaku_sampai'] ? 'habis' : (string)$d['status'];
}
function bl_dok_terakhir(int $lapId): ?array { return q1('SELECT * FROM bl_dok WHERE lap_id = ? ORDER BY id DESC LIMIT 1', [$lapId]); }
function bl_dok_out(?array $d): ?array
{
    if (!$d) return null;
    return ['id' => (int)$d['id'], 'nomor' => $d['nomor'], 'kode' => $d['kode'], 'akses' => $d['akses'], 'sifat' => $d['sifat'], 'kendali' => $d['kendali'], 'terbit' => $d['terbit'],
        'sampai' => $d['berlaku_sampai'], 'status' => bl_dok_status($d), 'sejak' => $d['status_sejak'], 'pengesah' => $d['pengesah_nama'], 'revisi' => (int)$d['revisi'], 'sync' => $d['sync']];
}

/* ---------- hak akses laporan ---------- */
function bl_lap_by_id(int $id): ?array { return q1('SELECT * FROM bl_laporan WHERE id = ?', [$id]); }
/** Hak pengguna atas satu laporan */
function bl_lap_hak(array $u, array $r, array $k): array
{
    $dok = bl_dok_terakhir((int)$r['id']);
    $fasilAktif = (bool)qval("SELECT aktif FROM bl_users WHERE id = ? AND role = 'fasil'", [$r['user_id']]);
    $pemilik = $u['role'] === 'fasil' && (int)$u['id'] === (int)$r['user_id'] && bl_lap_can($u, $k);
    $adminSunting = $u['role'] === 'admin' && ($k['status'] === 'arsip' || !$fasilAktif);
    $pengesahPilihan = $u['role'] === 'pembina' && (int)$u['id'] === (int)$r['pembina_id'];
    $pengesahDok = $dok && $u['role'] === 'pembina' && (int)$u['id'] === (int)$dok['pengesah_id'];
    $draf = $r['status'] === 'draf';
    return [
        'lihat' => $pemilik || $u['role'] === 'admin' || ($u['role'] === 'pembina' && ($pengesahPilihan && $r['status'] !== 'draf' || $pengesahDok)),
        'sunting' => $draf && ($pemilik || $adminSunting),
        'sahkan' => $r['status'] === 'menunggu' && $pengesahPilihan,
        'ubahDok' => $r['status'] === 'terbit' && $dok && bl_dok_status($dok) === 'sah' && ($u['role'] === 'admin' || $pengesahDok),
    ];
}

/* ---------- isi untuk VeriDoc: diringkas menurut aturan jenis Laporan Kegiatan ---------- */
function bl_paragraf_pertama(string $s): string
{
    foreach (preg_split('/\n\s*\n|\n/', trim($s)) as $p) if (trim($p) !== '') return trim($p);
    return '';
}
function bl_dok_isi(array $L): array
{
    $b = [['t' => 'kv', 'rows' => array_values(array_filter($L['ident'], fn($r) => $r[0] !== t('dok.nomor')))]];
    $teks = function (string $label, string $s, bool $ringkas) use (&$b) {
        $b[] = ['t' => 'judul', 'v' => $label];
        if ($ringkas) { $b[] = ['t' => 'p', 'v' => bl_paragraf_pertama($s) ?: t('lap.belum_diisi')]; return; }
        $lines = array_values(array_filter(array_map('trim', explode("\n", $s)), fn($x) => $x !== ''));
        foreach ($lines ?: [t('lap.belum_diisi')] as $l) $b[] = ['t' => 'p', 'v' => $l];
    };
    $teks(t('lap.sec_a'), $L['f']['pendahuluan'], true);
    $teks(t('lap.sec_b'), $L['f']['mekanisme'], true);
    $h = $L['hasil'];
    $b[] = ['t' => 'judul', 'v' => t('lap.sec_c')];
    $b[] = ['t' => 'p', 'v' => $h['pembuka']];
    $b[] = ['t' => 'p', 'v' => '1. ' . $h['p1']];
    $b[] = ['t' => 'p', 'v' => t('lap.menurut_instansi')]; $b[] = ['t' => 'daftar', 'v' => $h['inst']];
    $b[] = ['t' => 'p', 'v' => t('lap.menurut_profesi')]; $b[] = ['t' => 'daftar', 'v' => $h['prof']];
    $b[] = ['t' => 'p', 'v' => '2. ' . $h['p2']];
    $b[] = ['t' => 'tabel', 'kepala' => [t('lap.t_komponen'), t('lap.t_n'), t('lap.t_rata'), t('lap.t_min'), t('lap.t_max')], 'baris' => array_map(fn($r) => array_map('strval', $r), $h['stat'])];
    $b[] = ['t' => 'p', 'v' => $h['p2b']];
    $b[] = ['t' => 'tabel', 'kepala' => [t('lap.t_no'), t('lap.t_nama'), t('lap.t_instansi'), t('lap.t_pre'), t('lap.t_post'), t('lap.t_progres'), t('lap.t_praktik'), t('lap.t_sikap'), t('lap.t_skor'), t('lap.t_kategori')], 'baris' => $h['detail']];
    $b[] = ['t' => 'p', 'v' => '3. ' . $h['p3']];
    $b[] = ['t' => 'p', 'v' => $h['kat_ket']];
    $teks(t('lap.sec_d'), $L['f']['kendala'], false);
    $teks(t('lap.sec_e'), $L['f']['kesimpulan'], false);
    $teks(t('lap.sec_f'), $L['f']['rekomendasi'], false);
    $t = $L['ttd'];
    $b[] = ['t' => 'kv', 'rows' => [[t('lap.ttd_fasil'), $t['fasil']], [t('lap.ttd_pembina'), $t['pembina']], ['', trim($t['tempat'] . ', ' . $t['tanggal'], ', ')]]];
    return $b;
}

/* ---------- VeriDoc ---------- */
function bl_dok_payload(array $d): array
{
    $lama = $d['menggantikan_id'] ? (string)qval('SELECT kode FROM bl_dok WHERE id = ?', [$d['menggantikan_id']]) : '';
    return ['aksi' => 'terbit', 'kode' => $d['kode'], 'jenis' => $d['jenis'], 'nomor' => $d['nomor'], 'judul' => $d['judul'], 'sifat' => $d['sifat'], 'kendali' => $d['kendali'],
        'akses' => $d['akses'], 'penerbit' => $d['penerbit'], 'pengesah' => ['nama' => $d['pengesah_nama'], 'jabatan' => $d['pengesah_jabatan']],
        'terbit' => $d['terbit'], 'sampai' => $d['berlaku_sampai'], 'isi' => jdec($d['isi']), 'menggantikan' => $lama ?: null];
}
/** Kirim satu permintaan bertanda tangan ke VeriDoc; true bila diterima */
function bl_veridoc_kirim(array $data): bool
{
    $key = (string)(sp_config()['veridoc_kunci'] ?? '');
    if ($key === '' || !function_exists('curl_init')) return false;
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $w = time();
    $c = curl_init(bl_veridoc_url() . '/api/registri');
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-veridoc-sumber: sipandu', 'x-veridoc-waktu: ' . $w, 'x-veridoc-tanda: ' . hash_hmac('sha256', $w . '.' . $body, $key)]]);
    $out = curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    return $code === 200 && is_string($out) && str_contains($out, '"ok":true');
}
/** Sinkronkan satu dokumen (terbit, lalu cabut bila sudah dicabut) */
function bl_dok_sync(int $id): bool
{
    $d = q1('SELECT * FROM bl_dok WHERE id = ?', [$id]);
    if (!$d) return true;
    $ok = bl_veridoc_kirim(bl_dok_payload($d));
    if ($ok && $d['status'] === 'dicabut') $ok = bl_veridoc_kirim(['aksi' => 'cabut', 'kode' => $d['kode'], 'sejak' => $d['status_sejak']]);
    q('UPDATE bl_dok SET sync = ? WHERE id = ?', [$ok ? 'ok' : 'tunda', $id]);
    return $ok;
}
/** Kirim ulang antrean (paling sering sekali per menit) */
function bl_veridoc_antrean(): void
{
    if ((int)setting('veridoc_coba', 0) > time() - 60) return;
    set_setting('veridoc_coba', (string)time());
    foreach (qall("SELECT id FROM bl_dok WHERE sync = 'tunda' ORDER BY id LIMIT 5") as $r) if (!bl_dok_sync((int)$r['id'])) break;
}

/* ---------- pengesahan, cabut, ganti ---------- */
function bl_lap_sahkan(array $u, array $r, array $k): array
{
    $jenis = bl_jenis(BL_JENIS_LAPORAN);
    $tgl = bl_hari_ini();
    $fasil = q1('SELECT * FROM bl_users WHERE id = ?', [$r['user_id']]) ?: ['nama' => ''];
    sp_db()->beginTransaction();
    try {
        $L = bl_lap_data($r, $k);
        unset($L['pembinaList']);
        $lama = $r['ganti_dok_id'] ? q1('SELECT * FROM bl_dok WHERE id = ?', [$r['ganti_dok_id']]) : null;
        $nomor = bl_jenis_nomor(BL_JENIS_LAPORAN, $tgl);
        $rahasiaKendali = $jenis['sifat'] === 'rahasia' && $r['kendali'] === 'terkendali';
        $akses = $rahasiaKendali ? substr_replace(bl_kode_acak(8), '-', 4, 0) : null;
        $kode = bl_kode_verifikasi();
        $jab = trim((string)(q1('SELECT jabatan FROM bl_profil WHERE user_id = ?', [$u['id']])['jabatan'] ?? ''));
        $dokInfo = ['nomor' => $nomor, 'kode' => $kode, 'akses' => $akses, 'sifat' => $jenis['sifat'], 'kendali' => (string)$r['kendali']];
        $L['dok'] = $dokInfo;
        array_unshift($L['ident'], [t('dok.nomor'), $nomor]);
        $id = sp_insert('bl_dok', ['lap_id' => $r['id'], 'keg_id' => $k['id'], 'jenis' => BL_JENIS_LAPORAN, 'revisi' => $lama ? (int)$lama['revisi'] + 1 : 0,
            'nomor' => $nomor, 'kode' => $kode, 'akses' => $akses, 'sifat' => $jenis['sifat'], 'kendali' => (string)$r['kendali'],
            'judul' => $jenis['nama'] . ' ' . $k['judul'], 'penerbit' => $jenis['penerbit'], 'pengesah_id' => $u['id'], 'pengesah_nama' => $u['nama'], 'pengesah_jabatan' => $jab !== '' ? $jab : t('lap.ttd_pembina'),
            'fasil_nama' => $fasil['nama'], 'berlaku_sampai' => $r['berlaku_mode'] === 'tanggal' ? $r['berlaku_sampai'] : null, 'terbit' => $tgl, 'status' => 'sah', 'status_sejak' => $tgl,
            'menggantikan_id' => $lama ? $lama['id'] : null, 'snapshot' => jenc($L), 'isi' => jenc(bl_dok_isi($L)), 'sync' => 'tunda', 'created_at' => now_str()]);
        if ($lama) q("UPDATE bl_dok SET status = 'digantikan', status_sejak = ?, pengganti_id = ? WHERE id = ?", [$tgl, $id, $lama['id']]);
        q("UPDATE bl_laporan SET status = 'terbit', ganti_dok_id = NULL, catatan_kembali = NULL, updated_at = ? WHERE id = ?", [now_str(), $r['id']]);
        audit('bl_lap_sahkan', ['lap' => (int)$r['id'], 'dok' => $id, 'nomor' => $nomor, 'menggantikan' => $lama ? (int)$lama['id'] : null]);
        sp_db()->commit();
    } catch (Throwable $e) { if (sp_db()->inTransaction()) sp_db()->rollBack(); throw $e; }
    bl_dok_sync($id);
    return q1('SELECT * FROM bl_dok WHERE id = ?', [$id]);
}
function bl_dok_cabut(array $u, array $d, string $alasan): void
{
    q("UPDATE bl_dok SET status = 'dicabut', status_sejak = ?, alasan = ?, sync = 'tunda' WHERE id = ?", [bl_hari_ini(), $alasan, $d['id']]);
    audit('bl_dok_cabut', ['dok' => (int)$d['id'], 'nomor' => $d['nomor'], 'alasan' => $alasan]);
    bl_dok_sync((int)$d['id']);
}

/* ---------- dokumen tercetak: QR (PNG untuk Word) ---------- */
function bl_dok_qr_url(array $dok): string { return bl_veridoc_url() . '/d/' . $dok['kode']; }
function bl_dok_qr_teks(array $dok): string
{
    return t('dok.qr_ket', ['kode' => $dok['kode']]) . ($dok['akses'] ? t('dok.qr_akses', ['akses' => $dok['akses']]) : '');
}
function bl_dok_label_sifat(array $dok): string { return t('dok.label_sifat.' . $dok['sifat'] . '_' . $dok['kendali']); }

/* ---------- data untuk layar ---------- */
/** Status laporan yang ditampilkan: draf / menunggu / status dokumen terbit (sah, dicabut, digantikan, habis) */
function bl_lap_status_tampil(?array $r): string
{
    if (!$r || $r['status'] === 'draf' || $r['status'] === 'menunggu') return $r['status'] ?? 'draf';
    $d = bl_dok_terakhir((int)$r['id']);
    return $d ? bl_dok_status($d) : 'draf';
}
/** Isi laporan untuk dicetak: dokumen terbit (snapshot + QR) atau draf (cap DRAF) */
function bl_lap_cetak(array $r, array $k): array
{
    $d = $r['status'] === 'terbit' ? bl_dok_terakhir((int)$r['id']) : null;
    if ($d) return (array)jdec($d['snapshot']);
    $L = bl_lap_data($r, $k);
    $L['draf'] = true;
    return $L;
}
function bl_lap_out(array $u, array $r, array $k): array
{
    $dok = bl_dok_terakhir((int)$r['id']);
    $L = $r['status'] === 'terbit' && $dok ? (array)jdec($dok['snapshot']) + ['pembinaList' => []] : bl_lap_data($r, $k);
    $pemb = $r['pembina_id'] ? (string)qval('SELECT nama FROM bl_users WHERE id = ?', [$r['pembina_id']]) : '';
    $hak = bl_lap_hak($u, $r, $k);
    return $L + [
        'lapId' => (int)$r['id'], 'status' => $r['status'], 'tampil' => bl_lap_status_tampil($r), 'hak' => $hak, 'pembinaNama' => $pemb,
        'kendali' => $r['kendali'], 'berlakuMode' => $r['berlaku_mode'], 'berlakuSampai' => $r['berlaku_sampai'], 'catatan' => $r['catatan_kembali'],
        'ganti' => (bool)$r['ganti_dok_id'], 'sifat' => bl_jenis(BL_JENIS_LAPORAN)['sifat'], 'dokInfo' => bl_dok_out($dok), 'today' => bl_hari_ini(),
    ];
}
