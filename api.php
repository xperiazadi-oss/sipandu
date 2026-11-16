<?php
/* Si PANDU – API JSON (Tahap 1). Semua permintaan: POST, sesi masuk, token CSRF. */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/domain.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
sp_send_security_headers();

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

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('galat.metode', [], 405);
    if (!sp_installed()) fail('galat.belum_terpasang', [], 503);
    sp_session_start();
    $isUpload = isset($_POST['a']);
    $d = $isUpload ? $_POST : (json_decode((string)file_get_contents('php://input'), true) ?: []);
    if (!csrf_ok($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($d['csrf'] ?? null))) fail('galat.csrf', [], 403);
    $u = current_user();
    if (!$u) fail('galat.sesi_habis', [], 401);
    $a = (string)($d['a'] ?? '');
    if ($u['must_change'] && $a !== 'password_change') fail('galat.wajib_ganti_sandi', [], 403);
    $role = $u['role'];
    $isB = $role === 'binaan'; $isP = $role === 'pembina'; $isA = $role === 'admin';
    $result = null;

    switch ($a) {
        /* ---------- umum ---------- */
        case 'state':
            break;
        case 'password_change':
            $old = (string)($d['old'] ?? ''); $new = (string)($d['new'] ?? '');
            need(password_verify($old, $u['pass_hash']), 'galat.sandi_lama_salah', 400);
            need(valid_new_password($new), 'galat.sandi_lemah', 400);
            need(!hash_equals($old, $new), 'galat.sandi_sama', 400);
            q('UPDATE users SET pass_hash = ?, must_change = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            audit('password_change');
            $result = ['ok' => true];
            break;
        case 'notif_read':
            $id = int_in($d, 'id');
            $n = q1('SELECT * FROM notifs WHERE id = ?', [$id]);
            need($n && $n['to_key'] === notif_key_for($u));
            if (!qval('SELECT COUNT(*) FROM notif_reads WHERE notif_id = ? AND user_id = ?', [$id, $u['id']])) sp_insert('notif_reads', ['notif_id' => $id, 'user_id' => $u['id']]);
            break;
        case 'notif_read_all':
            foreach (qall('SELECT id FROM notifs WHERE to_key = ?', [notif_key_for($u)]) as $n)
                if (!qval('SELECT COUNT(*) FROM notif_reads WHERE notif_id = ? AND user_id = ?', [$n['id'], $u['id']])) sp_insert('notif_reads', ['notif_id' => $n['id'], 'user_id' => $u['id']]);
            break;

        /* ---------- lembar QC ---------- */
        case 'sheet_get':
            $sh = sheet_row(int_in($d, 'id'));
            need($sh && can_view_pkm($u, (int)$sh['puskesmas_id']), 'galat.lembar_tidak_ada', 404);
            $result = ['sheet' => out_sheet($sh), 'runs' => array_map('out_run', runs_of((int)$sh['id'])),
                'comments' => array_map(fn($c) => ['by' => $c['nama'], 'role' => $c['role'], 'at' => $c['at'], 'text' => $c['body']], qall("SELECT * FROM comments WHERE target = 'sheet' AND target_id = ? ORDER BY id", [$sh['id']]))];
            break;
        case 'sheet_create':
            need($isB);
            $p = q1('SELECT * FROM params WHERE id = ?', [int_in($d, 'paramId')]);
            $al = q1('SELECT * FROM alat WHERE id = ? AND puskesmas_id = ? AND aktif = 1', [int_in($d, 'alatId'), $u['puskesmas_id']]);
            need($p && $al, 'galat.data_tidak_valid', 400);
            need($p['kategori'] === $al['kategori'], 'galat.kategori_tidak_sesuai', 400);
            need(!qval("SELECT COUNT(*) FROM sheets WHERE puskesmas_id = ? AND param_id = ? AND alat_id = ? AND status = 'aktif'", [$u['puskesmas_id'], $p['id'], $al['id']]), 'galat.lembar_sudah_ada', 400);
            $names = $p['kategori'] === 'Kimia' ? [t('level.kimia1'), t('level.kimia2')] : [t('level.hema1'), t('level.hema2'), t('level.hema3')];
            $lv = (string)($d['lv'] ?? 'all');
            $nAct = $lv === 'all' ? count($names) : max(1, min((int)$lv, count($names)));
            $levels = [];
            foreach ($names as $i => $nm) $levels[] = ['name' => $nm, 'control' => '', 'lot' => '', 'edPabrik' => '', 'edBuka' => '', 'mean' => null, 'sd' => null, 'active' => $i < $nAct];
            $id = sp_insert('sheets', ['puskesmas_id' => $u['puskesmas_id'], 'param_id' => $p['id'], 'alat_id' => $al['id'], 'kategori' => $p['kategori'], 'max_levels' => count($names), 'levels' => jenc($levels), 'satuan' => $p['satuan'], 'status' => 'aktif', 'created_at' => today_str(), 'reagen_events' => '[]']);
            audit('sheet_create', ['id' => $id]);
            $result = ['id' => $id];
            break;
        case 'sheet_level':
        case 'sheet_meta':
        case 'level_active':
        case 'run_add':
        case 'run_value':
        case 'run_excl':
        case 'run_reason':
        case 'reagen_lot':
        case 'new_lot':
            need($isB);
            $sid = int_in($d, 'sheetId');
            $sh = sheet_row($sid);
            need($sh && (int)$sh['puskesmas_id'] === (int)$u['puskesmas_id'], 'galat.lembar_tidak_ada', 404);
            need($sh['status'] === 'aktif', 'galat.lembar_ditutup', 400);
            $levels = jdec($sh['levels']);
            $hasRuns = (int)qval('SELECT COUNT(*) FROM runs WHERE sheet_id = ?', [$sid]) > 0;
            if ($a === 'sheet_level') {
                $i = int_in($d, 'i'); $k = (string)($d['k'] ?? '');
                need(isset($levels[$i]) && in_array($k, ['control', 'lot', 'edPabrik', 'edBuka', 'mean', 'sd'], true), 'galat.data_tidak_valid', 400);
                if ($k === 'mean' || $k === 'sd') {
                    $raw = $d['v'] ?? null;
                    $v = num_or_null($raw);
                    need(!($raw !== null && $raw !== '' && $v === null), 'galat.harus_angka', 400);
                    $levels[$i][$k] = $v;
                } else $levels[$i][$k] = str_in($d, 'v', 120);
                q('UPDATE sheets SET levels = ? WHERE id = ?', [jenc($levels), $sid]);
                if ($k === 'mean' || $k === 'sd') sheet_sync($sid);
            } elseif ($a === 'sheet_meta') {
                $map = ['method' => 'metode', 'reagen' => 'reagen', 'reagenLot' => 'reagen_lot', 'unit' => 'satuan'];
                $k = (string)($d['k'] ?? '');
                need(isset($map[$k]), 'galat.data_tidak_valid', 400);
                q("UPDATE sheets SET {$map[$k]} = ? WHERE id = ?", [str_in($d, 'v', 120), $sid]);
            } elseif ($a === 'level_active') {
                need(!$hasRuns, 'galat.level_terkunci', 400);
                $i = int_in($d, 'i');
                need(isset($levels[$i]), 'galat.data_tidak_valid', 400);
                $levels[$i]['active'] = (bool)($d['v'] ?? false);
                $any = false; foreach ($levels as $l) if (($l['active'] ?? true) !== false) $any = true;
                need($any, 'galat.minimal_satu_level', 400);
                q('UPDATE sheets SET levels = ? WHERE id = ?', [jenc($levels), $sid]);
            } elseif ($a === 'run_add') {
                $date = (string)($d['date'] ?? '');
                need((bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false, 'galat.tanggal', 400);
                need($date <= today_str(), 'galat.tanggal_depan', 400);
                $vals = []; $any = false;
                foreach ($levels as $i => $l) {
                    if (($l['active'] ?? true) === false) { $vals[] = null; continue; }
                    $raw = $d['values'][$i] ?? null;
                    $v = num_or_null($raw);
                    need(!($raw !== null && $raw !== '' && $v === null), 'galat.harus_angka', 400);
                    if ($v !== null) $any = true;
                    $vals[] = $v;
                }
                need($any, 'galat.isi_hasil', 400);
                $seq = (int)qval('SELECT COUNT(*) FROM runs WHERE sheet_id = ? AND tgl = ?', [$sid, $date]);
                $rid = sp_insert('runs', ['sheet_id' => $sid, 'tgl' => $date, 'seq' => $seq, 'vals' => jenc($vals), 'excluded' => 0, 'reason' => '', 'note' => str_in($d, 'note', 300), 'by_user' => $u['id'], 'by_name' => $u['nama'], 'created_at' => now_str()]);
                $ev = sheet_sync($sid);
                foreach ($ev as $e) if ((int)$e['run']['id'] === $rid) $result = ['runId' => $rid, 'status' => $e['status']];
                audit('run_add', ['sheet' => $sid, 'run' => $rid]);
            } else {
                if ($a === 'reagen_lot') {
                    $lot = str_in($d, 'lot', 60); $date = (string)($d['date'] ?? today_str());
                    need($lot !== '' && (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 'galat.isi_lot', 400);
                    $evs = jdec($sh['reagen_events']); $evs[] = ['date' => $date, 'lot' => $lot];
                    q('UPDATE sheets SET reagen_events = ?, reagen_lot = ? WHERE id = ?', [jenc($evs), $lot, $sid]);
                } elseif ($a === 'new_lot') {
                    q("UPDATE sheets SET status = 'ditutup', closed_at = ?, close_reason = ? WHERE id = ?", [today_str(), t('lembar.alasan_tutup_lot'), $sid]);
                    foreach ($levels as &$l) { $l['lot'] = ''; $l['mean'] = null; $l['sd'] = null; $l['edPabrik'] = ''; $l['edBuka'] = ''; }
                    unset($l);
                    $nid = sp_insert('sheets', ['puskesmas_id' => $sh['puskesmas_id'], 'param_id' => $sh['param_id'], 'alat_id' => $sh['alat_id'], 'kategori' => $sh['kategori'], 'max_levels' => $sh['max_levels'], 'levels' => jenc($levels), 'metode' => $sh['metode'], 'reagen' => $sh['reagen'], 'reagen_lot' => $sh['reagen_lot'], 'satuan' => $sh['satuan'], 'status' => 'aktif', 'created_at' => today_str(), 'prev_id' => $sid, 'reagen_events' => '[]']);
                    notify('pembina', t('notif.ganti_lot', ['pkm' => pkm_name((int)$sh['puskesmas_id']), 'matriks' => matrix_name($sh)]), 'lembar-' . $nid);
                    audit('new_lot', ['old' => $sid, 'new' => $nid]);
                    $result = ['id' => $nid];
                } else {
                    $run = q1('SELECT * FROM runs WHERE id = ? AND sheet_id = ?', [int_in($d, 'runId'), $sid]);
                    need((bool)$run, 'galat.data_tidak_valid', 400);
                    if ($a === 'run_value') {
                        $i = int_in($d, 'i');
                        need(isset($levels[$i]) && ($levels[$i]['active'] ?? true) !== false, 'galat.data_tidak_valid', 400);
                        $raw = $d['v'] ?? null; $v = num_or_null($raw);
                        need(!($raw !== null && $raw !== '' && $v === null), 'galat.harus_angka', 400);
                        $vals = jdec($run['vals']); $vals[$i] = $v;
                        q('UPDATE runs SET vals = ? WHERE id = ?', [jenc($vals), $run['id']]);
                        sheet_sync($sid);
                        audit('run_value', ['run' => $run['id'], 'i' => $i]);
                    } elseif ($a === 'run_excl') {
                        q('UPDATE runs SET excluded = ? WHERE id = ?', [!empty($d['v']) ? 1 : 0, $run['id']]);
                        audit('run_excl', ['run' => $run['id'], 'v' => !empty($d['v'])]);
                    } else {
                        q('UPDATE runs SET reason = ? WHERE id = ?', [str_in($d, 'v', 300), $run['id']]);
                    }
                }
            }
            break;
        case 'sheet_comment':
            need($isB || $isP);
            $sh = sheet_row(int_in($d, 'sheetId'));
            need($sh && can_view_pkm($u, (int)$sh['puskesmas_id']), 'galat.lembar_tidak_ada', 404);
            $txt = str_in($d, 'text', 2000);
            need($txt !== '', 'galat.isi_pesan', 400);
            sp_insert('comments', ['target' => 'sheet', 'target_id' => $sh['id'], 'user_id' => $u['id'], 'role' => $role, 'nama' => $u['nama'], 'at' => now_str(), 'body' => $txt]);
            if ($isP) notify('pkm:' . $sh['puskesmas_id'], t('notif.komentar_pembina', ['matriks' => matrix_name($sh), 'kutip' => mb_substr($txt, 0, 60)]), 'lembar-' . $sh['id']);
            else notify('pembina', t('notif.tanggapan_binaan', ['pkm' => pkm_name((int)$sh['puskesmas_id']), 'matriks' => matrix_name($sh)]), 'lembar-' . $sh['id']);
            break;

        /* ---------- kasus ---------- */
        case 'case_comments':
            $c = q1('SELECT * FROM cases WHERE id = ?', [int_in($d, 'id')]);
            need($c && can_view_pkm($u, (int)$c['puskesmas_id']), 'galat.kasus_tidak_ada', 404);
            $result = array_map(fn($x) => ['by' => $x['nama'], 'role' => $x['role'], 'at' => $x['at'], 'text' => $x['body']], qall("SELECT * FROM comments WHERE target = 'case' AND target_id = ? ORDER BY id", [$c['id']]));
            break;
        case 'case_comment':
            $c = q1('SELECT * FROM cases WHERE id = ?', [int_in($d, 'id')]);
            need($c && can_view_pkm($u, (int)$c['puskesmas_id']), 'galat.kasus_tidak_ada', 404);
            $txt = str_in($d, 'text', 2000);
            need($txt !== '', 'galat.isi_pesan', 400);
            sp_insert('comments', ['target' => 'case', 'target_id' => $c['id'], 'user_id' => $u['id'], 'role' => $role, 'nama' => $u['nama'], 'at' => now_str(), 'body' => $txt]);
            if ($isP) notify('pkm:' . $c['puskesmas_id'], t('notif.saran_pembina', ['id' => $c['id'], 'kutip' => mb_substr($txt, 0, 60)]), 'kasus-' . $c['id']);
            else notify('pembina', t('notif.pesan_binaan', ['pkm' => pkm_name((int)$c['puskesmas_id']), 'id' => $c['id']]), 'kasus-' . $c['id']);
            break;
        case 'case_mode':
        case 'case_close_warn':
        case 'case_findings':
        case 'case_corrections':
        case 'case_close':
            need($isB);
            $c = q1('SELECT * FROM cases WHERE id = ?', [int_in($d, 'id')]);
            need($c && (int)$c['puskesmas_id'] === (int)$u['puskesmas_id'], 'galat.kasus_tidak_ada', 404);
            need(!in_array($c['state'], ['Ditutup', 'Dibatalkan'], true), 'galat.kasus_selesai', 400);
            $teks = sp_teks();
            if ($a === 'case_mode') {
                need($c['mode'] === null, 'galat.data_tidak_valid', 400);
                $mode = ($d['v'] ?? '') === 'konsultasi' ? 'konsultasi' : 'mandiri';
                q("UPDATE cases SET mode = ?, state = 'Investigasi' WHERE id = ?", [$mode, $c['id']]);
                case_log_add($c, t($mode === 'mandiri' ? 'kasus.log_mandiri' : 'kasus.log_konsultasi'), $u['nama']);
            } elseif ($a === 'case_close_warn') {
                need($c['type'] === 'Peringatan', 'galat.data_tidak_valid', 400);
                q("UPDATE cases SET mode = 'mandiri', state = 'Ditutup', verify_date = run_date WHERE id = ?", [$c['id']]);
                case_log_add($c, t('kasus.log_tutup_peringatan'), $u['nama']);
            } elseif ($a === 'case_findings') {
                need(in_array($c['state'], ['Terbuka', 'Investigasi'], true) && $c['mode'] !== null, 'galat.data_tidak_valid', 400);
                $find = []; $corr = [];
                foreach ((array)($d['keys'] ?? []) as $key) {
                    $p = explode(':', (string)$key);
                    if (count($p) !== 2 || !isset($teks['temuan'][$p[0]][(int)$p[1]])) continue;
                    $item = $teks['temuan'][$p[0]][(int)$p[1]];
                    $find[] = ['key' => $p[0] . ':' . (int)$p[1]];
                    $corr[] = ['text' => $item[1], 'from' => $teks['aspek'][$p[0]] . ' – ' . $item[0], 'done' => false, 'note' => ''];
                }
                $other = str_in($d, 'other', 1000);
                if ($other !== '') { $find[] = ['key' => 'lain', 'note' => $other]; $corr[] = ['text' => t('kasus.tindakan_temuan_lain'), 'from' => $other, 'done' => false, 'note' => '']; }
                need((bool)$find, 'galat.pilih_temuan', 400);
                q("UPDATE cases SET findings = ?, corrections = ?, state = 'Koreksi' WHERE id = ?", [jenc($find), jenc($corr), $c['id']]);
                case_log_add($c, t('kasus.log_temuan', ['n' => count($find)]), $u['nama']);
            } elseif ($a === 'case_corrections') {
                need($c['state'] === 'Koreksi', 'galat.data_tidak_valid', 400);
                $corr = jdec($c['corrections']);
                foreach ((array)($d['items'] ?? []) as $i => $it) if (isset($corr[$i])) { $corr[$i]['done'] = !empty($it['done']); $corr[$i]['note'] = mb_substr(trim((string)($it['note'] ?? '')), 0, 300); }
                $add = str_in($d, 'add', 300);
                if ($add !== '') $corr[] = ['text' => $add, 'from' => t('kasus.tambahan_binaan'), 'done' => false, 'note' => ''];
                $finish = !empty($d['finish']);
                if ($finish) {
                    $any = false; foreach ($corr as $k) if ($k['done']) $any = true;
                    need($any, 'galat.centang_tindakan', 400);
                    q("UPDATE cases SET corrections = ?, state = 'Verifikasi' WHERE id = ?", [jenc($corr), $c['id']]);
                    case_log_add($c, t('kasus.log_koreksi'), $u['nama']);
                } else q('UPDATE cases SET corrections = ? WHERE id = ?', [jenc($corr), $c['id']]);
            } else { // case_close
                need($c['state'] === 'Verifikasi', 'galat.data_tidak_valid', 400);
                $r = q1('SELECT * FROM runs WHERE id = ? AND sheet_id = ?', [int_in($d, 'runId'), $c['sheet_id']]);
                need($r && ($r['tgl'] >= $c['run_date']) && (int)$r['id'] !== (int)$c['run_id'] && (jdec($r['eval'])['status'] ?? '') === 'DITERIMA', 'galat.run_verifikasi', 400);
                q("UPDATE cases SET verify_run_id = ?, verify_date = ?, state = 'Ditutup' WHERE id = ?", [$r['id'], $r['tgl'], $c['id']]);
                case_log_add($c, t('kasus.log_tutup', ['tgl' => $r['tgl']]), $u['nama']);
            }
            break;

        /* ---------- Manajemen Risiko ---------- */
        case 'risk_save':
            need($isB);
            $aspect = (string)($d['aspect'] ?? '');
            need(in_array($aspect, QCEngine::ASPECTS, true), 'galat.data_tidak_valid', 400);
            $alatId = null;
            if ($aspect === 'alat') {
                $al = q1('SELECT * FROM alat WHERE id = ? AND puskesmas_id = ? AND aktif = 1', [int_in($d, 'alatId'), $u['puskesmas_id']]);
                need((bool)$al, 'galat.data_tidak_valid', 400);
                $alatId = (int)$al['id'];
            }
            $items = [];
            foreach ((array)($d['items'] ?? []) as $it) {
                $L = (int)($it['L'] ?? 0); $S = (int)($it['S'] ?? 0);
                $txt = mb_substr(trim((string)($it['text'] ?? '')), 0, 200);
                if ($txt === '' || $L < 1 || $L > 5 || $S < 1 || $S > 5) fail('galat.data_tidak_valid');
                $items[] = ['text' => $txt, 'L' => $L, 'S' => $S, 'control' => mb_substr(trim((string)($it['control'] ?? '')), 0, 300), 'plan' => mb_substr(trim((string)($it['plan'] ?? '')), 0, 300)];
            }
            need((bool)$items, 'galat.data_tidak_valid', 400);
            sp_insert('risks', ['puskesmas_id' => $u['puskesmas_id'], 'aspect' => $aspect, 'alat_id' => $alatId, 'tgl' => today_str(), 'by_name' => $u['nama'], 'items' => jenc($items)]);
            audit('risk_save', ['aspect' => $aspect, 'alat' => $alatId]);
            break;

        /* ---------- Kop dokumen ---------- */
        case 'kop_upload':
            need($isB || $isP);
            $key = $isB ? 'pkm:' . $u['puskesmas_id'] : 'pembina';
            need(isset($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name']), 'galat.unggah', 400);
            need($_FILES['file']['size'] <= 8 * 1024 * 1024, 'galat.unggah_besar', 400);
            $raw = (string)file_get_contents($_FILES['file']['tmp_name']);
            $info = @getimagesizefromstring($raw);
            need($info !== false && in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true), 'galat.bukan_gambar', 400);
            need($info[0] * $info[1] <= 40000000, 'galat.unggah_besar', 400);
            $src = @imagecreatefromstring($raw);
            need($src !== false, 'galat.bukan_gambar', 400);
            [$W, $H] = [2008, 413];
            $dst = imagecreatetruecolor($W, $H);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            $ow = imagesx($src); $oh = imagesy($src);
            $sc = min($W / $ow, $H / $oh);
            $w = (int)round($ow * $sc); $hh = (int)round($oh * $sc);
            imagecopyresampled($dst, $src, (int)(($W - $w) / 2), (int)(($H - $hh) / 2), 0, 0, $w, $hh, $ow, $oh);
            $file = preg_replace('/[^a-z0-9]/', '_', $key) . '.png';
            imagepng($dst, data_dir('kop') . '/' . $file, 6);
            imagedestroy($src); imagedestroy($dst);
            $meta = ['by' => $u['nama'], 'ow' => $ow, 'oh' => $oh, 'fitted' => $ow !== $W || $oh !== $H, 'scale' => $sc];
            if (qval('SELECT COUNT(*) FROM kops WHERE owner_key = ?', [$key])) q('UPDATE kops SET file = ?, info = ?, updated_at = ? WHERE owner_key = ?', [$file, jenc($meta), now_str(), $key]);
            else sp_insert('kops', ['owner_key' => $key, 'file' => $file, 'info' => jenc($meta), 'updated_at' => now_str()]);
            audit('kop_upload', ['key' => $key]);
            break;

        /* ---------- Administrator ---------- */
        case 'user_add':
            need($isA);
            $r = (string)($d['role'] ?? '');
            need(in_array($r, ['pembina', 'binaan'], true), 'galat.data_tidak_valid', 400);
            $uname = strtolower(str_in($d, 'username', 40)); $nama = str_in($d, 'name', 150);
            need((bool)preg_match('/^[a-z0-9._-]{3,40}$/', $uname), 'galat.username_format', 400);
            need($nama !== '', 'galat.isi_nama', 400);
            need(!qval('SELECT COUNT(*) FROM users WHERE username = ?', [$uname]), 'galat.username_dipakai', 400);
            $pkmId = null;
            if ($r === 'binaan') {
                $pkmId = int_in($d, 'pkmId');
                need((bool)qval('SELECT COUNT(*) FROM puskesmas WHERE id = ?', [$pkmId]), 'galat.pilih_pkm', 400);
            }
            $pw = temp_password();
            $id = sp_insert('users', ['role' => $r, 'nama' => $nama, 'username' => $uname, 'pass_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change' => 1, 'puskesmas_id' => $pkmId, 'nip' => str_in($d, 'nip', 40), 'jabatan' => str_in($d, 'jabatan', 120), 'aktif' => 1, 'created_at' => now_str()]);
            audit('user_add', ['id' => $id, 'role' => $r]);
            $result = ['id' => $id, 'username' => $uname, 'password' => $pw];
            break;
        case 'user_update':
            need($isA);
            $t = q1('SELECT * FROM users WHERE id = ?', [int_in($d, 'id')]);
            need((bool)$t, 'galat.data_tidak_valid', 400);
            $k = (string)($d['k'] ?? ''); $v = str_in($d, 'v', 150);
            if ($k === 'username') {
                $v = strtolower($v);
                need((bool)preg_match('/^[a-z0-9._-]{3,40}$/', $v), 'galat.username_format', 400);
                need(!qval('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?', [$v, $t['id']]), 'galat.username_dipakai', 400);
                q('UPDATE users SET username = ? WHERE id = ?', [$v, $t['id']]);
            } elseif (in_array($k, ['nama', 'nip', 'jabatan'], true)) {
                need($k !== 'nama' || $v !== '', 'galat.isi_nama', 400);
                q("UPDATE users SET $k = ? WHERE id = ?", [$v, $t['id']]);
            } elseif ($k === 'pkmId' && $t['role'] === 'binaan') {
                need((bool)qval('SELECT COUNT(*) FROM puskesmas WHERE id = ?', [(int)$v]), 'galat.pilih_pkm', 400);
                q('UPDATE users SET puskesmas_id = ? WHERE id = ?', [(int)$v, $t['id']]);
            } else fail('galat.data_tidak_valid');
            audit('user_update', ['id' => $t['id'], 'k' => $k]);
            break;
        case 'user_active':
            need($isA);
            $t = q1('SELECT * FROM users WHERE id = ?', [int_in($d, 'id')]);
            need($t && $t['role'] !== 'admin', 'galat.data_tidak_valid', 400);
            q('UPDATE users SET aktif = ? WHERE id = ?', [!empty($d['v']) ? 1 : 0, $t['id']]);
            audit('user_active', ['id' => $t['id'], 'v' => !empty($d['v'])]);
            break;
        case 'user_reset_pw':
            need($isA);
            $t = q1('SELECT * FROM users WHERE id = ?', [int_in($d, 'id')]);
            need($t && $t['role'] !== 'admin', 'galat.data_tidak_valid', 400);
            $pw = temp_password();
            q('UPDATE users SET pass_hash = ?, must_change = 1 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $t['id']]);
            audit('user_reset_pw', ['id' => $t['id']]);
            $result = ['username' => $t['username'], 'password' => $pw];
            break;
        case 'pkm_add':
            need($isA);
            $nama = str_in($d, 'name', 150);
            need($nama !== '', 'galat.isi_nama', 400);
            $n = (int)qval('SELECT COUNT(*) FROM puskesmas') + 1;
            do { $kode = sprintf('P%02d', $n++); } while (qval('SELECT COUNT(*) FROM puskesmas WHERE kode = ?', [$kode]));
            $id = sp_insert('puskesmas', ['kode' => $kode, 'nama' => $nama, 'aktif' => 1]);
            audit('pkm_add', ['id' => $id]);
            $result = ['id' => $id];
            break;
        case 'pkm_update':
            need($isA);
            $nama = str_in($d, 'name', 150);
            need($nama !== '', 'galat.isi_nama', 400);
            q('UPDATE puskesmas SET nama = ? WHERE id = ?', [$nama, int_in($d, 'id')]);
            break;
        case 'param_add':
            need($isA);
            $nama = str_in($d, 'name', 120); $kat = (string)($d['cat'] ?? '');
            need($nama !== '' && in_array($kat, ['Kimia', 'Hematologi'], true), 'galat.data_tidak_valid', 400);
            sp_insert('params', ['nama' => $nama, 'kategori' => $kat, 'satuan' => str_in($d, 'unit', 30), 'spec_src' => 'lain', 'level' => 'Desirable', 'tea_ok' => 0]);
            break;
        case 'param_update':
            need($isA);
            $p = q1('SELECT * FROM params WHERE id = ?', [int_in($d, 'id')]);
            need((bool)$p, 'galat.data_tidak_valid', 400);
            $k = (string)($d['k'] ?? '');
            $map = ['name' => 'nama', 'unit' => 'satuan', 'specSrc' => 'spec_src', 'level' => 'level', 'cvi' => 'cvi', 'cvg' => 'cvg', 'bvSrc' => 'bv_src', 'tea' => 'tea', 'teaSrc' => 'tea_src'];
            need(isset($map[$k]), 'galat.data_tidak_valid', 400);
            if (in_array($k, ['cvi', 'cvg', 'tea'], true)) {
                $raw = $d['v'] ?? null; $v = num_or_null($raw);
                need(!($raw !== null && $raw !== '' && ($v === null || $v < 0)), 'galat.harus_angka_positif', 400);
            } elseif ($k === 'specSrc') { $v = ($d['v'] ?? '') === 'bv' ? 'bv' : 'lain'; }
            elseif ($k === 'level') { $v = isset(SPEC_LEVELS[$d['v'] ?? '']) ? $d['v'] : 'Desirable'; }
            else { $v = str_in($d, 'v', 120); need($k !== 'name' || $v !== '', 'galat.isi_nama', 400); }
            $resetOK = in_array($k, ['specSrc', 'level', 'cvi', 'cvg', 'bvSrc', 'tea', 'teaSrc'], true);
            q("UPDATE params SET {$map[$k]} = ?" . ($resetOK ? ', tea_ok = 0' : '') . ' WHERE id = ?', [$v, $p['id']]);
            audit('param_update', ['id' => $p['id'], 'k' => $k]);
            break;
        case 'param_teaok':
            need($isA);
            q('UPDATE params SET tea_ok = ? WHERE id = ?', [!empty($d['v']) ? 1 : 0, int_in($d, 'id')]);
            audit('param_teaok', ['id' => int_in($d, 'id'), 'v' => !empty($d['v'])]);
            break;
        case 'alat_add':
            need($isA);
            $nama = str_in($d, 'name', 150); $kat = (string)($d['cat'] ?? ''); $pkmId = int_in($d, 'pkmId');
            need($nama !== '' && in_array($kat, ['Kimia', 'Hematologi'], true) && qval('SELECT COUNT(*) FROM puskesmas WHERE id = ?', [$pkmId]), 'galat.data_tidak_valid', 400);
            sp_insert('alat', ['puskesmas_id' => $pkmId, 'nama' => $nama, 'merk' => str_in($d, 'merk', 150), 'kategori' => $kat, 'aktif' => 1]);
            break;
        case 'alat_update':
            need($isA);
            $k = (string)($d['k'] ?? '');
            need(in_array($k, ['name', 'merk'], true), 'galat.data_tidak_valid', 400);
            $v = str_in($d, 'v', 150);
            need($k !== 'name' || $v !== '', 'galat.isi_nama', 400);
            q('UPDATE alat SET ' . ($k === 'name' ? 'nama' : 'merk') . ' = ? WHERE id = ?', [$v, int_in($d, 'id')]);
            break;
        case 'alat_active':
            need($isA);
            q('UPDATE alat SET aktif = ? WHERE id = ?', [!empty($d['v']) ? 1 : 0, int_in($d, 'id')]);
            break;
        case 'rule_toggle':
            need($isA);
            $k = (string)($d['k'] ?? '');
            need(isset(QCEngine::RULES[$k]), 'galat.data_tidak_valid', 400);
            $r = rules_enabled(); $r[$k] = !empty($d['v']);
            set_setting('rules', $r);
            foreach (qall("SELECT id FROM sheets WHERE status = 'aktif'") as $s) sheet_sync((int)$s['id']);
            audit('rule_toggle', ['k' => $k, 'v' => $r[$k]]);
            break;
        case 'settings_k':
            need($isA);
            $k = num_or_null($d['v'] ?? null);
            need($k !== null && $k > 0 && $k <= 6, 'galat.k_tidak_valid', 400);
            set_setting('k', $k);
            break;
        case 'settings_tampilan':
            need($isA);
            $color = (string)($d['color'] ?? '');
            need((bool)preg_match('/^#[0-9a-fA-F]{6}$/', $color), 'galat.data_tidak_valid', 400);
            set_setting('tampilan', ['appName' => str_in($d, 'appName', 80), 'longName' => str_in($d, 'longName', 200), 'tagline' => str_in($d, 'tagline', 150), 'color' => $color]);
            audit('settings_tampilan');
            break;
        default:
            fail('galat.aksi_tidak_dikenal');
    }
    echo jenc(['ok' => true, 'data' => $result, 'state' => app_state(current_user_fresh($u['id']))]);
} catch (ApiError $e) {
    http_response_code($e->getCode() ?: 400);
    echo jenc(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('SIPANDU API: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo jenc(['ok' => false, 'error' => t('galat.server')]);
}

function current_user_fresh(int $id): array
{
    return q1('SELECT * FROM users WHERE id = ?', [$id]);
}
