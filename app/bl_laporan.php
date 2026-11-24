<?php
/* Subdomain pembelajaran – Laporan Kegiatan (khusus Fasilitator): data laporan, pengambilan Pendahuluan dari TOR (.docx),
   serta penyusun berkas PDF (fon inti Times, tanpa pustaka luar) dan Word (.docx, OOXML lewat ZipArchive). Kertas A4,
   margin seperti SiCinta Verifikasi Metode: kop 10 mm dari atas, kiri-kanan 15 mm. */
// Tanpa strict_types: nilai dari basis data selalu berupa string dan dikonversi otomatis oleh PHP.

const BL_TOR_MAX = 5242880;   // 5 MB
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
    sp_insert('bl_laporan', ['keg_id' => $k['id'], 'user_id' => $u['id'], 'pelaksana' => (string)$k['penyelenggara'], 'mekanisme' => bl_lap_mekanisme_auto($k),
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
/** "Selasa – Rabu, 19–20 Mei 2026" */
function bl_lap_waktu(string $a, string $b): string
{
    $da = DateTimeImmutable::createFromFormat('Y-m-d', $a); $db = DateTimeImmutable::createFromFormat('Y-m-d', $b);
    if (!$da) return $db ? bl_lap_tgl($b, true) : '';
    if (!$db || $a === $b) return bl_lap_tgl($a, true);
    $H = sp_teks()['lap']['hari']; $B = sp_teks()['bulan'];
    $hari = $H[(int)$da->format('w')] . ' – ' . $H[(int)$db->format('w')];
    if ($da->format('Y-m') === $db->format('Y-m')) return $hari . ', ' . (int)$da->format('j') . '–' . (int)$db->format('j') . ' ' . $B[(int)$da->format('n') - 1] . ' ' . $da->format('Y');
    return $hari . ', ' . bl_lap_tgl($a) . ' – ' . bl_lap_tgl($b);
}
function bl_lap_kategori($v): string
{
    foreach (BL_KATEGORI as [$min, $key]) if ($v !== null && (float)$v >= $min) return t('lap.kat.' . $key);
    return '–';
}

/* ---------- Mekanisme: paragraf pengenalan Si PANDU + rundown dari jadwal ---------- */
function bl_lap_mekanisme_auto(array $k): string
{
    $lines = [t('lap.mek_intro'), t('lap.mek_rundown')];
    $i = 0;
    foreach (bl_segs((int)$k['id']) as $s) {
        $i++;
        $parts = [trim((string)$s['judul']) !== '' ? (string)$s['judul'] : t('bl.jenis_opsi.' . $s['jenis'])];
        $w = trim(($s['tanggal'] ? bl_lap_tgl($s['tanggal'], true) : '') . ($s['jam_mulai'] ? ', ' . str_replace(':', '.', $s['jam_mulai']) . ($s['jam_selesai'] ? '–' . str_replace(':', '.', $s['jam_selesai']) : '') : ''), ', ');
        if ($w !== '') $parts[] = $w;
        $pic = bl_pic_name($s);
        if ($pic !== '') $parts[] = t('lap.mek_pic', ['nama' => $pic]);
        $lines[] = $i . '. ' . implode(' – ', $parts);
        foreach (jdec($s['sesi']) ?: [] as $x) {
            $sub = trim((string)($x['title'] ?? ''));
            if ($sub === '') continue;
            $jam = !empty($x['from']) ? str_replace(':', '.', $x['from']) . (!empty($x['to']) ? '–' . str_replace(':', '.', $x['to']) : '') : '';
            $lines[] = '- ' . $sub . ($jam !== '' ? ' (' . $jam . ')' : '') . (!empty($x['pic']) ? ', ' . $x['pic'] : '');
        }
    }
    $lines[] = t('lap.mek_penilaian', ['n' => count(bl_soal((int)$k['id']))]);
    return implode("\n", $lines);
}

/* ---------- Hasil (otomatis dari data penilaian) ---------- */
function bl_lap_hasil(array $k): array
{
    $sc = bl_scores($k);
    $rows = $sc['rows'];
    $jab = [];
    foreach (qall("SELECT id, jabatan FROM bl_peserta WHERE keg_id = ? AND status = 'diterima'", [$k['id']]) as $p) $jab[(int)$p['id']] = trim((string)$p['jabatan']);
    $n = count($rows);
    $inst = []; $prof = [];
    foreach ($rows as $r) {
        $a = trim((string)$r['instansi']) ?: t('lap.tidak_diisi'); $inst[$a] = ($inst[$a] ?? 0) + 1;
        $b = ($jab[$r['pesertaId']] ?? '') ?: t('lap.tidak_diisi'); $prof[$b] = ($prof[$b] ?? 0) + 1;
    }
    arsort($inst); arsort($prof);
    $stat = [];
    foreach (['pre' => 'lap.k_pre', 'post' => 'lap.k_post', 'progres' => 'lap.k_progres', 'praktik' => 'lap.k_praktik', 'sikap' => 'lap.k_sikap', 'terbaik' => 'lap.k_terbaik'] as $key => $label) {
        $vals = array_values(array_filter(array_map(fn($r) => $r[$key], $rows), fn($v) => $v !== null));
        $stat[] = [t($label), count($vals), $vals ? bl_lap_num(array_sum($vals) / count($vals)) : '–', $vals ? bl_lap_num(min($vals)) : '–', $vals ? bl_lap_num(max($vals)) : '–'];
    }
    usort($rows, fn($a, $b) => ($b['terbaik'] ?? -1) <=> ($a['terbaik'] ?? -1));
    $detail = []; $kat = ['sb' => 0, 'b' => 0, 'c' => 0, 'k' => 0];
    foreach ($rows as $i => $r) {
        $detail[] = [(string)($i + 1), (string)$r['name'], (string)$r['instansi'], bl_lap_num($r['pre']), bl_lap_num($r['post']), bl_lap_num($r['progres']), bl_lap_num($r['praktik']), bl_lap_num($r['sikap']), bl_lap_num($r['terbaik']), bl_lap_kategori($r['terbaik'])];
        if ($r['terbaik'] !== null) foreach (BL_KATEGORI as [$min, $key]) if ((float)$r['terbaik'] >= $min) { $kat[$key]++; break; }
    }
    $sk = array_values(array_filter(array_map(fn($r) => $r['terbaik'], $rows), fn($v) => $v !== null));
    $avg = $sk ? array_sum($sk) / count($sk) : null;
    return [
        'pembuka' => t('lap.hasil_pembuka'),
        'p1' => t('lap.p1', ['n' => $n, 'ni' => count($inst), 'np' => count($prof)]),
        'inst' => array_map(fn($a, $c) => t('lap.butir_n', ['nama' => $a, 'n' => $c]), array_keys($inst), $inst),
        'prof' => array_map(fn($a, $c) => t('lap.butir_n', ['nama' => $a, 'n' => $c]), array_keys($prof), $prof),
        'p2' => t('lap.p2'), 'stat' => $stat, 'p2b' => t('lap.p2b'), 'detail' => $detail,
        'p3' => $avg === null ? t('lap.p3_kosong') : t('lap.p3', ['v' => bl_lap_num($avg), 'kat' => bl_lap_kategori($avg), 'sb' => $kat['sb'], 'b' => $kat['b'], 'c' => $kat['c'], 'k' => $kat['k']]),
        'kat_ket' => t('lap.kat_ket'),
    ];
}

/** Semua isi laporan (untuk layar, PDF, dan Word) */
function bl_lap_data(array $u, array $k): array
{
    $r = bl_lap_row($u, $k);
    $pemb = $r['pembina_id'] ? q1("SELECT nama FROM bl_users WHERE id = ? AND role = 'pembina'", [$r['pembina_id']]) : null;
    return [
        'kegId' => (int)$k['id'], 'title' => $k['judul'],
        'ident' => [[t('lap.i_judul'), (string)$k['judul']], [t('lap.i_penyelenggara'), (string)$k['penyelenggara']], [t('lap.i_pelaksana'), (string)$r['pelaksana']],
            [t('lap.i_fasilitator'), (string)$u['nama']], [t('lap.i_waktu'), bl_lap_waktu((string)$k['tgl_mulai'], (string)$k['tgl_selesai'])], [t('lap.i_tempat'), (string)$k['lokasi']]],
        'f' => ['pelaksana' => (string)$r['pelaksana'], 'pendahuluan' => (string)$r['pendahuluan'], 'mekanisme' => (string)$r['mekanisme'], 'kendala' => (string)$r['kendala'],
            'kesimpulan' => (string)$r['kesimpulan'], 'rekomendasi' => (string)$r['rekomendasi'], 'pembinaId' => $r['pembina_id'] ? (int)$r['pembina_id'] : null,
            'ttdTempat' => (string)$r['ttd_tempat'], 'ttdTanggal' => (string)$r['ttd_tanggal']],
        'hasTor' => (bool)$r['tor'], 'updated' => $r['updated_at'],
        'hasil' => bl_lap_hasil($k),
        'ttd' => ['tempat' => (string)$r['ttd_tempat'], 'tanggal' => $r['ttd_tanggal'] ? bl_lap_tgl($r['ttd_tanggal']) : '', 'fasil' => (string)$u['nama'], 'pembina' => $pemb ? (string)$pemb['nama'] : ''],
        'pembinaList' => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['nama']], qall("SELECT id, nama FROM bl_users WHERE role = 'pembina' AND aktif = 1 ORDER BY nama")),
    ];
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

/* ---------- kop: kop SiCinta (data/kop atau app/kop_sicinta.png); bila tidak ada, kop Pembina Si PANDU ---------- */
function bl_lap_kop_jpeg(): ?array
{
    $cand = [data_dir('kop') . '/kop_sicinta.png', data_dir('kop') . '/kop_sicinta.jpg', __DIR__ . '/kop_sicinta.png'];   // kop SiCinta terbaru ikut di repo (folder app/ tertutup dari web)
    $k = q1("SELECT file FROM kops WHERE owner_key = 'pembina'");
    if ($k) $cand[] = data_dir('kop') . '/' . basename($k['file']);
    foreach ($cand as $f) {
        if (!is_file($f)) continue;
        $src = @imagecreatefromstring((string)file_get_contents($f));
        if (!$src) continue;
        $w = imagesx($src); $h = imagesy($src);
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imagecopy($im, $src, 0, 0, 0, 0, $w, $h);
        ob_start(); imagejpeg($im, null, 92); $jpg = (string)ob_get_clean();
        imagedestroy($src); imagedestroy($im);
        return ['data' => $jpg, 'w' => $w, 'h' => $h];
    }
    return null;
}

/* ---------- blok isi laporan (dipakai PDF & Word) ---------- */
/** Ubah teks isian menjadi blok: paragraf, butir bernomor ("1. "), atau butir ("- ") */
function bl_lap_blocks(string $text): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {
        $l = trim($line);
        if ($l === '') continue;
        if (preg_match('/^(\d{1,2})[\.\)]\s+(.*)$/u', $l, $m)) $out[] = ['num', $m[1] . '.', $m[2]];
        elseif (preg_match('/^[-•*]\s+(.*)$/u', $l, $m)) $out[] = ['bul', '–', $m[1]];
        else $out[] = ['p', '', $l];
    }
    return $out;
}

/* ================= PDF (fon inti Times, WinAnsi) ================= */
final class BlPdf
{
    private array $pages = [];
    private string $buf = '';
    private array $W;
    public float $y = 0;
    public const PW = 595.28, PH = 841.89;
    public float $ml = 42.52, $mr = 42.52, $mt = 28.35, $mb = 56.7;
    private ?array $img = null;
    public string $footer = '';
    private const FONTS = ['R' => 'Times-Roman', 'B' => 'Times-Bold', 'I' => 'Times-Italic', 'BI' => 'Times-BoldItalic'];

    public function __construct() { $this->W = require __DIR__ . '/pdf_fonts.php'; }
    public static function enc(string $s): string
    {
        $s = strtr($s, ['≤' => '<=', '≥' => '>=', '−' => '-', "\u{00A0}" => ' ', '’' => "'", '‘' => "'"]);
        $r = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);
        return $r === false ? (string)mb_convert_encoding($s, 'CP1252', 'UTF-8') : $r;
    }
    public function width(string $s, string $f, float $size): float
    {
        $w = 0; $tbl = $this->W[self::FONTS[$f]];
        for ($i = 0, $n = strlen($s); $i < $n; $i++) $w += $tbl[ord($s[$i])];
        return $w * $size / 1000;
    }
    public function addPage(): void
    {
        if ($this->buf !== '' || $this->pages) $this->pages[] = $this->buf;
        $this->buf = '';
        $this->y = $this->mt;
    }
    public function ensure(float $h): void { if ($this->y + $h > self::PH - $this->mb) $this->addPage(); }
    private static function esc(string $s): string { return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '']); }
    public function text(float $x, float $y, string $s, string $f = 'R', float $size = 11, float $tw = 0, float $gray = 0): void
    {
        $fn = ['R' => 'F1', 'B' => 'F2', 'I' => 'F3', 'BI' => 'F4'][$f];
        $this->buf .= sprintf("BT %s rg /%s %.2F Tf %.3F Tw %.2F %.2F Td (%s) Tj ET\n", $gray ? "$gray $gray $gray" : '0 0 0', $fn, $size, $tw, $x, self::PH - $y, self::esc($s));
    }
    public function line(float $x1, float $y1, float $x2, float $y2, float $w = 0.5): void
    {
        $this->buf .= sprintf("%.2F w %.2F %.2F m %.2F %.2F l S\n", $w, $x1, self::PH - $y1, $x2, self::PH - $y2);
    }
    public function image(array $img, float $x, float $y, float $w): float
    {
        $this->img = $img;
        $h = $w * $img['h'] / $img['w'];
        $this->buf .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q\n", $w, $h, $x, self::PH - $y - $h);
        return $h;
    }
    /** Pecah teks (sudah WinAnsi) menjadi baris dengan lebar maksimum */
    public function wrap(string $s, string $f, float $size, float $maxw): array
    {
        $lines = []; $cur = '';
        foreach (explode(' ', $s) as $word) {
            $try = $cur === '' ? $word : $cur . ' ' . $word;
            if ($this->width($try, $f, $size) <= $maxw || $cur === '') { $cur = $try; continue; }
            $lines[] = $cur; $cur = $word;
        }
        $lines[] = $cur;
        // kata yang lebih panjang dari baris dipotong paksa
        $out = [];
        foreach ($lines as $l) {
            while ($this->width($l, $f, $size) > $maxw && strlen($l) > 1) {
                $cut = strlen($l) - 1;
                while ($cut > 1 && $this->width(substr($l, 0, $cut), $f, $size) > $maxw) $cut--;
                $out[] = substr($l, 0, $cut); $l = substr($l, $cut);
            }
            $out[] = $l;
        }
        return $out;
    }
    /** Paragraf rata kiri-kanan; $first = indentasi baris pertama */
    public function para(string $s, float $x, float $w, string $f = 'R', float $size = 11, float $first = 0, bool $justify = true, float $lh = 1.35): void
    {
        $s = self::enc($s);
        $lines = $this->wrapFirst($s, $f, $size, $w, $first);
        $n = count($lines);
        foreach ($lines as $i => $l) {
            $this->ensure($size * $lh);
            $xx = $x + ($i === 0 ? $first : 0);
            $ww = $w - ($i === 0 ? $first : 0);
            $tw = 0;
            $sp = substr_count($l, ' ');
            if ($justify && $i < $n - 1 && $sp > 0) $tw = ($ww - $this->width($l, $f, $size)) / $sp;
            $this->y += $size * $lh;
            $this->text($xx, $this->y - $size * 0.3, $l, $f, $size, $tw);
        }
    }
    private function wrapFirst(string $s, string $f, float $size, float $w, float $first): array
    {
        if ($first == 0) return $this->wrap($s, $f, $size, $w);
        $words = explode(' ', $s); $l1 = '';
        while ($words) { $try = $l1 === '' ? $words[0] : $l1 . ' ' . $words[0]; if ($l1 !== '' && $this->width($try, $f, $size) > $w - $first) break; $l1 = $try; array_shift($words); }
        return array_merge([$l1], $words ? $this->wrap(implode(' ', $words), $f, $size, $w) : []);
    }
    /** Tabel: lebar kolom (pt), baris pertama = kepala (tebal, abu-abu) */
    public function table(array $rows, array $cw, float $x, float $size = 9.5, array $align = []): void
    {
        $pad = 3; $lh = $size * 1.25;
        $this->ensure(2 * ($lh * 2 + 2 * $pad));   // kepala tabel tidak tertinggal sendirian di bawah halaman
        foreach ($rows as $ri => $row) {
            $f = $ri === 0 ? 'B' : 'R';
            $cells = []; $h = 0;
            foreach ($row as $ci => $c) { $cells[$ci] = $this->wrap(self::enc((string)$c), $f, $size, $cw[$ci] - 2 * $pad); $h = max($h, count($cells[$ci]) * $lh + 2 * $pad); }
            if ($this->y + $h > self::PH - $this->mb) { $this->addPage(); if ($ri > 0) { $this->tableRow($rows[0], $cw, $x, $size, $align, 'B'); } }
            $this->tableRow($row, $cw, $x, $size, $align, $f, $cells, $h);
        }
    }
    private function tableRow(array $row, array $cw, float $x, float $size, array $align, string $f, ?array $cells = null, ?float $h = null): void
    {
        $pad = 3; $lh = $size * 1.25;
        if ($cells === null) { $h = 0; foreach ($row as $ci => $c) { $cells[$ci] = $this->wrap(self::enc((string)$c), $f, $size, $cw[$ci] - 2 * $pad); $h = max($h, count($cells[$ci]) * $lh + 2 * $pad); } }
        $top = $this->y; $xx = $x;
        if ($f === 'B') $this->buf .= sprintf("0.92 g %.2F %.2F %.2F %.2F re f 0 g\n", $x, self::PH - $top - $h, array_sum($cw), $h);
        foreach ($cw as $ci => $w) {
            foreach ($cells[$ci] as $li => $l) {
                $tx = $xx + $pad;
                $a = $align[$ci] ?? 'L';
                if ($a === 'C') $tx = $xx + ($w - $this->width($l, $f, $size)) / 2;
                elseif ($a === 'R') $tx = $xx + $w - $pad - $this->width($l, $f, $size);
                $this->text($tx, $top + $pad + ($li + 1) * $lh - $size * 0.25, $l, $f, $size);
            }
            $this->buf .= sprintf("0.5 w %.2F %.2F %.2F %.2F re S\n", $xx, self::PH - $top - $h, $w, $h);
            $xx += $w;
        }
        $this->y = $top + $h;
    }
    public function output(): string
    {
        $this->pages[] = $this->buf; $this->buf = '';
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $fontStart = 3;
        foreach (array_values(self::FONTS) as $i => $name) $objs[$fontStart + $i] = "<< /Type /Font /Subtype /Type1 /BaseFont /$name /Encoding /WinAnsiEncoding >>";
        $next = $fontStart + 4;
        $imgRef = '';
        if ($this->img) {
            $objs[$next] = ['<< /Type /XObject /Subtype /Image /Width ' . $this->img['w'] . ' /Height ' . $this->img['h'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($this->img['data']) . ' >>', $this->img['data']];
            $imgRef = "/XObject << /Im1 $next 0 R >>";
            $next++;
        }
        $res = "<< /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R /F4 6 0 R >> $imgRef >>";
        $kids = [];
        $np = count($this->pages);
        foreach ($this->pages as $pi => $content) {
            if ($this->footer !== '') {
                $ft = self::enc($this->footer);
                $content .= sprintf("BT 0.45 0.45 0.45 rg /F3 7.5 Tf %.2F %.2F Td (%s) Tj ET\n", self::PW - $this->mr - $this->width($ft, 'I', 7.5), 22, self::esc($ft));
                $pg = self::enc(t('lap.halaman', ['n' => $pi + 1, 'm' => $np]));
                $content .= sprintf("BT 0.45 0.45 0.45 rg /F1 7.5 Tf %.2F %.2F Td (%s) Tj ET\n", $this->ml, 22, self::esc($pg));
            }
            $c = $next++; $p = $next++;
            $objs[$c] = ['<< /Length ' . strlen($content) . ' >>', $content];
            $objs[$p] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . self::PW . ' ' . self::PH . "] /Resources $res /Contents $c 0 R >>";
            $kids[] = "$p 0 R";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $off = [];
        foreach ($objs as $i => $o) {
            $off[$i] = strlen($out);
            $out .= is_array($o) ? "$i 0 obj\n{$o[0]}\nstream\n{$o[1]}\nendstream\nendobj\n" : "$i 0 obj\n$o\nendobj\n";
        }
        $x = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) $out .= isset($off[$i]) ? sprintf("%010d 00000 n \n", $off[$i]) : "0000000000 65535 f \n";
        return $out . "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
    }
}

function bl_lap_pdf(array $L): string
{
    $p = new BlPdf();
    $p->footer = t('app.hak_cipta');
    $p->addPage();
    $x = $p->ml; $w = BlPdf::PW - $p->ml - $p->mr; $fs = 11;
    $kop = bl_lap_kop_jpeg();
    if ($kop) $p->y += $p->image($kop, $x, $p->y, $w) + 10;
    $p->y += 14;
    $title = BlPdf::enc(t('lap.judul_dok'));
    $tw = $p->width($title, 'B', 12.5);
    $p->text($x + ($w - $tw) / 2, $p->y, $title, 'B', 12.5);
    $p->line($x + ($w - $tw) / 2, $p->y + 2, $x + ($w + $tw) / 2, $p->y + 2, 0.6);
    $p->y += 14;
    // identitas
    foreach ($L['ident'] as [$lab, $val]) {
        $lines = $p->wrap(BlPdf::enc($val), 'R', $fs, $w - 150);
        $p->ensure(count($lines) * $fs * 1.35);
        $p->y += $fs * 1.35;
        $p->text($x, $p->y - 3, BlPdf::enc($lab), 'B', $fs);
        $p->text($x + 135, $p->y - 3, ':', 'R', $fs);
        foreach ($lines as $i => $l) { if ($i) $p->y += $fs * 1.35; $p->text($x + 145, $p->y - 3, $l, 'R', $fs); }
    }
    $p->y += 8;
    $sec = function (string $label) use ($p, $x, $fs) { $p->ensure(40); $p->y += $fs * 1.6; $p->text($x, $p->y - 3, BlPdf::enc($label), 'B', $fs); };
    $body = function (string $text) use ($p, $x, $w, $fs) {
        foreach (bl_lap_blocks($text) as [$type, $mark, $t]) {
            if ($type === 'p') { $p->para($t, $x + 14, $w - 14, 'R', $fs, 22); continue; }
            $ind = $type === 'num' ? 14 : 30;
            $p->ensure($fs * 1.35);
            $y0 = $p->y;
            $p->para($t, $x + $ind + 14, $w - $ind - 14, 'R', $fs, 0);
            $p->text($x + $ind, $y0 + $fs * 1.35 - $fs * 0.3, BlPdf::enc($mark), 'R', $fs);
        }
    };
    $sec(t('lap.sec_a')); $body($L['f']['pendahuluan'] ?: t('lap.belum_diisi'));
    $sec(t('lap.sec_b')); $body($L['f']['mekanisme'] ?: t('lap.belum_diisi'));
    $h = $L['hasil'];
    $sec(t('lap.sec_c'));
    $p->para($h['pembuka'], $x + 14, $w - 14, 'R', $fs, 22);
    $item = function (string $n, string $t) use ($p, $x, $w, $fs) { $p->ensure($fs * 1.4); $y0 = $p->y; $p->para($t, $x + 28, $w - 28, 'R', $fs, 0); $p->text($x + 14, $y0 + $fs * 1.35 - $fs * 0.3, $n, 'R', $fs); };
    $item('1.', $h['p1']);
    $p->para(t('lap.menurut_instansi'), $x + 28, $w - 28, 'I', $fs, 0, false);
    foreach ($h['inst'] as $b) { $y0 = $p->y; $p->para($b, $x + 42, $w - 42, 'R', $fs, 0, false); $p->text($x + 32, $y0 + $fs * 1.35 - $fs * 0.3, '-', 'R', $fs); }
    $p->para(t('lap.menurut_profesi'), $x + 28, $w - 28, 'I', $fs, 0, false);
    foreach ($h['prof'] as $b) { $y0 = $p->y; $p->para($b, $x + 42, $w - 42, 'R', $fs, 0, false); $p->text($x + 32, $y0 + $fs * 1.35 - $fs * 0.3, '-', 'R', $fs); }
    $item('2.', $h['p2']);
    $p->y += 4;
    $tw2 = $w - 28;
    $p->table(array_merge([[t('lap.t_komponen'), t('lap.t_n'), t('lap.t_rata'), t('lap.t_min'), t('lap.t_max')]], $h['stat']), [$tw2 * 0.44, $tw2 * 0.14, $tw2 * 0.14, $tw2 * 0.14, $tw2 * 0.14], $x + 28, 9.5, [1 => 'C', 2 => 'C', 3 => 'C', 4 => 'C']);
    $p->y += 6;
    $p->para($h['p2b'], $x + 28, $w - 28, 'R', $fs, 0);
    $p->y += 4;
    $cw = [0.05, 0.2, 0.17, 0.08, 0.08, 0.08, 0.08, 0.08, 0.08, 0.10];
    $p->table(array_merge([[t('lap.t_no'), t('lap.t_nama'), t('lap.t_instansi'), t('lap.t_pre'), t('lap.t_post'), t('lap.t_progres'), t('lap.t_praktik'), t('lap.t_sikap'), t('lap.t_skor'), t('lap.t_kategori')]], $h['detail']),
        array_map(fn($f) => $f * $w, $cw), $x, 8.5, [0 => 'C', 3 => 'C', 4 => 'C', 5 => 'C', 6 => 'C', 7 => 'C', 8 => 'C', 9 => 'C']);
    $p->y += 6;
    $item('3.', $h['p3']);
    $p->para($h['kat_ket'], $x + 28, $w - 28, 'I', 9.5, 0, false);
    $sec(t('lap.sec_d')); $body($L['f']['kendala'] ?: t('lap.belum_diisi'));
    $sec(t('lap.sec_e')); $body($L['f']['kesimpulan'] ?: t('lap.belum_diisi'));
    $sec(t('lap.sec_f')); $body($L['f']['rekomendasi'] ?: t('lap.belum_diisi'));
    // tanda tangan basah
    $p->ensure(130);
    $p->y += 26;
    $t = $L['ttd'];
    $lx = $x + 10; $rx = $x + $w * 0.58;
    $p->text($lx, $p->y, BlPdf::enc(trim($t['tempat'] . ', ' . $t['tanggal'], ', ')), 'R', $fs);
    $p->text($rx, $p->y, BlPdf::enc(t('lap.mengetahui')), 'R', $fs);
    $p->y += $fs * 1.35;
    $p->text($lx, $p->y, BlPdf::enc(t('lap.ttd_fasil')), 'R', $fs);
    $p->text($rx, $p->y, BlPdf::enc(t('lap.ttd_pembina')), 'R', $fs);
    $p->y += 70;
    $nf = BlPdf::enc($t['fasil']); $np = BlPdf::enc($t['pembina'] !== '' ? $t['pembina'] : str_repeat('.', 40));
    $p->text($lx, $p->y, $nf, 'B', $fs); $p->line($lx, $p->y + 2, $lx + $p->width($nf, 'B', $fs), $p->y + 2, 0.5);
    $p->text($rx, $p->y, $np, 'B', $fs); if ($t['pembina'] !== '') $p->line($rx, $p->y + 2, $rx + $p->width($np, 'B', $fs), $p->y + 2, 0.5);
    return $p->output();
}

/* ================= Word (.docx) ================= */
function bl_docx_x(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function bl_docx_run(string $t, bool $b = false, bool $i = false, int $sz = 22): string
{
    return '<w:r><w:rPr>' . ($b ? '<w:b/>' : '') . ($i ? '<w:i/>' : '') . '<w:sz w:val="' . $sz . '"/></w:rPr><w:t xml:space="preserve">' . bl_docx_x($t) . '</w:t></w:r>';
}
/** $ind: [kiri, gantung/baris-pertama] dalam twip; jc: both|left|center */
function bl_docx_p(string $runs, string $jc = 'both', int $left = 0, int $first = 0, int $hanging = 0, int $after = 60): string
{
    $ind = $left || $first || $hanging ? '<w:ind w:left="' . $left . '"' . ($first ? ' w:firstLine="' . $first . '"' : '') . ($hanging ? ' w:hanging="' . $hanging . '"' : '') . '/>' : '';
    return '<w:p><w:pPr><w:spacing w:after="' . $after . '" w:line="300" w:lineRule="auto"/>' . $ind . '<w:jc w:val="' . $jc . '"/></w:pPr>' . $runs . '</w:p>';
}
function bl_docx_body(string $text): string
{
    $x = '';
    foreach (bl_lap_blocks($text) as [$type, $mark, $t]) {
        if ($type === 'p') $x .= bl_docx_p(bl_docx_run($t), 'both', 280, 440);
        elseif ($type === 'num') $x .= bl_docx_p(bl_docx_run($mark . "\t" . $t), 'both', 560, 0, 280);
        else $x .= bl_docx_p(bl_docx_run($mark . "\t" . $t), 'both', 880, 0, 280);
    }
    return $x;
}
function bl_docx_table(array $rows, array $cw, int $sz = 18, int $indent = 0, array $center = []): string
{
    $x = '<w:tbl><w:tblPr><w:tblW w:w="' . array_sum($cw) . '" w:type="dxa"/>' . ($indent ? '<w:tblInd w:w="' . $indent . '" w:type="dxa"/>' : '') . '<w:tblBorders>'
        . implode('', array_map(fn($e) => "<w:$e w:val=\"single\" w:sz=\"4\" w:space=\"0\" w:color=\"000000\"/>", ['top', 'left', 'bottom', 'right', 'insideH', 'insideV']))
        . '</w:tblBorders><w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="57" w:type="dxa"/><w:right w:w="57" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>'
        . implode('', array_map(fn($w) => '<w:gridCol w:w="' . $w . '"/>', $cw)) . '</w:tblGrid>';
    foreach ($rows as $ri => $row) {
        $x .= '<w:tr>' . ($ri === 0 ? '<w:trPr><w:tblHeader/></w:trPr>' : '');
        foreach ($row as $ci => $c) {
            $x .= '<w:tc><w:tcPr><w:tcW w:w="' . $cw[$ci] . '" w:type="dxa"/>' . ($ri === 0 ? '<w:shd w:val="clear" w:color="auto" w:fill="EBEBEB"/>' : '') . '</w:tcPr>'
                . '<w:p><w:pPr><w:spacing w:before="20" w:after="20"/><w:jc w:val="' . (in_array($ci, $center, true) ? 'center' : 'left') . '"/></w:pPr>' . bl_docx_run((string)$c, $ri === 0, false, $sz) . '</w:p></w:tc>';
        }
        $x .= '</w:tr>';
    }
    return $x . '</w:tbl>';
}
function bl_lap_docx(array $L): string
{
    $W = 11906 - 850 - 850;   // lebar teks (twip): A4, kiri-kanan 15 mm
    $kop = bl_lap_kop_jpeg();
    $x = '';
    if ($kop) {
        $cx = (int)round($W / 1440 * 914400); $cy = (int)round($cx * $kop['h'] / $kop['w']);
        $x .= '<w:p><w:pPr><w:spacing w:after="120"/><w:jc w:val="center"/></w:pPr><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="1" name="Kop"/>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:nvPicPr><pic:cNvPr id="1" name="kop.jpg"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="rIdKop"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }
    $x .= '<w:p><w:pPr><w:spacing w:before="200" w:after="200"/><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/><w:u w:val="single"/><w:sz w:val="25"/></w:rPr><w:t>' . bl_docx_x(t('lap.judul_dok')) . '</w:t></w:r></w:p>';
    $rows = '';
    foreach ($L['ident'] as [$lab, $val]) $rows .= '<w:tr><w:tc><w:tcPr><w:tcW w:w="2700" w:type="dxa"/></w:tcPr><w:p><w:pPr><w:spacing w:after="40"/></w:pPr>' . bl_docx_run($lab, true) . '</w:p></w:tc>'
        . '<w:tc><w:tcPr><w:tcW w:w="300" w:type="dxa"/></w:tcPr><w:p><w:pPr><w:spacing w:after="40"/></w:pPr>' . bl_docx_run(':') . '</w:p></w:tc>'
        . '<w:tc><w:tcPr><w:tcW w:w="' . ($W - 3000) . '" w:type="dxa"/></w:tcPr><w:p><w:pPr><w:spacing w:after="40"/></w:pPr>' . bl_docx_run($val) . '</w:p></w:tc></w:tr>';
    $x .= '<w:tbl><w:tblPr><w:tblW w:w="' . $W . '" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="0" w:type="dxa"/><w:right w:w="57" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid><w:gridCol w:w="2700"/><w:gridCol w:w="300"/><w:gridCol w:w="' . ($W - 3000) . '"/></w:tblGrid>' . $rows . '</w:tbl>';
    $sec = fn($s) => '<w:p><w:pPr><w:keepNext/><w:spacing w:before="200" w:after="60"/></w:pPr>' . bl_docx_run($s, true) . '</w:p>';
    $x .= $sec(t('lap.sec_a')) . bl_docx_body($L['f']['pendahuluan'] ?: t('lap.belum_diisi'));
    $x .= $sec(t('lap.sec_b')) . bl_docx_body($L['f']['mekanisme'] ?: t('lap.belum_diisi'));
    $h = $L['hasil'];
    $x .= $sec(t('lap.sec_c')) . bl_docx_p(bl_docx_run($h['pembuka']), 'both', 280, 440);
    $x .= bl_docx_p(bl_docx_run("1.\t" . $h['p1']), 'both', 560, 0, 280);
    $x .= bl_docx_p(bl_docx_run(t('lap.menurut_instansi'), false, true), 'left', 560);
    foreach ($h['inst'] as $b) $x .= bl_docx_p(bl_docx_run("-\t" . $b), 'left', 880, 0, 280, 20);
    $x .= bl_docx_p(bl_docx_run(t('lap.menurut_profesi'), false, true), 'left', 560);
    foreach ($h['prof'] as $b) $x .= bl_docx_p(bl_docx_run("-\t" . $b), 'left', 880, 0, 280, 20);
    $x .= bl_docx_p(bl_docx_run("2.\t" . $h['p2']), 'both', 560, 0, 280);
    $tw = $W - 560;
    $x .= bl_docx_table(array_merge([[t('lap.t_komponen'), t('lap.t_n'), t('lap.t_rata'), t('lap.t_min'), t('lap.t_max')]], $h['stat']), array_map(fn($f) => (int)($f * $tw), [0.44, 0.14, 0.14, 0.14, 0.14]), 19, 560, [1, 2, 3, 4]);
    $x .= '<w:p><w:pPr><w:spacing w:after="0" w:line="120" w:lineRule="exact"/></w:pPr></w:p>' . bl_docx_p(bl_docx_run($h['p2b']), 'both', 560, 0, 0, 60);
    $x .= bl_docx_table(array_merge([[t('lap.t_no'), t('lap.t_nama'), t('lap.t_instansi'), t('lap.t_pre'), t('lap.t_post'), t('lap.t_progres'), t('lap.t_praktik'), t('lap.t_sikap'), t('lap.t_skor'), t('lap.t_kategori')]], $h['detail']),
        array_map(fn($f) => (int)($f * $W), [0.05, 0.2, 0.17, 0.08, 0.08, 0.08, 0.08, 0.08, 0.08, 0.10]), 17, 0, [0, 3, 4, 5, 6, 7, 8, 9]);
    $x .= '<w:p><w:pPr><w:spacing w:after="0" w:line="120" w:lineRule="exact"/></w:pPr></w:p>' . bl_docx_p(bl_docx_run("3.\t" . $h['p3']), 'both', 560, 0, 280);
    $x .= bl_docx_p(bl_docx_run($h['kat_ket'], false, true, 19), 'left', 560);
    $x .= $sec(t('lap.sec_d')) . bl_docx_body($L['f']['kendala'] ?: t('lap.belum_diisi'));
    $x .= $sec(t('lap.sec_e')) . bl_docx_body($L['f']['kesimpulan'] ?: t('lap.belum_diisi'));
    $x .= $sec(t('lap.sec_f')) . bl_docx_body($L['f']['rekomendasi'] ?: t('lap.belum_diisi'));
    // tanda tangan basah: tabel tanpa garis dua kolom
    $t = $L['ttd'];
    $cell = fn($lines, $w) => '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/></w:tcPr>' . implode('', $lines) . '</w:tc>';
    $pl = fn($s, $b = false, $u = false) => '<w:p><w:pPr><w:keepNext/><w:spacing w:after="0"/></w:pPr><w:r><w:rPr>' . ($b ? '<w:b/>' : '') . ($u ? '<w:u w:val="single"/>' : '') . '<w:sz w:val="22"/></w:rPr><w:t xml:space="preserve">' . bl_docx_x($s) . '</w:t></w:r></w:p>';
    $gap = '<w:p><w:pPr><w:keepNext/><w:spacing w:after="0"/></w:pPr></w:p>';
    $half = (int)($W / 2);
    $x .= '<w:p><w:pPr><w:keepNext/><w:spacing w:before="300"/></w:pPr></w:p><w:tbl><w:tblPr><w:tblW w:w="' . $W . '" w:type="dxa"/><w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid><w:gridCol w:w="' . $half . '"/><w:gridCol w:w="' . ($W - $half) . '"/></w:tblGrid><w:tr><w:trPr><w:cantSplit/></w:trPr>'
        . $cell([$pl(trim($t['tempat'] . ', ' . $t['tanggal'], ', ')), $pl(t('lap.ttd_fasil')), $gap, $gap, $gap, $gap, $pl($t['fasil'], true, true)], $half)
        . $cell([$pl(t('lap.mengetahui')), $pl(t('lap.ttd_pembina')), $gap, $gap, $gap, $gap, $pl($t['pembina'] !== '' ? $t['pembina'] : str_repeat('.', 40), true, $t['pembina'] !== '')], $W - $half)
        . '</w:tr></w:tbl>';
    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><w:body>'
        . $x . '<w:sectPr><w:footerReference w:type="default" r:id="rIdFtr"/><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="567" w:right="850" w:bottom="1134" w:left="850" w:header="340" w:footer="340" w:gutter="0"/></w:sectPr></w:body></w:document>';
    $ftr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:pPr><w:jc w:val="right"/></w:pPr><w:r><w:rPr><w:i/><w:color w:val="737373"/><w:sz w:val="15"/></w:rPr><w:t>' . bl_docx_x(t('app.hak_cipta')) . '</w:t></w:r></w:p></w:ftr>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman" w:eastAsia="Times New Roman"/><w:sz w:val="22"/><w:lang w:val="id-ID"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="60"/></w:pPr></w:pPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style></w:styles>';
    $tmp = tempnam(sys_get_temp_dir(), 'lap');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="jpg" ContentType="image/jpeg"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $z->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdSty" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rIdFtr" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>' . ($kop ? '<Relationship Id="rIdKop" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/kop.jpg"/>' : '') . '</Relationships>');
    $z->addFromString('word/document.xml', $doc);
    $z->addFromString('word/styles.xml', $styles);
    $z->addFromString('word/footer1.xml', $ftr);
    if ($kop) $z->addFromString('word/media/kop.jpg', $kop['data']);
    $z->close();
    $bin = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}
