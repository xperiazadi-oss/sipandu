<?php
/* Menyajikan berkas pembelajaran dari folder data di luar web root, dengan pemeriksaan hak akses:
   j=surat  (surat tugas: Admin & akun Puskesmas pengirim), j=materi (staf kegiatan & peserta yang sudah menyelesaikan pretest),
   j=profil (foto narasumber: semua akun yang masuk) */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/domain.php';
sp_session_start();
$u = current_user();
$j = (string)($_GET['j'] ?? ''); $id = (int)($_GET['id'] ?? 0);
$path = ''; $type = 'application/pdf'; $name = 'berkas.pdf';
if ($u && !$u['must_change']) {
    if ($j === 'surat') {
        $p = q1('SELECT * FROM bl_peserta WHERE id = ?', [$id]);
        if ($p && $p['surat'] && ($u['role'] === 'admin' || ($u['role'] === 'binaan' && (int)$u['puskesmas_id'] === (int)$p['puskesmas_id']) || ($u['role'] === 'peserta' && (int)$u['id'] === (int)$p['user_id']))) {
            $path = data_dir('surat') . '/' . basename($p['surat']); $name = 'surat-tugas-' . $p['id'] . '.pdf';
        }
    } elseif ($j === 'materi') {
        $m = q1('SELECT * FROM bl_materi WHERE id = ?', [$id]);
        $k = $m ? bl_keg((int)$m['keg_id']) : null;
        $ok = false;
        if ($k && bl_is_staff($u, $k)) $ok = true;
        elseif ($k && ($me = bl_me_peserta($u, (int)$k['id']))) $ok = (bool)qval("SELECT COUNT(*) FROM bl_selesai f JOIN bl_seg s ON s.id = f.seg_id WHERE f.peserta_id = ? AND s.keg_id = ? AND s.jenis = 'pretest'", [$me['id'], $k['id']]);
        if ($ok) { $path = data_dir('materi') . '/' . basename($m['file']); $name = preg_replace('/[^A-Za-z0-9 ._-]/', '', $m['judul']) . '.pdf'; }
    } elseif ($j === 'profil') {
        $f = (string)qval('SELECT foto FROM bl_profil WHERE user_id = ?', [$id]);
        if ($f !== '') { $path = data_dir('profil') . '/' . basename($f); $type = 'image/jpeg'; $name = 'profil.jpg'; }
    }
}
if ($path === '' || !is_file($path)) { http_response_code(404); exit; }
header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
if ($type === 'application/pdf') header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
readfile($path);
