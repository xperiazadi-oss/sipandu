<?php
/* Si PANDU – fungsi bantu API (dipakai Si PANDU dan subdomain pembelajaran) */
// Tanpa strict_types: nilai dari basis data selalu berupa string dan dikonversi otomatis oleh PHP.

final class ApiError extends Exception {}
function fail(string $key, array $vars = [], int $code = 400): never
{
    throw new ApiError(t($key, $vars), $code);
}
function need(bool $cond, string $key = 'galat.tidak_berhak', int $code = 403): void
{
    if (!$cond) fail($key, [], $code);
}
function str_in(array $d, string $k, int $max = 500): string
{
    $v = trim((string)($d[$k] ?? ''));
    return mb_substr($v, 0, $max);
}
function int_in(array $d, string $k): int
{
    return (int)($d[$k] ?? 0);
}
/** Tanggal opsional: '' atau YYYY-MM-DD yang valid */
function date_in(array $d, string $k): string
{
    $v = trim((string)($d[$k] ?? ''));
    if ($v === '') return '';
    need((bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)), 'galat.tanggal', 400);
    return $v;
}
/** Gambar unggahan → kanvas tetap (contain, latar putih, tanpa potong/distorsi) */
function fit_image(string $raw, int $W, int $H, bool $fixOrientation)
{
    $src = @imagecreatefromstring($raw);
    need($src !== false, 'galat.bukan_gambar', 400);
    if ($fixOrientation && function_exists('exif_read_data')) {
        $ex = @exif_read_data('data://image/jpeg;base64,' . base64_encode($raw));
        $o = (int)($ex['Orientation'] ?? 1);
        $ang = [3 => 180, 6 => 270, 8 => 90][$o] ?? 0;
        if ($ang) { $r = imagerotate($src, $ang, 0); if ($r) { imagedestroy($src); $src = $r; } }
    }
    $dst = imagecreatetruecolor($W, $H);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    $ow = imagesx($src); $oh = imagesy($src);
    $sc = min($W / $ow, $H / $oh);
    $w = (int)round($ow * $sc); $hh = (int)round($oh * $sc);
    imagecopyresampled($dst, $src, (int)(($W - $w) / 2), (int)(($H - $hh) / 2), 0, 0, $w, $hh, $ow, $oh);
    imagedestroy($src);
    return [$dst, $ow, $oh, $sc];
}
