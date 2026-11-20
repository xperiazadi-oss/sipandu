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
| `berkas.php` | Menyajikan surat tugas, materi PDF, dan foto narasumber (Peningkatan Kompetensi SDM) dengan pemeriksaan hak akses |
| `.user.ini` | Batas unggah PHP di hosting (materi PDF 10 MB) |
| `foto.php` | Menyajikan foto personel (Sumber Daya Manusia) dengan pemeriksaan hak akses |
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
php tests/migrate_test.php        # migrasi skema v1 → terbaru tanpa kehilangan data
node tests/e2e_server.js          # uji integrasi 3 peran (server PHP lokal + Chrome)
node tests/pw_test.js             # ikon mata kata sandi (mouse, sentuh, papan ketik)
node tests/bl_test.js             # Peningkatan Kompetensi SDM (jalankan setelah e2e_server.js)
```
