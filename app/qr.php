<?php
/* Pembuat kode QR (ISO/IEC 18004) tanpa pustaka luar: mode byte, koreksi galat M, versi 1–10, pemilihan masker
   berdasarkan skor penalti. Diadaptasi dari algoritme pustaka "QR Code generator" Project Nayuki (MIT). */

final class SpQr
{
    private const ECC_PER_BLOCK_M = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26];
    private const BLOCKS_M = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5];
    public int $size = 0;
    /** @var bool[][] modul gelap [y][x] */
    public array $m = [];
    private array $fn = [];

    /** Matriks QR untuk teks (UTF-8) */
    public static function encode(string $text): self
    {
        $data = array_values(unpack('C*', $text) ?: []);
        for ($ver = 1; $ver <= 10; $ver++) {
            $cap = self::dataCodewords($ver) * 8;
            $need = 4 + ($ver <= 9 ? 8 : 16) + 8 * count($data);
            if ($need <= $cap) break;
        }
        if ($ver > 10) throw new RuntimeException('Teks QR terlalu panjang');
        $bits = [];
        $put = function (int $val, int $len) use (&$bits) { for ($i = $len - 1; $i >= 0; $i--) $bits[] = ($val >> $i) & 1; };
        $put(4, 4); $put(count($data), $ver <= 9 ? 8 : 16);
        foreach ($data as $b) $put($b, 8);
        $cap = self::dataCodewords($ver) * 8;
        $put(0, min(4, $cap - count($bits)));
        $put(0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $cap; $pad ^= 0xEC ^ 0x11) $put($pad, 8);
        $cw = [];
        foreach (array_chunk($bits, 8) as $ch) { $v = 0; foreach ($ch as $bit) $v = ($v << 1) | $bit; $cw[] = $v; }
        $q = new self();
        $q->build($ver, self::addEcc($ver, $cw));
        return $q;
    }

    private static function rawModules(int $ver): int
    {
        $r = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) { $na = intdiv($ver, 7) + 2; $r -= (25 * $na - 10) * $na - 55; if ($ver >= 7) $r -= 36; }
        return $r;
    }
    private static function dataCodewords(int $ver): int
    {
        return intdiv(self::rawModules($ver), 8) - self::ECC_PER_BLOCK_M[$ver] * self::BLOCKS_M[$ver];
    }
    private static function gmul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) { $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF; $z ^= (($y >> $i) & 1) * $x; }
        return $z;
    }
    private static function rsDivisor(int $deg): array
    {
        $r = array_fill(0, $deg, 0); $r[$deg - 1] = 1; $root = 1;
        for ($i = 0; $i < $deg; $i++) {
            for ($j = 0; $j < $deg; $j++) { $r[$j] = self::gmul($r[$j], $root); if ($j + 1 < $deg) $r[$j] ^= $r[$j + 1]; }
            $root = self::gmul($root, 2);
        }
        return $r;
    }
    private static function rsRemainder(array $data, array $div): array
    {
        $r = array_fill(0, count($div), 0);
        foreach ($data as $b) {
            $f = $b ^ array_shift($r); $r[] = 0;
            foreach ($div as $i => $d) $r[$i] ^= self::gmul($d, $f);
        }
        return $r;
    }
    private static function addEcc(int $ver, array $data): array
    {
        $nb = self::BLOCKS_M[$ver]; $el = self::ECC_PER_BLOCK_M[$ver];
        $raw = intdiv(self::rawModules($ver), 8);
        $nShort = $nb - $raw % $nb; $shortLen = intdiv($raw, $nb);
        $div = self::rsDivisor($el); $blocks = []; $k = 0;
        for ($i = 0; $i < $nb; $i++) {
            $len = $shortLen - $el + ($i < $nShort ? 0 : 1);
            $dat = array_slice($data, $k, $len); $k += $len;
            $ecc = self::rsRemainder($dat, $div);
            if ($i < $nShort) $dat[] = 0;
            $blocks[] = array_merge($dat, $ecc);
        }
        $out = [];
        for ($i = 0, $n = count($blocks[0]); $i < $n; $i++)
            foreach ($blocks as $j => $b) if ($i !== $shortLen - $el || $j >= $nShort) $out[] = $b[$i];
        return $out;
    }

    private function set(int $x, int $y, bool $dark): void { $this->m[$y][$x] = $dark; $this->fn[$y][$x] = true; }
    private function build(int $ver, array $cw): void
    {
        $n = $this->size = $ver * 4 + 17;
        $this->m = array_fill(0, $n, array_fill(0, $n, false));
        $this->fn = array_fill(0, $n, array_fill(0, $n, false));
        for ($i = 0; $i < $n; $i++) { $this->set(6, $i, $i % 2 === 0); $this->set($i, 6, $i % 2 === 0); }
        foreach ([[3, 3], [$n - 4, 3], [3, $n - 4]] as [$cx, $cy])
            for ($dy = -4; $dy <= 4; $dy++) for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx; $y = $cy + $dy; $d = max(abs($dx), abs($dy));
                if ($x >= 0 && $x < $n && $y >= 0 && $y < $n) $this->set($x, $y, $d !== 2 && $d !== 4);
            }
        $al = $this->alignPos($ver); $na = count($al);
        for ($i = 0; $i < $na; $i++) for ($j = 0; $j < $na; $j++) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $na - 1) || ($i === $na - 1 && $j === 0)) continue;
            for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) $this->set($al[$i] + $dx, $al[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
        }
        $this->formatBits(0);
        if ($ver >= 7) {
            $rem = $ver; for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            $bits = ($ver << 12) | $rem;
            for ($i = 0; $i < 18; $i++) { $b = (($bits >> $i) & 1) === 1; $a = $n - 11 + $i % 3; $c = intdiv($i, 3); $this->set($a, $c, $b); $this->set($c, $a, $b); }
        }
        // isi kodewords zig-zag
        $i = 0; $total = count($cw) * 8;
        for ($right = $n - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5;
            for ($v = 0; $v < $n; $v++) for ($j = 0; $j < 2; $j++) {
                $x = $right - $j; $up = (($right + 1) & 2) === 0; $y = $up ? $n - 1 - $v : $v;
                if (!$this->fn[$y][$x] && $i < $total) { $this->m[$y][$x] = (($cw[$i >> 3] >> (7 - ($i & 7))) & 1) === 1; $i++; }
            }
        }
        // masker dengan penalti terkecil
        $best = 0; $min = PHP_INT_MAX; $base = $this->m;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->m = $base; $this->applyMask($mask); $this->formatBits($mask);
            $p = $this->penalty();
            if ($p < $min) { $min = $p; $best = $mask; }
        }
        $this->m = $base; $this->applyMask($best); $this->formatBits($best);
    }
    private function alignPos(int $ver): array
    {
        if ($ver === 1) return [];
        $na = intdiv($ver, 7) + 2;
        $step = (int)(ceil(($ver * 4 + 4) / ($na * 2 - 2)) * 2);
        $r = [6];
        for ($pos = $this->size - 7; count($r) < $na; $pos -= $step) array_splice($r, 1, 0, [$pos]);
        return $r;
    }
    private function formatBits(int $mask): void
    {
        $data = (0 << 3) | $mask;   // koreksi galat M = 00
        $rem = $data; for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $bits = (($data << 10) | $rem) ^ 0x5412; $n = $this->size;
        $g = fn($i) => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) $this->set(8, $i, $g($i));
        $this->set(8, 7, $g(6)); $this->set(8, 8, $g(7)); $this->set(7, 8, $g(8));
        for ($i = 9; $i < 15; $i++) $this->set(14 - $i, 8, $g($i));
        for ($i = 0; $i < 8; $i++) $this->set($n - 1 - $i, 8, $g($i));
        for ($i = 8; $i < 15; $i++) $this->set(8, $n - 15 + $i, $g($i));
        $this->set(8, $n - 8, true);
    }
    private function applyMask(int $k): void
    {
        $n = $this->size;
        for ($y = 0; $y < $n; $y++) for ($x = 0; $x < $n; $x++) {
            if ($this->fn[$y][$x]) continue;
            switch ($k) {
                case 0: $inv = ($x + $y) % 2 === 0; break;
                case 1: $inv = $y % 2 === 0; break;
                case 2: $inv = $x % 3 === 0; break;
                case 3: $inv = ($x + $y) % 3 === 0; break;
                case 4: $inv = (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0; break;
                case 5: $inv = $x * $y % 2 + $x * $y % 3 === 0; break;
                case 6: $inv = ($x * $y % 2 + $x * $y % 3) % 2 === 0; break;
                default: $inv = (($x + $y) % 2 + $x * $y % 3) % 2 === 0;
            }
            if ($inv) $this->m[$y][$x] = !$this->m[$y][$x];
        }
    }
    private function penalty(): int
    {
        $n = $this->size; $p = 0; $dark = 0; $lines = [];
        for ($y = 0; $y < $n; $y++) {
            $row = ''; $col = '';
            for ($x = 0; $x < $n; $x++) { $row .= $this->m[$y][$x] ? '1' : '0'; $col .= $this->m[$x][$y] ? '1' : '0'; if ($this->m[$y][$x]) $dark++; }
            $lines[] = $row; $lines[] = $col;
        }
        foreach ($lines as $l) {
            if (preg_match_all('/(0{5,}|1{5,})/', $l, $mm)) foreach ($mm[0] as $run) $p += 3 + strlen($run) - 5;
            $p += 40 * (substr_count('0000' . $l, '00001011101') + substr_count($l . '0000', '10111010000'));
        }
        for ($y = 0; $y < $n - 1; $y++) for ($x = 0; $x < $n - 1; $x++) {
            $c = $this->m[$y][$x];
            if ($c === $this->m[$y][$x + 1] && $c === $this->m[$y + 1][$x] && $c === $this->m[$y + 1][$x + 1]) $p += 3;
        }
        $total = $n * $n;
        $p += (int)(ceil(abs($dark * 20 - $total * 10) / $total) - 1) * 10;
        return $p;
    }

    /** Gambar PNG (GD) dengan zona tenang 4 modul */
    public function png(int $scale = 8): string
    {
        $q = 4; $dim = ($this->size + 2 * $q) * $scale;
        $im = imagecreate($dim, $dim);
        $white = imagecolorallocate($im, 255, 255, 255); $black = imagecolorallocate($im, 0, 0, 0);
        imagefill($im, 0, 0, $white);
        for ($y = 0; $y < $this->size; $y++) for ($x = 0; $x < $this->size; $x++)
            if ($this->m[$y][$x]) imagefilledrectangle($im, ($x + $q) * $scale, ($y + $q) * $scale, ($x + $q + 1) * $scale - 1, ($y + $q + 1) * $scale - 1, $black);
        ob_start(); imagepng($im); $png = (string)ob_get_clean();
        imagedestroy($im);
        return $png;
    }
}
