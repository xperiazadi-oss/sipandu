<?php
/* Menyajikan foto personel dari folder data di luar web root, dengan pemeriksaan hak akses */
// Tanpa strict_types: nilai dari basis data selalu berupa string dan dikonversi otomatis oleh PHP.
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/domain.php';
sp_session_start();
$u = current_user();
$f = $u && !$u['must_change'] ? q1('SELECT f.file, p.puskesmas_id FROM personel_foto f JOIN personel p ON p.id = f.personel_id WHERE f.id = ?', [(int)($_GET['f'] ?? 0)]) : null;
$path = $f && sd_can_view($u, (int)$f['puskesmas_id']) ? data_dir('foto') . '/' . basename($f['file']) : '';
if ($path === '' || !is_file($path)) { http_response_code(404); exit; }
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($path);
