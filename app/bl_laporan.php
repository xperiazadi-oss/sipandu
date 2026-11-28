<?php
/* Subdomain pembelajaran – Laporan Kegiatan: sampul (templat tersimpan + isian penyusun), Lembar Pengesahan, Daftar Isi otomatis,
   bab/sub-bab berisi teks berformat (bl_rt.php), tabel olahan website yang dapat difilter, dan lampiran (gambar; halaman PDF diubah
   menjadi gambar di browser). Berkas PDF (bl_lap_pdf.php, fon inti tanpa pustaka luar) dan Word (bl_lap_docx.php). Kertas A4. */
// Tanpa strict_types: nilai dari basis data selalu berupa string dan dikonversi otomatis oleh PHP.

require_once __DIR__ . '/bl_rt.php';
require_once __DIR__ . '/bl_lap_pdf.php';
require_once __DIR__ . '/bl_lap_docx.php';

const BL_TOR_MAX = 5242880;          // 5 MB
const BL_LAP_FILE_MAX = 10485760;    // 10 MB per file unggahan
const BL_LAP_GAMBAR_MAX = 400;       // jumlah gambar lampiran per laporan
/** Kategori kualitatif (standar penilaian umum): batas bawah → kunci teks */
const BL_KATEGORI = [[86, 'sb'], [71, 'b'], [56, 'c'], [0, 'k']];

/* ---------- hak akses & baris laporan ---------- */
function bl_lap_can(array $u, array $k): bool
{
    return $u['role'] === 'fasil' && bl_is_staff($u, $k);
}
function bl_lap_row(array $u, array $k): array
{
    $r = q1('SELECT * FROM bl_laporan WHERE keg_id = ? AND user_id = ?', [$k['id'], $u['id']]);
    if ($r) return $r;
    sp_insert('bl_laporan', ['keg_id' => $k['id'], 'user_id' => $u['id'], 'pelaksana' => (string)$k['penyelenggara'],
        'ttd_tempat' => t('lap.ttd_tempat_bawaan'), 'ttd_tanggal' => substr(now_str(), 0, 10), 'updated_at' => now_str()]);
    return q1('SELECT * FROM bl_laporan WHERE keg_id = ? AND user_id = ?', [$k['id'], $u['id']]);
}

/* ---------- format ---------- */
function bl_lap_num($v): string
{
    if ($v === null) return '–';
    $v = round((float)$v, 1);
    return floor($v) == $v ? (string)(int)$v : number_format($v, 1, ',', '.');
}
function bl_lap_tgl(string $ymd, bool $hari = false): string
{
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $ymd);
    if (!$d) return '';
    $s = (int)$d->format('j') . ' ' . sp_teks()['bulan'][(int)$d->format('n') - 1] . ' ' . $d->format('Y');
    return $hari ? sp_teks()['lap']['hari'][(int)$d->format('w')] . ', ' . $s : $s;
}
function bl_lap_kat_key($v): ?string
{
    if ($v === null) return null;
    foreach (BL_KATEGORI as [$min, $key]) if ((float)$v >= $min) return $key;
    return 'k';
}
function bl_lap_kategori($v): string
{
    $k = bl_lap_kat_key($v);
    return $k ? t('lap.kat.' . $k) : '–';
}
function bl_lap_romawi(int $n): string { return bl_rt_romawi($n); }

/* ---------- struktur laporan: sampul, bab/sub-bab, lampiran ---------- */
function bl_lap_uid(): string { return bin2hex(random_bytes(4)); }
/** Judul bab (tetap) menurut urutan: A. PENDAHULUAN, … */
function bl_lap_bab_judul(int $i): string { return chr(65 + $i) . '. ' . (sp_teks()['lap']['bab'][$i][0] ?? ''); }
function bl_lap_struktur(array $r, array $k): array
{
    $S = ['sampul' => jdec($r['sampul'] ?? null), 'bab' => jdec($r['bab'] ?? null), 'lampiran' => jdec($r['lampiran'] ?? null)];
    if (!is_array($S['sampul']) || !isset($S['sampul']['judulKegiatan'])) {
        $fas = q1('SELECT nama FROM bl_users WHERE id = ?', [$r['user_id']]);
        $S['sampul'] = ['judulLaporan' => '', 'judulKegiatan' => (string)$k['judul'], 'nama' => (string)($fas['nama'] ?? ''),
            'jabatan' => trim((string)qval('SELECT jabatan FROM bl_profil WHERE user_id = ?', [$r['user_id']])), 'foto' => null];
    }
    if (!is_array($S['bab']) || !$S['bab']) {
        $S['bab'] = [];
        foreach (sp_teks()['lap']['bab'] as [$judul, $subs]) $S['bab'][] = ['html' => '', 'sub' => array_map(fn($j) => ['id' => bl_lap_uid(), 'judul' => $j, 'html' => ''], $subs)];
        // isian format lama dipindahkan: Pendahuluan→A.1, Mekanisme→B.3, Kendala→C.1, Kesimpulan & Rekomendasi→D
        $put = function (int $b, ?int $sub, string $txt) use (&$S) {
            $h = bl_rt_dari_teks($txt);
            if ($h === '') return;
            if ($sub !== null && isset($S['bab'][$b]['sub'][$sub])) $S['bab'][$b]['sub'][$sub]['html'] .= $h;
            else $S['bab'][$b]['html'] .= $h;
        };
        $put(0, 0, (string)($r['pendahuluan'] ?? ''));
        $put(1, 2, (string)($r['mekanisme'] ?? ''));
        $put(2, 0, (string)($r['kendala'] ?? ''));
        $put(3, null, (string)($r['kesimpulan'] ?? ''));
        $put(3, null, (string)($r['rekomendasi'] ?? ''));
    }
    if (!is_array($S['lampiran'])) $S['lampiran'] = [];
    // simpan struktur awal sekali agar id sub-bab tetap (tanpa mengubah waktu sunting)
    if (!empty($r['id']) && (($r['sampul'] ?? null) === null || ($r['bab'] ?? null) === null || ($r['lampiran'] ?? null) === null))
        q('UPDATE bl_laporan SET sampul = ?, bab = ?, lampiran = ? WHERE id = ?', [jenc($S['sampul']), jenc($S['bab']), jenc($S['lampiran']), $r['id']]);
    return $S;
}
function bl_lap_simpan(array $r, array $S): void
{
    q('UPDATE bl_laporan SET sampul = ?, bab = ?, lampiran = ?, updated_at = ? WHERE id = ?', [jenc($S['sampul']), jenc($S['bab']), jenc($S['lampiran']), now_str(), $r['id']]);
}

/* ---------- berkas gambar laporan (foto sampul, halaman lampiran) ---------- */
function bl_lap_dir(): string { return data_dir('laporan'); }
function bl_lap_path(string $f): string { return bl_lap_dir() . '/' . basename($f); }
/** Simpan gambar unggahan sebagai JPEG (latar putih, orientasi EXIF, sisi terpanjang ≤ $max); kembalikan [file, w, h] */
function bl_lap_simpan_gambar(string $raw, int $lapId, int $max): array
{
    $info = @getimagesizefromstring($raw);
    need($info !== false && in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true), 'lap.galat.format', 400);
    need($info[0] * $info[1] <= 60000000, 'lap.galat.file_besar', 400);
    $src = @imagecreatefromstring($raw);
    need($src !== false, 'lap.galat.format', 400);
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $ex = @exif_read_data('data://image/jpeg;base64,' . base64_encode($raw));
        $ang = [3 => 180, 6 => 270, 8 => 90][(int)($ex['Orientation'] ?? 1)] ?? 0;
        if ($ang) { $rot = imagerotate($src, $ang, 0); if ($rot) { imagedestroy($src); $src = $rot; } }
    }
    $w = imagesx($src); $h = imagesy($src);
    $sc = min(1, $max / max($w, $h));
    $nw = max(1, (int)round($w * $sc)); $nh = max(1, (int)round($h * $sc));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);
    $file = 'l' . $lapId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.jpg';
    imagejpeg($dst, bl_lap_path($file), 85);
    imagedestroy($dst);
    return [$file, $nw, $nh];
}
/** Hapus berkas hanya bila laporan belum pernah terbit (berkas dokumen terbit dipakai snapshot) */
function bl_lap_hapus_berkas(array $r, string $f): void
{
    if ($f !== '' && !qval('SELECT COUNT(*) FROM bl_dok WHERE lap_id = ?', [$r['id']])) @unlink(bl_lap_path($f));
}

/** Semua isi laporan (untuk layar, PDF, dan Word); $r = baris bl_laporan */
function bl_lap_data(array $r, array $k): array
{
    $u = q1('SELECT * FROM bl_users WHERE id = ?', [$r['user_id']]) ?: ['nama' => ''];
    $pemb = $r['pembina_id'] ? q1("SELECT nama FROM bl_users WHERE id = ? AND role = 'pembina'", [$r['pembina_id']]) : null;
    $S = bl_lap_struktur($r, $k);
    foreach ($S['bab'] as $i => &$b) $b['judul'] = bl_lap_bab_judul($i);
    unset($b);
    return [
        'kegId' => (int)$k['id'], 'title' => $k['judul'],
        'sampul' => $S['sampul'], 'bab' => $S['bab'], 'lampiran' => $S['lampiran'],
        'f' => ['pembinaId' => $r['pembina_id'] ? (int)$r['pembina_id'] : null, 'ttdTempat' => (string)$r['ttd_tempat'], 'ttdTanggal' => (string)$r['ttd_tanggal']],
        'hasTor' => (bool)$r['tor'], 'updated' => $r['updated_at'],
        'ttd' => ['tempat' => (string)$r['ttd_tempat'], 'tanggal' => $r['ttd_tanggal'] ? bl_lap_tgl($r['ttd_tanggal']) : '', 'fasil' => (string)$u['nama'], 'pembina' => $pemb ? (string)$pemb['nama'] : ''],
        'pembinaList' => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['nama']], qall("SELECT id, nama FROM bl_users WHERE role = 'pembina' AND aktif = 1 ORDER BY nama")),
    ];
}

/* ---------- tabel olahan website (difilter penyusun, disisipkan ke penyunting) ---------- */
function bl_lap_peserta(array $k): array
{
    $jab = [];
    foreach (qall("SELECT id, jabatan FROM bl_peserta WHERE keg_id = ? AND status = 'diterima'", [$k['id']]) as $p) $jab[(int)$p['id']] = trim((string)$p['jabatan']);
    $out = [];
    foreach (bl_scores($k)['rows'] as $r) $out[] = ['nama' => (string)$r['name'], 'instansi' => trim((string)$r['instansi']) ?: t('lap.tidak_diisi'), 'jabatan' => ($jab[$r['pesertaId']] ?? '') ?: t('lap.tidak_diisi'),
        'pre' => $r['pre'], 'post' => $r['post'], 'progres' => $r['progres'], 'praktik' => $r['praktik'], 'sikap' => $r['sikap'], 'skor' => $r['terbaik'], 'kat' => bl_lap_kat_key($r['terbaik']) ?? '-'];
    return $out;
}
/** $o: jenis, instansi[], jabatan[], kategori[], kolom[], urut, arah (naik|turun) */
function bl_lap_tabel(array $k, array $o): array
{
    $jenis = in_array($o['jenis'] ?? '', ['stat', 'detail', 'instansi', 'jabatan'], true) ? $o['jenis'] : 'stat';
    $all = bl_lap_peserta($k);
    $uniq = fn($key) => array_values(array_unique(array_map(fn($p) => $p[$key], $all)));
    $opsi = ['instansi' => $uniq('instansi'), 'jabatan' => $uniq('jabatan'),
        'kategori' => array_map(fn($x) => [$x[1], t('lap.kat.' . $x[1])], BL_KATEGORI)];
    sort($opsi['instansi']); sort($opsi['jabatan']);
    $pil = fn($key) => array_values(array_filter(array_map('strval', (array)($o[$key] ?? [])), fn($x) => $x !== ''));
    $fi = $pil('instansi'); $fj = $pil('jabatan'); $fk = $pil('kategori');
    $rows = array_values(array_filter($all, fn($p) => (!$fi || in_array($p['instansi'], $fi, true)) && (!$fj || in_array($p['jabatan'], $fj, true)) && (!$fk || in_array($p['kat'], $fk, true))));
    $kolomSemua = match ($jenis) {
        'stat' => ['komponen' => t('lap.t_komponen'), 'n' => t('lap.t_n'), 'rata' => t('lap.t_rata'), 'min' => t('lap.t_min'), 'max' => t('lap.t_max')],
        'detail' => ['no' => t('lap.t_no'), 'nama' => t('lap.t_nama'), 'instansi' => t('lap.t_instansi'), 'jabatan' => t('lap.t_jabatan'), 'pre' => t('lap.t_pre'), 'post' => t('lap.t_post'),
            'progres' => t('lap.t_progres'), 'praktik' => t('lap.t_praktik'), 'sikap' => t('lap.t_sikap'), 'skor' => t('lap.t_skor'), 'kategori' => t('lap.t_kategori')],
        'instansi' => ['no' => t('lap.t_no'), 'nama' => t('lap.t_instansi'), 'n' => t('lap.t_jumlah_peserta')],
        'jabatan' => ['no' => t('lap.t_no'), 'nama' => t('lap.t_jabatan'), 'n' => t('lap.t_jumlah_peserta')],
    };
    $kolomBawaan = $jenis === 'detail' ? ['no', 'nama', 'instansi', 'pre', 'post', 'progres', 'praktik', 'sikap', 'skor', 'kategori'] : array_keys($kolomSemua);
    $kolom = array_values(array_filter($pil('kolom'), fn($c) => isset($kolomSemua[$c])));
    if (!$kolom) $kolom = $kolomBawaan;
    $kolom = array_values(array_filter(array_keys($kolomSemua), fn($c) => in_array($c, $kolom, true)));   // urutan baku
    $urutOpsi = match ($jenis) {
        'detail' => ['skor', 'nama', 'instansi', 'jabatan', 'pre', 'post', 'progres', 'praktik', 'sikap'],
        'instansi', 'jabatan' => ['n', 'nama'],
        default => [],
    };
    $urut = in_array($o['urut'] ?? '', $urutOpsi, true) ? $o['urut'] : ($urutOpsi[0] ?? '');
    $turun = ($o['arah'] ?? '') === 'naik' ? false : (($o['arah'] ?? '') === 'turun' ? true : !in_array($urut, ['nama', 'instansi', 'jabatan'], true));
    $baris = []; $numKol = ['n', 'rata', 'min', 'max', 'pre', 'post', 'progres', 'praktik', 'sikap', 'skor', 'no', 'kategori'];
    if ($jenis === 'stat') {
        foreach (['pre' => 'lap.k_pre', 'post' => 'lap.k_post', 'progres' => 'lap.k_progres', 'praktik' => 'lap.k_praktik', 'sikap' => 'lap.k_sikap', 'skor' => 'lap.k_terbaik'] as $key => $label) {
            $v = array_values(array_filter(array_map(fn($r) => $r[$key], $rows), fn($x) => $x !== null));
            $baris[] = ['komponen' => t($label), 'n' => (string)count($v), 'rata' => $v ? bl_lap_num(array_sum($v) / count($v)) : '–', 'min' => $v ? bl_lap_num(min($v)) : '–', 'max' => $v ? bl_lap_num(max($v)) : '–'];
        }
    } elseif ($jenis === 'detail') {
        usort($rows, function ($a, $b) use ($urut, $turun) {
            $x = $a[$urut]; $y = $b[$urut];
            $c = is_string($x) || is_string($y) ? strcasecmp((string)$x, (string)$y) : (($x ?? -INF) <=> ($y ?? -INF));
            return $turun ? -$c : $c;
        });
        foreach ($rows as $i => $r) $baris[] = ['no' => (string)($i + 1), 'nama' => $r['nama'], 'instansi' => $r['instansi'], 'jabatan' => $r['jabatan'], 'pre' => bl_lap_num($r['pre']), 'post' => bl_lap_num($r['post']),
            'progres' => bl_lap_num($r['progres']), 'praktik' => bl_lap_num($r['praktik']), 'sikap' => bl_lap_num($r['sikap']), 'skor' => bl_lap_num($r['skor']), 'kategori' => bl_lap_kategori($r['skor'])];
    } else {
        $cnt = [];
        foreach ($rows as $r) $cnt[$r[$jenis]] = ($cnt[$r[$jenis]] ?? 0) + 1;
        $items = array_map(fn($n, $c) => ['nama' => (string)$n, 'n' => $c], array_keys($cnt), $cnt);
        usort($items, fn($a, $b) => ($urut === 'nama' ? strcasecmp($a['nama'], $b['nama']) : ($a['n'] <=> $b['n'] ?: -strcasecmp($a['nama'], $b['nama']))) * ($turun ? -1 : 1));
        foreach ($items as $i => $it) $baris[] = ['no' => (string)($i + 1), 'nama' => $it['nama'], 'n' => (string)$it['n']];
    }
    $kepala = array_map(fn($c) => $kolomSemua[$c], $kolom);
    $isi = array_map(fn($b) => array_map(fn($c) => (string)$b[$c], $kolom), $baris);
    $al = array_map(fn($c) => in_array($c, $numKol, true) ? 'c' : 'l', $kolom);
    $run = fn($s) => $s === '' ? [] : [['x' => $s, 'b' => false, 'i' => false, 'u' => false, 's' => 0]];
    $tbl = [array_map(fn($h, $a) => ['h' => true, 'al' => $a, 'r' => $run($h)], $kepala, $al)];
    foreach ($isi as $row) $tbl[] = array_map(fn($v, $a) => ['h' => false, 'al' => $a, 'r' => $run($v)], $row, $al);
    return ['jenis' => $jenis, 'kepala' => $kepala, 'baris' => $isi, 'n' => count($rows), 'html' => $isi ? bl_rt_html([['t' => 'tbl', 'rows' => $tbl]]) : '',
        'opsi' => $opsi + ['kolom' => array_map(fn($c, $l) => [$c, $l], array_keys($kolomSemua), $kolomSemua), 'urut' => array_map(fn($c) => [$c, $kolomSemua[$c]], $urutOpsi)],
        'pilih' => ['instansi' => $fi, 'jabatan' => $fj, 'kategori' => $fk, 'kolom' => $kolom, 'urut' => $urut, 'arah' => $turun ? 'turun' : 'naik']];
}

/* ---------- TOR (.docx): ambil bagian Pendahuluan / Latar Belakang ---------- */
function bl_tor_paragraphs(string $file): array
{
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) return [];
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) return [];
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml, LIBXML_NONET)) return [];
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $out = [];
    foreach ($xp->query('//w:body//w:p') as $p) {
        $txt = '';
        foreach ($xp->query('.//w:t|.//w:tab', $p) as $n) $txt .= $n->localName === 'tab' ? ' ' : $n->textContent;
        $style = (string)($xp->evaluate('string(w:pPr/w:pStyle/@w:val)', $p));
        $numbered = $xp->query('w:pPr/w:numPr', $p)->length > 0;
        $out[] = ['t' => trim(preg_replace('/\s+/u', ' ', $txt)), 'style' => $style, 'num' => $numbered];
    }
    return $out;
}
function bl_tor_is_heading(array $p): bool
{
    $t = $p['t'];
    if ($t === '' || mb_strlen($t) > 90) return false;
    if (preg_match('/^(heading|judul|title|berkas)/i', $p['style'])) return true;
    if (preg_match('/^(bab\s+[ivx\d]+|[ivx]+[\.\)]|[a-h][\.\)]|\d{1,2}[\.\)])\s+\S/iu', $t) && mb_strlen($t) <= 60 && !preg_match('/[\.:;]$/u', $t)) return true;
    return mb_strlen($t) <= 60 && mb_strtoupper($t) === $t && preg_match('/\p{L}{3}/u', $t);
}
function bl_tor_pendahuluan(string $file): string
{
    $ps = bl_tor_paragraphs($file);
    $on = false; $buf = [];
    foreach ($ps as $p) {
        if ($p['t'] === '') continue;
        $head = bl_tor_is_heading($p);
        if (!$on) { if ($head && preg_match('/pendahuluan|latar\s+belakang/iu', $p['t'])) $on = true; continue; }
        if ($head) {
            if (!$buf || preg_match('/latar\s+belakang/iu', $p['t'])) continue;   // sub-judul "Latar Belakang" di bawah "Pendahuluan"
            break;
        }
        $buf[] = ($p['num'] ? '- ' : '') . $p['t'];
    }
    return implode("\n", $buf);
}

