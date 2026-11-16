# Si PANDU Balikpapan

Sistem Informasi Pembinaan dan Pengendalian Mutu Labkesmas Tingkat I Kota Balikpapan — *“Terarah Pembinaannya, Terjaga Mutunya.”*

**Tahap 1** · PHP 8.1+ · MariaDB/MySQL (SQLite untuk uji otomatis).

## Struktur
| Lokasi | Isi |
|---|---|
| `index.php` | Halaman masuk, ganti kata sandi wajib, kerangka aplikasi |
| `api.php` | API JSON (POST + token CSRF + sesi) |
| `install.php` | Pemasangan awal (butuh `install_token`, terkunci setelah selesai) |
| `selftest.php` | Uji mandiri server (butuh `selftest_token`) |
| `kop.php` | Menyajikan kop dari folder data di luar web root |
| `app/` | Inti, skema, logika bisnis, **mesin Westgard PHP**, `teks.php` (satu-satunya file teks tampilan) |
| `assets/` | CSS dan JavaScript antarmuka (mesin Westgard JS identik) |
| `tests/` | Uji kesetaraan Excel, skenario paper, uji pembanding JS↔PHP, uji integrasi |

Folder `app/`, `tests/`, dan `.git/` diblokir dari peramban oleh `.htaccess`.

## Konfigurasi (di luar repo dan di luar web root)
`/home/<akun>/sipandu-config.php`:
```php
<?php return [
  'db' => ['dsn' => 'mysql:host=localhost;dbname=...;charset=utf8mb4', 'user' => '...', 'pass' => '...'],
  'data_dir' => '/home/<akun>/sipandu-data',
  'install_token' => '...', 'selftest_token' => '...',
  'base_url' => 'https://sipandubalikpapan.my.id',
];
```

## Uji
```
php tests/run_engine.php          # 7.428 pemeriksaan setara Excel + 23 skenario paper
node tests/diff_gen.js && php tests/diff_check.php   # 3.000 lembar acak: mesin JS = mesin PHP
php tests/check_teks.php          # semua kunci teks ada di app/teks.php
node tests/e2e_server.js          # uji integrasi 3 peran (server PHP lokal + Chrome)
```
