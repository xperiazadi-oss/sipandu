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
| `berkas.php` | Subdomain pembelajaran: surat tugas, materi PDF, dan foto narasumber dengan pemeriksaan hak akses |
| `.htaccess` | Juga mengatur batas unggah PHP di hosting (materi PDF 10 MB) |
| `foto.php` | Menyajikan foto personel (Sumber Daya Manusia) dengan pemeriksaan hak akses |
| `app/` | Inti, skema, logika bisnis, **mesin Westgard PHP**, `teks.php` (satu-satunya file teks tampilan) |
| `assets/` | CSS dan JavaScript antarmuka (mesin Westgard JS identik) |
| `tests/` | Uji kesetaraan Excel, skenario paper, uji pembanding JS↔PHP, uji integrasi |

Folder `app/`, `tests/`, dan `.git/` diblokir dari peramban oleh `.htaccess`.

## Subdomain pembelajaran (`belajar.*`)
Si PANDU – Peningkatan Kapasitas Kompetensi SDM memakai berkas yang sama: subdomain `belajar.<domain>` diarahkan ke folder yang sama
(document root = `public_html`), dan `sp_app()` memilih aplikasi menurut nama host. Akun (`bl_users`), sesi, dan log (`bl_audit`)
terpisah dari Si PANDU. Logika di `app/belajar.php` dan `app/bl_web.php`; antarmuka `assets/bl-shell.js` + `assets/belajar.js`.
Akun Admin subdomain dibuat lewat `install.php` di subdomain (token pemasangan). Alamat subdomain dapat diatur dengan `'bl_url'` di konfigurasi.
Admin dapat menghapus akun Peserta (seluruh datanya) atau menghapus peserta dari satu kegiatan; alasan wajib (min. 10 karakter),
penghapusan dan catatan `bl_audit` (`bl_hapus_akun` / `bl_hapus_peserta`) berada dalam satu transaksi, tampil di menu Audit trail.
Peserta yang tercatat di kegiatan arsip tidak dapat dihapus.

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
php tests/migrate_v4_test.php     # migrasi v4 → v5: pembelajaran pindah ke subdomain
node tests/e2e_server.js          # uji integrasi 3 peran (server PHP lokal + Chrome)
node tests/pw_test.js             # ikon mata kata sandi (mouse, sentuh, papan ketik)
node tests/bl_test.js             # subdomain pembelajaran (server kedua dengan SIPANDU_APP=bl; setelah e2e_server.js)
```

## PIN personel (sejak 1.6.0)
Isian Lembar kerja QC dan Kasus oleh akun Laboratorium Binaan wajib memakai sesi petugas: personel aktif memasukkan PIN 6 angka
(diatur akun lab di detail personel). Nama personel dicatat pada run, log kasus, komentar, dan audit. Sesi berakhir 30 menit tanpa
aktivitas atau saat PIN diganti; 5x PIN salah mengunci personel 15 menit. Isian lain (risiko, SDM, alat, BHP, kop) tanpa PIN.
