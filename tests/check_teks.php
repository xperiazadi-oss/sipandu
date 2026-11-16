<?php
/* Memastikan setiap kunci teks yang dipakai kode ada di app/teks.php (satu file teks) */
$T = require __DIR__ . '/../app/teks.php';
$has = function (string $k) use ($T) { $n = $T; foreach (explode('.', $k) as $p) { if (!is_array($n) || !array_key_exists($p, $n)) return false; $n = $n[$p]; } return true; };
$keys = [];
foreach (['assets/app.js', 'assets/client.js'] as $f) { preg_match_all("/\\bt\\('([a-z_A-Z0-9.]+)'/", file_get_contents(__DIR__ . "/../$f"), $m); foreach ($m[1] as $k) $keys[$k][] = $f; }
foreach (array_merge(glob(__DIR__ . '/../*.php'), glob(__DIR__ . '/../app/*.php')) as $f) {
    $s = file_get_contents($f);
    preg_match_all("/\\bt\\('([a-z_A-Z0-9.]+)'/", $s, $m); foreach ($m[1] as $k) $keys[$k][] = basename($f);
    preg_match_all("/'(galat\\.[a-z_]+)'/", $s, $m); foreach ($m[1] as $k) $keys[$k][] = basename($f);
}
$miss = [];
foreach ($keys as $k => $f) { if (substr($k, -1) === '.') continue; if (!$has($k)) $miss[] = "$k (" . implode(',', array_unique($f)) . ")"; }
// kunci dinamis
$dyn = ['menu.' => ['beranda','risiko','qc','dokkm','pme','sa','pustaka','kop','arsip','notif','pantau','kasus','tinjau','lpm','ub','up','kl','ttd','pengguna','ref_pkm','ref_param','ref_alat','aturan','pustaka_kelola','tampilan','operasional'],
  'menu.grup.' => ['pmi','lainnya','pembinaan','pengesahan','akses','referensi','operasional'], 'status.' => ['DITERIMA','PERINGATAN','TERTOLAK','BELUM_LENGKAP','BELUM_DIISI','DATA_ACUAN_INVALID'],
  'penyebab.' => ['Acak','Sistematik','Sistematik_Spontan','Acak_Sistematik','Belum_spesifik'], 'kasus.tahap.' => ['Terbuka','Investigasi','Koreksi','Verifikasi','Ditutup','Dibatalkan'],
  'kasus.jenis.' => ['Tertolak','Peringatan'], 'kasus.mode.' => ['mandiri','konsultasi'], 'kasus.tab.' => ['buka','kons','tutup','semua'], 'peran.' => ['admin','pembina','binaan'],
  'aspek.' => ['alat','bahan','lingkungan','personel'], 'temuan.' => ['alat','bahan','lingkungan','personel'], 'risiko.butir.' => ['alat','bahan','lingkungan','personel']];
foreach ($dyn as $p => $list) foreach ($list as $x) if (!$has($p . $x)) $miss[] = "$p$x (dinamis)";
echo "kunci dipakai: " . count($keys) . ", hilang: " . count($miss) . "\n" . implode("\n", $miss) . "\n";
// teks Indonesia literal di JS (heuristik): string berisi spasi + huruf kecil di luar t()
$lit = 0;
foreach (['assets/app.js', 'assets/client.js'] as $f) {
  $s = preg_replace("/\\bt\\('[^']*'/", '', file_get_contents(__DIR__ . "/../$f"));
  if (preg_match_all("/'([A-Za-z][a-z]+ [a-z][^'<>=;{}()]{3,})'/", $s, $m)) foreach ($m[1] as $x) { if ($x === 'use strict') continue; $lit++; echo "literal? $f: $x\n"; }
}
exit(count($miss) || $lit ? 1 : 0);
