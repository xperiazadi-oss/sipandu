<?php
/* Menyajikan gambar kop dari folder data di luar web root, dengan pemeriksaan hak akses */
// Tanpa strict_types: nilai dari basis data selalu berupa string dan dikonversi otomatis oleh PHP.
require __DIR__ . '/app/bootstrap.php';
if (sp_app() === 'bl') { http_response_code(404); exit; }   // khusus Si PANDU; subdomain pembelajaran memakai akun terpisah
sp_session_start();
$u = current_user();
$key = (string)($_GET['k'] ?? '');
$allowed = $u && !$u['must_change'] && (
    ($u['role'] === 'binaan' && $key === 'pkm:' . $u['puskesmas_id']) ||
    ($u['role'] === 'pembina' && ($key === 'pembina' || str_starts_with($key, 'pkm:'))) ||
    $u['role'] === 'admin'
);
$k = $allowed ? q1('SELECT * FROM kops WHERE owner_key = ?', [$key]) : null;
$path = $k ? data_dir('kop') . '/' . basename($k['file']) : '';
if (!$k || !is_file($path)) { http_response_code(404); exit; }
header('Content-Type: image/png');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($path);
