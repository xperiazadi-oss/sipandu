<?php
/* Laporan Kegiatan – penyusun berkas PDF tanpa pustaka luar: fon inti Times (isi) & Helvetica (sampul), WinAnsi;
   gambar JPEG (DCTDecode) dengan masker alfa (SMask) untuk templat sampul; teks berformat dari bl_rt.php. */

final class BlPdf
{
    private array $pages = [];
    private string $buf = '';
    private bool $started = false;
    private array $W;
    public float $y = 0;
    public const PW = 595.28, PH = 841.89;
    public float $ml = 85.04, $mr = 70.87, $mt = 70.87, $mb = 70.87;   // kiri 3 cm, kanan/atas/bawah 2,5 cm
    private array $imgs = [];
    public string $footer = '';
    public string $stamp = '';
    public array $tanpaFooter = [];   // nomor halaman (1-based) tanpa catatan kaki
    public array $capDiAtas = [];     // nomor halaman yang cap DRAF-nya digambar di atas isi (sampul bergambar)
    private const FONTS = ['R' => 'Times-Roman', 'B' => 'Times-Bold', 'I' => 'Times-Italic', 'BI' => 'Times-BoldItalic', 'H' => 'Helvetica', 'HB' => 'Helvetica-Bold'];
    private const FN = ['R' => 'F1', 'B' => 'F2', 'I' => 'F3', 'BI' => 'F4', 'H' => 'F5', 'HB' => 'F6'];

    public function __construct() { $this->W = require __DIR__ . '/pdf_fonts.php'; }
    public static function enc(string $s): string
    {
        $s = strtr($s, ['≤' => '<=', '≥' => '>=', '−' => '-', "\u{00A0}" => ' ', '’' => "'", '‘' => "'", "\t" => ' ', '◦' => 'o']);
        $r = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);
        return $r === false ? (string)mb_convert_encoding($s, 'CP1252', 'UTF-8') : $r;
    }
    public static function font(bool $b, bool $i): string { return $b ? ($i ? 'BI' : 'B') : ($i ? 'I' : 'R'); }
    public function width(string $s, string $f, float $size): float
    {
        $w = 0; $tbl = $this->W[self::FONTS[$f]];
        for ($i = 0, $n = strlen($s); $i < $n; $i++) $w += $tbl[ord($s[$i])];
        return $w * $size / 1000;
    }
    public function page(): int { return count($this->pages) + 1; }
    public function addPage(): void
    {
        if ($this->started) $this->pages[] = $this->buf;
        $this->started = true;
        $this->buf = '';
        $this->y = $this->mt;
    }
    public function bottom(): float { return self::PH - $this->mb; }
    public function ensure(float $h): void { if ($this->y + $h > $this->bottom()) $this->addPage(); }
    private static function esc(string $s): string { return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '']); }
    public function raw(string $ops): void { $this->buf .= $ops; }
    /** $y = garis dasar (dari atas halaman); $rgb = "r g b" */
    public function text(float $x, float $y, string $s, string $f = 'R', float $size = 12, float $tw = 0, string $rgb = '0 0 0'): void
    {
        $this->buf .= sprintf("BT %s rg /%s %.2F Tf %.3F Tw %.2F %.2F Td (%s) Tj ET\n", $rgb, self::FN[$f], $size, $tw, $x, self::PH - $y, self::esc($s));
    }
    public function line(float $x1, float $y1, float $x2, float $y2, float $w = 0.5): void
    {
        $this->buf .= sprintf("%.2F w %.2F %.2F m %.2F %.2F l S\n", $w, $x1, self::PH - $y1, $x2, self::PH - $y2);
    }
    /** Kode QR sebagai kotak-kotak vektor; $sz = sisi (pt), termasuk zona tenang 4 modul */
    public function qr(SpQr $q, float $x, float $y, float $sz): void
    {
        $mod = $sz / ($q->size + 8); $ops = "0 0 0 rg\n";
        for ($r = 0; $r < $q->size; $r++) for ($c = 0; $c < $q->size; $c++)
            if ($q->m[$r][$c]) $ops .= sprintf("%.3F %.3F %.3F %.3F re\n", $x + ($c + 4) * $mod, self::PH - ($y + ($r + 5) * $mod), $mod + 0.01, $mod + 0.01);
        $this->buf .= $ops . "f\n";
    }
    /** Daftarkan gambar JPEG: ['path'|'data', 'w', 'h', 'smask'? (zlib, abu-abu 8 bit)] → nama XObject */
    public function img(array $im): string
    {
        $key = $im['path'] ?? md5($im['data']);
        if (!isset($this->imgs[$key])) $this->imgs[$key] = $im + ['name' => 'Im' . (count($this->imgs) + 1)];
        return $this->imgs[$key]['name'];
    }
    /** Gambar pada kotak (x, y atas, w, h); bila $clip diisi [x, y, w, h], gambar dipotong ke kotak itu */
    public function drawImage(string $name, float $x, float $y, float $w, float $h, ?array $clip = null): void
    {
        $o = 'q ';
        if ($clip) $o .= sprintf('%.2F %.2F %.2F %.2F re W n ', $clip[0], self::PH - $clip[1] - $clip[3], $clip[2], $clip[3]);
        $this->buf .= $o . sprintf("%.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $x, self::PH - $y - $h, $name);
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
    /** Paragraf satu gaya (rata kiri atau kiri-kanan) */
    public function para(string $s, float $x, float $w, string $f = 'R', float $size = 12, bool $justify = false, float $lh = 1.35): void
    {
        $lines = $this->wrap(self::enc($s), $f, $size, $w);
        $n = count($lines);
        foreach ($lines as $i => $l) {
            $this->ensure($size * $lh);
            $sp = substr_count($l, ' ');
            $tw = $justify && $i < $n - 1 && $sp > 0 ? ($w - $this->width($l, $f, $size)) / $sp : 0;
            $this->y += $size * $lh;
            $this->text($x, $this->y - $size * 0.3, $l, $f, $size, $tw);
        }
    }

    /* ---------- teks berformat ---------- */
    /** runs → kata-kata; tiap kata = potongan bergaya [s, f, size, u, w]; null = ganti baris paksa */
    private function words(array $runs, float $base): array
    {
        $words = []; $cur = [];
        foreach ($runs as $r) {
            if (isset($r['br'])) { if ($cur) { $words[] = $cur; $cur = []; } $words[] = null; continue; }
            $f = self::font((bool)$r['b'], (bool)$r['i']); $size = $r['s'] ?: $base;
            foreach (preg_split('/( )/', $r['x'], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
                if ($part === ' ') { if ($cur) { $words[] = $cur; $cur = []; } continue; }
                $s = self::enc($part);
                $cur[] = ['s' => $s, 'f' => $f, 'z' => $size, 'u' => (bool)$r['u'], 'w' => $this->width($s, $f, $size)];
            }
        }
        if ($cur) $words[] = $cur;
        return $words;
    }
    private static function wordW(array $wd): float { return array_sum(array_column($wd, 'w')); }
    /** Pecah kata yang lebih lebar dari baris menjadi beberapa kata */
    private function splitWord(array $wd, float $max): array
    {
        $out = []; $cur = []; $cw = 0;
        foreach ($wd as $pc) {
            $s = $pc['s'];
            while ($s !== '') {
                $n = strlen($s);
                while ($n > 1 && $cw + $this->width(substr($s, 0, $n), $pc['f'], $pc['z']) > $max) $n--;
                if ($n === 1 && $cw > 0 && $cw + $this->width($s[0], $pc['f'], $pc['z']) > $max) { $out[] = $cur; $cur = []; $cw = 0; continue; }
                $part = substr($s, 0, $n); $s = substr($s, $n);
                $w = $this->width($part, $pc['f'], $pc['z']);
                $cur[] = ['s' => $part, 'w' => $w] + $pc; $cw += $w;
                if ($s !== '') { $out[] = $cur; $cur = []; $cw = 0; }
            }
        }
        if ($cur) $out[] = $cur;
        return $out;
    }
    /** Paragraf berformat. $al: l|c|r|j; $mark (tanda butir) digambar di $markX pada baris pertama */
    public function rich(array $runs, float $x, float $w, string $al = 'j', float $base = 12, ?string $mark = null, float $markX = 0, float $lh = 1.35): void
    {
        $words = $this->words($runs, $base);
        if (!$words) { $this->ensure($base * $lh); $this->y += $base * $lh; if ($mark !== null) $this->text($markX, $this->y - $base * 0.3, self::enc($mark), 'R', $base); return; }
        // susun baris
        $lines = []; $cur = []; $cw = 0;
        $push = function (bool $forced) use (&$lines, &$cur, &$cw) { $lines[] = ['w' => $cur, 'forced' => $forced]; $cur = []; $cw = 0; };
        foreach ($words as $wd) {
            if ($wd === null) { $push(true); continue; }
            $ww = self::wordW($wd);
            $parts = $ww > $w ? $this->splitWord($wd, $w) : [$wd];
            foreach ($parts as $pw) {
                $ww = self::wordW($pw);
                $sp = $cur ? $this->width(' ', end($cur)[count(end($cur)) - 1]['f'], end($cur)[count(end($cur)) - 1]['z']) : 0;
                if ($cur && $cw + $sp + $ww > $w + 0.01) $push(false);
                $sp = $cur ? $sp : 0;
                $cur[] = $pw; $cw += $sp + $ww;
            }
        }
        if ($cur || !$lines) $push(true);
        $n = count($lines);
        foreach ($lines as $li => $ln) {
            $maxZ = $base;
            foreach ($ln['w'] as $wd) foreach ($wd as $pc) $maxZ = max($maxZ, $pc['z']);
            $this->ensure($maxZ * $lh);
            $this->y += $maxZ * $lh;
            $bl = $this->y - $maxZ * 0.3;
            if ($li === 0 && $mark !== null) $this->text($markX, $bl, self::enc($mark), 'R', $base);
            $nat = 0; $gaps = max(0, count($ln['w']) - 1); $spW = [];
            foreach ($ln['w'] as $i => $wd) {
                $nat += self::wordW($wd);
                if ($i < $gaps) { $lp = $wd[count($wd) - 1]; $spW[$i] = $this->width(' ', $lp['f'], $lp['z']); $nat += $spW[$i]; }
            }
            $extra = 0; $xx = $x;
            if ($al === 'j' && $li < $n - 1 && !$ln['forced'] && $gaps > 0) $extra = ($w - $nat) / $gaps;
            elseif ($al === 'c') $xx = $x + ($w - $nat) / 2;
            elseif ($al === 'r') $xx = $x + $w - $nat;
            foreach ($ln['w'] as $i => $wd) {
                foreach ($wd as $pc) {
                    $this->text($xx, $bl, $pc['s'], $pc['f'], $pc['z']);
                    if ($pc['u']) $this->line($xx, $bl + 1.5, $xx + $pc['w'], $bl + 1.5, 0.5);
                    $xx += $pc['w'];
                }
                if ($i < $gaps) $xx += $spW[$i] + $extra;
            }
        }
    }
    /** Teks & fon sel tabel: tebal bila kepala atau seluruh isinya tebal */
    public static function sel(array $c): array
    {
        $plain = array_values(array_filter($c['r'], fn($r) => !isset($r['br'])));
        $b = $c['h'] || ($plain && !array_filter($plain, fn($r) => !$r['b']));
        return [self::enc(bl_rt_teks($c['r'])), $b ? 'B' : 'R'];
    }
    /** Lebar kolom (pt): minimum = kata terpanjang, ideal = teks satu baris; sisa ruang dibagi proporsional */
    public float $lastSize = 10;
    public function colWidths(array $rows, float $w, float $size = 10): array
    {
        $pad = 3; $nc = max(array_map('count', $rows));
        $min = array_fill(0, $nc, 12.0); $pref = array_fill(0, $nc, 12.0);
        foreach ($rows as $row) foreach ($row as $ci => $c) {
            [$t, $f] = self::sel($c);
            foreach (preg_split("/\n/", $t) as $ln) {
                $pref[$ci] = max($pref[$ci], $this->width($ln, $f, $size) + 2 * $pad + 1);
                foreach (explode(' ', $ln) as $wd) $min[$ci] = max($min[$ci], min($w / $nc * 2, $this->width($wd, $f, $size) + 2 * $pad + 1));
            }
        }
        $sp = array_sum($pref); $sm = array_sum($min);
        if ($sm > $w && $size > 7.5) return $this->colWidths($rows, $w, max(7.5, $size - 0.5));   // tabel lebar: huruf diperkecil
        $this->lastSize = $size;
        if ($sp <= $w) return array_map(fn($p) => $p + ($w - $sp) * $p / $sp, $pref);
        if ($sm <= $w) { $d = $sp - $sm; return array_map(fn($a, $p) => $a + ($w - $sm) * ($d > 0 ? ($p - $a) / $d : 1 / count($min)), $min, $pref); }
        return array_map(fn($a) => $a * $w / $sm, $min);
    }
    /** Tabel dari blok bl_rt: baris pertama bertanda kepala (th) diberi latar abu-abu dan diulang di halaman berikutnya */
    public function tbl(array $rows, float $x, float $w, float $size = 10): void
    {
        $pad = 3; $lh = $size * 1.25;
        $cell = [self::class, 'sel'];
        $cw = $this->colWidths($rows, $w, $size);
        $size = $this->lastSize; $lh = $size * 1.25;
        $head = $rows && !array_filter($rows[0], fn($c) => !$c['h']);
        $render = function (array $row, bool $isHead) use ($cw, $x, $size, $pad, $lh, $cell) {
            $cells = []; $h = 0;
            foreach ($row as $ci => $c) {
                [$t, $f] = $cell($c); $ls = [];
                foreach (preg_split("/\n/", $t) as $ln) array_push($ls, ...$this->wrap($ln, $f, $size, $cw[$ci] - 2 * $pad));
                $cells[$ci] = [$ls, $f, $c['al']]; $h = max($h, count($ls) * $lh + 2 * $pad);
            }
            return [$cells, $h];
        };
        $draw = function (array $cells, float $h, bool $isHead) use ($cw, $x, $size, $pad, $lh) {
            $top = $this->y; $xx = $x;
            if ($isHead) $this->buf .= sprintf("0.92 g %.2F %.2F %.2F %.2F re f 0 g\n", $x, self::PH - $top - $h, array_sum($cw), $h);
            foreach ($cw as $ci => $cwi) {
                [$ls, $f, $al] = $cells[$ci];
                foreach ($ls as $li => $l) {
                    $tx = $xx + $pad;
                    if ($al === 'c') $tx = $xx + ($cwi - $this->width($l, $f, $size)) / 2;
                    elseif ($al === 'r') $tx = $xx + $cwi - $pad - $this->width($l, $f, $size);
                    $this->text($tx, $top + $pad + ($li + 1) * $lh - $size * 0.25, $l, $f, $size);
                }
                $this->buf .= sprintf("0.5 w %.2F %.2F %.2F %.2F re S\n", $xx, self::PH - $top - $h, $cwi, $h);
                $xx += $cwi;
            }
            $this->y = $top + $h;
        };
        $hd = $head ? $render($rows[0], true) : null;
        foreach ($rows as $ri => $row) {
            $isHead = $head && $ri === 0;
            [$cells, $h] = $isHead ? $hd : $render($row, false);
            $need = $isHead && isset($rows[1]) ? $h + $render($rows[1], false)[1] : $h;   // kepala tidak tertinggal sendirian
            if ($this->y + min($need, $this->bottom() - $this->mt) > $this->bottom()) {
                $this->addPage();
                if ($head && !$isHead) $draw($hd[0], $hd[1], true);
            }
            $draw($cells, $h, $isHead);
        }
    }

    /** Tulis PDF ke aliran $fh (gambar dibaca dari berkas saat ditulis) */
    public function output($fh): void
    {
        $this->pages[] = $this->buf; $this->buf = '';
        $np = count($this->pages);
        $fontStart = 3;
        $off = []; $pos = 0;
        $w = function (string $s) use ($fh, &$pos) { fwrite($fh, $s); $pos += strlen($s); };
        $obj = function (int $i, string $body, ?string $stream = null, ?string $path = null) use ($w, &$off, &$pos) {
            $off[$i] = $pos;
            if ($path !== null) {
                $w("$i 0 obj\n$body\nstream\n");
                $in = fopen($path, 'rb');
                while (!feof($in)) { $chunk = fread($in, 1 << 16); if ($chunk === false) break; $w($chunk); }
                fclose($in);
                $w("\nendstream\nendobj\n");
            } elseif ($stream !== null) $w("$i 0 obj\n$body\nstream\n$stream\nendstream\nendobj\n");
            else $w("$i 0 obj\n$body\nendobj\n");
        };
        $w("%PDF-1.4\n%\xE2\xE3\xCF\xD3\n");
        $obj(1, '<< /Type /Catalog /Pages 2 0 R >>');
        $i = $fontStart;
        foreach (self::FONTS as $name) $obj($i++, "<< /Type /Font /Subtype /Type1 /BaseFont /$name /Encoding /WinAnsiEncoding >>");
        $xo = [];
        foreach ($this->imgs as $im) {
            $sm = '';
            if (!empty($im['smask'])) { $obj($i, '<< /Type /XObject /Subtype /Image /Width ' . $im['w'] . ' /Height ' . $im['h'] . ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length ' . strlen($im['smask']) . ' >>', $im['smask']); $sm = " /SMask $i 0 R"; $i++; }
            $len = isset($im['path']) ? (int)filesize($im['path']) : strlen($im['data']);
            $obj($i, '<< /Type /XObject /Subtype /Image /Width ' . $im['w'] . ' /Height ' . $im['h'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode' . $sm . ' /Length ' . $len . ' >>', $im['data'] ?? null, $im['path'] ?? null);
            $xo[] = '/' . $im['name'] . " $i 0 R"; $i++;
        }
        $fr = []; $j = $fontStart; foreach (self::FN as $fn) $fr[] = "/$fn " . ($j++) . ' 0 R';
        $res = '<< /Font << ' . implode(' ', $fr) . ' >>' . ($xo ? ' /XObject << ' . implode(' ', $xo) . ' >>' : '') . ' >>';
        $kids = [];
        foreach ($this->pages as $pi => $content) {
            $pn = $pi + 1;
            if ($this->stamp !== '') {
                $st = self::enc($this->stamp); $sz = 46; $wd = $this->width($st, 'B', $sz);
                $cos = cos(deg2rad(35)); $sin = sin(deg2rad(35));
                $cx = self::PW / 2 - $wd / 2 * $cos; $cy = self::PH / 2 - $wd / 2 * $sin;
                $cap = sprintf("BT 0.82 0.82 0.82 rg /F2 %d Tf %.4F %.4F %.4F %.4F %.2F %.2F Tm (%s) Tj ET\n", $sz, $cos, $sin, -$sin, $cos, $cx, $cy, self::esc($st));
                $content = in_array($pn, $this->capDiAtas, true) ? $content . $cap : $cap . $content;
            }
            if ($this->footer !== '' && !in_array($pn, $this->tanpaFooter, true)) {
                $ft = self::enc($this->footer);
                $content .= sprintf("BT 0.45 0.45 0.45 rg /F3 7.5 Tf %.2F %.2F Td (%s) Tj ET\n", self::PW - $this->mr - $this->width($ft, 'I', 7.5), 30, self::esc($ft));
                $pg = self::enc(t('lap.halaman', ['n' => $pn, 'm' => $np]));
                $content .= sprintf("BT 0.45 0.45 0.45 rg /F1 7.5 Tf %.2F %.2F Td (%s) Tj ET\n", $this->ml, 30, self::esc($pg));
            }
            $c = $i++; $p = $i++;
            $obj($c, '<< /Length ' . strlen($content) . ' >>', $content);
            $obj($p, "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . self::PW . ' ' . self::PH . "] /Resources $res /Contents $c 0 R >>");
            $kids[] = "$p 0 R";
        }
        $obj(2, '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>');
        $x = $pos;
        $max = $i - 1;
        $w("xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n");
        for ($k = 1; $k <= $max; $k++) $w(isset($off[$k]) ? sprintf("%010d 00000 n \n", $off[$k]) : "0000000000 65535 f \n");
        $w("trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n");
    }
}

/* ================= tata letak laporan ================= */
const BL_SAMPUL_W = 1400, BL_SAMPUL_H = 2000, BL_SAMPUL_FOTO = [747, 1717];   // templat sampul (px) & batas area foto

/** Ukuran huruf terbesar (≤ $z0, ≥ $min) agar teks muat dalam $maxLines baris */
function bl_lap_pas(BlPdf $p, string $s, string $f, float $z0, float $min, float $w, int $maxLines): array
{
    $s = BlPdf::enc($s);
    for ($z = $z0; $z >= $min; $z -= 0.5) { $ls = $p->wrap($s, $f, $z, $w); if (count($ls) <= $maxLines) return [$z, $ls]; }
    return [$min, $p->wrap($s, $f, $min, $w)];
}
/** Posisi teks sampul (pt): [x, garis dasar baris 1, lebar, ukuran awal, ukuran min, jarak baris (× ukuran), baris maks] */
function bl_lap_sampul_tata(): array
{
    $sx = BlPdf::PW / BL_SAMPUL_W; $sy = BlPdf::PH / BL_SAMPUL_H;
    return [
        'judulLaporan' => [115 * $sx, 442 * $sy, 1175 * $sx, 24.5, 14, 1.38, 2],
        'judulKegiatan' => [115 * $sx, 662 * $sy, 1040 * $sx, 15, 10, 1.4, 2],
        'penyusun' => [707 * $sx, 1866 * $sy, 670 * $sx, 13, 9, 1.38, 3],
        'label' => [707 * $sx, 1822 * $sy],
    ];
}
function bl_lap_penyusun_baris(array $s): array
{
    $out = [];
    if (trim((string)$s['nama']) !== '') $out[] = trim((string)$s['nama']);
    if (trim((string)$s['jabatan']) !== '') $out[] = '(' . trim((string)$s['jabatan']) . ')';
    return $out;
}
function bl_lap_pdf_sampul(BlPdf $p, array $s): void
{
    $W = BlPdf::PW; $H = BlPdf::PH; $sy = $H / BL_SAMPUL_H;
    $foto = !empty($s['foto']) ? bl_lap_path($s['foto']) : '';
    if ($foto !== '' && is_file($foto) && ($gi = @getimagesize($foto))) {
        $ry = BL_SAMPUL_FOTO[0] * $sy; $rh = (BL_SAMPUL_FOTO[1] - BL_SAMPUL_FOTO[0]) * $sy;
        $sc = max($W / $gi[0], $rh / $gi[1]); $iw = $gi[0] * $sc; $ih = $gi[1] * $sc;
        $p->drawImage($p->img(['path' => $foto, 'w' => $gi[0], 'h' => $gi[1]]), ($W - $iw) / 2, $ry + ($rh - $ih) / 2, $iw, $ih, [0, $ry, $W, $rh]);
    }
    $p->drawImage($p->img(['path' => __DIR__ . '/sampul.jpg', 'w' => BL_SAMPUL_W, 'h' => BL_SAMPUL_H, 'smask' => (string)file_get_contents(__DIR__ . '/sampul_alpha.z')]), 0, 0, $W, $H);
    $T = bl_lap_sampul_tata();
    foreach (['judulLaporan' => 'HB', 'judulKegiatan' => 'HB'] as $key => $f) {
        $v = trim((string)($s[$key] ?? ''));
        if ($v === '') continue;
        [$x, $y, $w, $z0, $min, $lh, $max] = $T[$key];
        [$z, $ls] = bl_lap_pas($p, $v, $f, $z0, $min, $w, $max);
        foreach ($ls as $i => $l) $p->text($x, $y + $i * $z * $lh, $l, $f, $z, 0, '1 1 1');
    }
    $pen = bl_lap_penyusun_baris($s);
    if ($pen) {
        [$x, $y, $w, $z0, $min, $lh, $max] = $T['penyusun'];
        for ($z = $z0; $z >= $min; $z -= 0.5) { $ls = []; foreach ($pen as $l) array_push($ls, ...$p->wrap(BlPdf::enc($l), 'H', $z, $w)); if (count($ls) <= $max) break; }
        foreach ($ls as $i => $l) $p->text($x, $y + $i * $z * $lh, $l, 'H', $z, 0, '1 1 1');
    }
}
/** Daftar entri daftar isi (urutan tetap): [tingkat, label, kunci halaman|null] */
function bl_lap_entri_isi(array $L): array
{
    $e = [];
    foreach ($L['bab'] as $i => $b) {
        $e[] = [1, $b['judul'], "b$i"];
        foreach ($b['sub'] as $j => $s) $e[] = [2, ($j + 1) . '. ' . $s['judul'], "b$i.$j"];
    }
    if ($L['lampiran']) {
        $e[] = [1, mb_strtoupper(t('lap.lampiran')), 'lamp'];
        foreach ($L['lampiran'] as $j => $l) $e[] = [3, mb_strtoupper(t('lap.lampiran')) . ' ' . bl_lap_romawi($j + 1) . '. ' . $l['judul'], null];
    }
    return $e;
}
/** Tanda tangan basah + QR VeriDoc (Lembar Pengesahan) */
function bl_lap_pdf_ttd(BlPdf $p, array $L, float $x, float $w): void
{
    $fs = 12; $t = $L['ttd'];
    $p->y += 30;
    $lx = $x; $rx = $x + $w * 0.55;
    $p->text($lx, $p->y, BlPdf::enc(trim($t['tempat'] . ', ' . $t['tanggal'], ', ')), 'R', $fs);
    $p->text($rx, $p->y, BlPdf::enc(t('lap.mengetahui')), 'R', $fs);
    $p->y += $fs * 1.35;
    $p->text($lx, $p->y, BlPdf::enc(t('lap.ttd_fasil')), 'R', $fs);
    $p->text($rx, $p->y, BlPdf::enc(t('lap.ttd_pembina')), 'R', $fs);
    $p->y += 72;
    $nf = BlPdf::enc($t['fasil']); $np = BlPdf::enc($t['pembina'] !== '' ? $t['pembina'] : str_repeat('.', 40));
    $p->text($lx, $p->y, $nf, 'B', $fs); $p->line($lx, $p->y + 2, $lx + $p->width($nf, 'B', $fs), $p->y + 2, 0.5);
    $p->text($rx, $p->y, $np, 'B', $fs); if ($t['pembina'] !== '') $p->line($rx, $p->y + 2, $rx + $p->width($np, 'B', $fs), $p->y + 2, 0.5);
    if (!empty($L['dok'])) {
        $qs = 84;
        $p->y += 36;
        $p->qr(SpQr::encode(bl_dok_qr_url($L['dok'])), $x, $p->y, $qs);
        $y0 = $p->y; $p->y += $qs / 2 - 14;
        $p->para(bl_dok_qr_teks($L['dok']), $x + $qs + 8, $w - $qs - 8, 'R', 9.5);
        $p->y = max($p->y, $y0 + $qs);
    }
}
/** Blok teks berformat; $x/$w = area isi */
function bl_lap_pdf_blok(BlPdf $p, array $blocks, float $x, float $w): void
{
    foreach (bl_rt_marks($blocks) as $b) {
        if ($b['t'] === 'p') { $ind = $b['ind'] * 28.35; $p->rich($b['r'], $x + $ind, $w - $ind, $b['al']); $p->y += 5; }
        elseif ($b['t'] === 'li') { $l = $x + $b['lv'] * 20; $p->rich($b['r'], $l + 20, $w - $b['lv'] * 20 - 20, $b['al'], 12, $b['m'], $l); $p->y += 2; }
        else { $p->y += 3; $p->tbl($b['rows'], $x, $w); $p->y += 8; }
    }
}
/** Satu lintasan tata letak; $hal = nomor halaman tiap entri (lintasan kedua) */
function bl_lap_pdf_susun(array $L, array $hal): array
{
    $p = new BlPdf();
    $p->footer = t('app.hak_cipta');
    if (!empty($L['draf'])) { $p->stamp = t('dok.draf_cap'); $p->capDiAtas = [1]; }
    $x = $p->ml; $w = BlPdf::PW - $p->ml - $p->mr; $fs = 12;
    $catat = [];
    // 1. sampul
    $p->addPage(); $p->tanpaFooter = [1];
    bl_lap_pdf_sampul($p, $L['sampul']);
    // 2. Lembar Pengesahan
    $p->addPage();
    if (!empty($L['dok'])) { $sf = BlPdf::enc(bl_dok_label_sifat($L['dok'])); $p->text($x + $w - $p->width($sf, 'B', 10), $p->y - 20, $sf, 'B', 10); }
    $tt = BlPdf::enc(t('lap.pengesahan'));
    $p->y += 30; $p->text($x + ($w - $p->width($tt, 'B', 14)) / 2, $p->y, $tt, 'B', 14);
    $p->y += 24;
    $kv = [];
    if (!empty($L['dok'])) $kv[] = [t('dok.nomor'), $L['dok']['nomor']];
    $kv[] = [t('lap.judul_laporan'), trim($L['sampul']['judulLaporan']) ?: '–'];
    $kv[] = [t('lap.judul_kegiatan'), trim($L['sampul']['judulKegiatan']) ?: '–'];
    $kv[] = [t('lap.disusun_oleh'), implode(' ', bl_lap_penyusun_baris($L['sampul'])) ?: '–'];
    foreach ($kv as [$lab, $val]) {
        $ls = $p->wrap(BlPdf::enc($val), 'R', $fs, $w - 125);
        foreach ($ls as $i => $l) {
            $p->y += $fs * 1.4;
            if ($i === 0) { $p->text($x, $p->y - 3, BlPdf::enc($lab), 'B', $fs); $p->text($x + 112, $p->y - 3, ':', 'R', $fs); }
            $p->text($x + 125, $p->y - 3, $l, 'R', $fs);
        }
    }
    bl_lap_pdf_ttd($p, $L, $x, $w);
    // 3. Daftar Isi
    $p->addPage();
    $tt = BlPdf::enc(mb_strtoupper(t('lap.daftar_isi')));
    $p->y += 4; $p->text($x + ($w - $p->width($tt, 'B', 14)) / 2, $p->y, $tt, 'B', 14);
    $p->y += 26; $hh = BlPdf::enc(t('lap.hal')); $p->text($x + $w - $p->width($hh, 'R', $fs), $p->y, $hh, 'R', $fs);
    $p->y += 6;
    $dot = $p->width('.', 'R', $fs);
    foreach (bl_lap_entri_isi($L) as [$lv, $label, $key]) {
        $ix = $lv === 1 ? 0 : ($lv === 2 ? 18 : 18);
        $num = $key !== null ? (string)($hal[$key] ?? '') : '';
        $ls = $p->wrap(BlPdf::enc(($lv === 3 ? '• ' : '') . $label), 'R', $fs, $w - $ix - 40);
        foreach ($ls as $i => $l) {
            $p->ensure($fs * 1.5);
            $p->y += $fs * 1.5;
            $p->text($x + $ix + ($i && $lv === 3 ? 9 : 0), $p->y - 3, $l, 'R', $fs);
            if ($i === count($ls) - 1 && $key !== null) {
                $nw = $p->width($num, 'R', $fs);
                $from = $x + $ix + $p->width($l, 'R', $fs) + 4; $to = $x + $w - max($nw, 14) - 4;
                if ($to > $from) $p->text($from, $p->y - 3, str_repeat('.', (int)floor(($to - $from) / $dot)), 'R', $fs);
                $p->text($x + $w - $nw, $p->y - 3, $num, 'R', $fs);
            }
        }
    }
    // 4. Isi bab
    $p->addPage();
    foreach ($L['bab'] as $i => $b) {
        $p->ensure(60);
        if ($i > 0) $p->y += 10;
        $p->y += $fs * 1.5; $catat["b$i"] = $p->page();
        $p->text($x, $p->y - 3, BlPdf::enc($b['judul']), 'B', $fs);
        $p->y += 4;
        $bb = bl_rt_parse($b['html']);
        if ($bb) bl_lap_pdf_blok($p, $bb, $x + 18, $w - 18);
        elseif (!$b['sub']) $p->para(t('lap.belum_diisi'), $x + 18, $w - 18);
        foreach ($b['sub'] as $j => $s) {
            $p->ensure(50);
            $p->y += 4 + $fs * 1.45; $catat["b$i.$j"] = $p->page();
            $p->text($x + 18, $p->y - 3, ($j + 1) . '.', 'B', $fs);
            $sl = $p->wrap(BlPdf::enc($s['judul']), 'B', $fs, $w - 36);
            foreach ($sl as $k => $l) { if ($k) $p->y += $fs * 1.35; $p->text($x + 36, $p->y - 3, $l, 'B', $fs); }
            $p->y += 3;
            $sb = bl_rt_parse($s['html']);
            if ($sb) bl_lap_pdf_blok($p, $sb, $x + 36, $w - 36);
            else $p->para(t('lap.belum_diisi'), $x + 36, $w - 36);
        }
    }
    // 5. Lampiran
    if ($L['lampiran']) {
        foreach ($L['lampiran'] as $j => $lp) {
            $p->addPage();
            if ($j === 0) {
                $catat['lamp'] = $p->page();
                $tt = BlPdf::enc(mb_strtoupper(t('lap.lampiran')));
                $p->y += 4; $p->text($x + ($w - $p->width($tt, 'B', 14)) / 2, $p->y, $tt, 'B', 14); $p->y += 10;
            }
            $p->para(mb_strtoupper(t('lap.lampiran')) . ' ' . bl_lap_romawi($j + 1) . '. ' . $lp['judul'], $x, $w, 'B', $fs);
            $p->y += 8;
            foreach ($lp['files'] as $f) foreach ($f['hal'] as $h) {
                $path = bl_lap_path($h['f']);
                if (!is_file($path)) continue;
                $full = $p->bottom() - $p->mt;
                $avail = $p->bottom() - $p->y;
                $sc = min($w / $h['w'], $avail / $h['h']);
                if ($h['h'] * $sc < $full * 0.45 && $sc < $w / $h['w']) { $p->addPage(); $avail = $full; $sc = min($w / $h['w'], $avail / $h['h']); }
                $iw = $h['w'] * $sc; $ih = $h['h'] * $sc;
                $p->drawImage($p->img(['path' => $path, 'w' => $h['w'], 'h' => $h['h']]), $x + ($w - $iw) / 2, $p->y, $iw, $ih);
                $p->y += $ih + 10;
            }
        }
    }
    return [$p, $catat];
}
/** PDF laporan; bila $fh diberikan, ditulis ke aliran itu (hemat memori), selain itu dikembalikan sebagai string */
function bl_lap_pdf(array $L, $fh = null): ?string
{
    [, $hal] = bl_lap_pdf_susun($L, []);
    [$p] = bl_lap_pdf_susun($L, $hal);
    if ($fh) { $p->output($fh); return null; }
    $m = fopen('php://temp/maxmemory:16777216', 'w+b');
    $p->output($m);
    rewind($m);
    $s = (string)stream_get_contents($m);
    fclose($m);
    return $s;
}
/** Nomor halaman (PDF) tiap entri daftar isi – dipakai sebagai isi awal daftar isi Word */
function bl_lap_halaman(array $L): array { return bl_lap_pdf_susun($L, [])[1]; }
