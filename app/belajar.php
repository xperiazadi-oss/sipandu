<?php
/* Si PANDU – Peningkatan Kompetensi SDM (pembelajaran): kegiatan, segmen, peserta, soal, penilaian, rekap. */
// Tanpa strict_types: nilai dari basis data selalu berupa string dan dikonversi otomatis oleh PHP.

const BL_JENIS = ['pretest', 'materi', 'praktik', 'posttest', 'penutup'];
const BL_NSOAL = 25;
const BL_NEVAL = 10;
/** Bobot Peserta Terbaik (kesepakatan): posttest 30, praktik 30, sikap 25, progres 15 */
const BL_BOBOT = ['post' => 30, 'praktik' => 30, 'sikap' => 25, 'progres' => 15];
const BL_SURAT_MAX = 1048576;      // 1 MB
const BL_MATERI_MAX = 10485760;    // 10 MB

/* ---------- baris & hak akses ---------- */
function bl_keg(int $id): ?array { return q1('SELECT * FROM bl_keg WHERE id = ?', [$id]); }
function bl_seg_row(int $id): ?array { return q1('SELECT * FROM bl_seg WHERE id = ?', [$id]); }
function bl_segs(int $kegId): array { return qall('SELECT * FROM bl_seg WHERE keg_id = ? ORDER BY urut, id', [$kegId]); }
function bl_ids($json): array { return array_values(array_map('intval', (array)jdec($json))); }

/** Staf kegiatan: Admin, semua Pembina, Fasilitator yang ditugaskan */
function bl_is_staff(array $u, array $k): bool
{
    if ($u['role'] === 'admin' || $u['role'] === 'pembina') return true;
    return $u['role'] === 'fasil' && in_array((int)$u['id'], bl_ids($k['fasil']), true);
}
/** Penyusun isi (soal, materi, aspek & nilai): Pembina dan Fasilitator yang ditugaskan; tidak untuk kegiatan diarsipkan */
function bl_can_content(array $u, array $k): bool
{
    return $k['status'] !== 'arsip' && $u['role'] !== 'admin' && bl_is_staff($u, $k);
}
/** Pembuka segmen: Admin, atau Pembina/Fasilitator yang dipilih Admin */
function bl_can_open(array $u, array $k): bool
{
    if ($k['status'] === 'arsip') return false;
    if ($u['role'] === 'admin') return true;
    return in_array($u['role'], ['pembina', 'fasil'], true) && in_array((int)$u['id'], bl_ids($k['pembuka']), true) && bl_is_staff($u, $k);
}
/** Baris peserta (diterima) milik sub-akun ini */
function bl_me_peserta(array $u, int $kegId): ?array
{
    if ($u['role'] !== 'peserta') return null;
    return q1("SELECT * FROM bl_peserta WHERE keg_id = ? AND user_id = ? AND status = 'diterima'", [$kegId, $u['id']]);
}
function bl_can_view(array $u, array $k): bool
{
    if (bl_is_staff($u, $k)) return true;
    if ($u['role'] === 'binaan') return $k['status'] === 'terbit' || ($k['status'] === 'arsip' && qval('SELECT COUNT(*) FROM bl_peserta WHERE keg_id = ? AND puskesmas_id = ?', [$k['id'], $u['puskesmas_id']]));
    if ($u['role'] === 'peserta') return $k['status'] === 'terbit' || ($k['status'] === 'arsip' && (bool)bl_my_reg($u, (int)$k['id']));
    return false;
}

/* ---------- keluaran ---------- */
function bl_out_keg(array $k): array
{
    return ['id' => (int)$k['id'], 'title' => $k['judul'], 'org' => (string)$k['penyelenggara'], 'place' => (string)$k['lokasi'], 'start' => (string)$k['tgl_mulai'], 'end' => (string)$k['tgl_selesai'],
        'desc' => (string)$k['deskripsi'], 'status' => $k['status'], 'open' => (bool)$k['daftar_buka'], 'fasil' => bl_ids($k['fasil']), 'pembuka' => bl_ids($k['pembuka'])];
}
function bl_out_seg(array $s): array
{
    return ['id' => (int)$s['id'], 'urut' => (int)$s['urut'], 'jenis' => $s['jenis'], 'title' => (string)$s['judul'], 'picId' => $s['pic_id'] ? (int)$s['pic_id'] : null, 'picName' => bl_pic_name($s),
        'picText' => (string)$s['pic_nama'], 'date' => (string)$s['tanggal'], 'from' => (string)$s['jam_mulai'], 'to' => (string)$s['jam_selesai'], 'sesi' => jdec($s['sesi']), 'status' => $s['status'],
        'openedAt' => $s['dibuka_at'], 'closedAt' => $s['ditutup_at']];
}
function bl_pic_name(array $s): string
{
    if ($s['pic_id']) { $n = qval('SELECT nama FROM users WHERE id = ?', [$s['pic_id']]); if ($n) return (string)$n; }
    return (string)$s['pic_nama'];
}
function bl_out_peserta(array $p, bool $withUser = true): array
{
    $o = ['id' => (int)$p['id'], 'kegId' => (int)$p['keg_id'], 'pkmId' => (int)$p['puskesmas_id'], 'personelId' => (int)$p['personel_id'], 'name' => $p['nama'], 'jabatan' => (string)$p['jabatan'],
        'nip' => (string)$p['nip'], 'hasSurat' => (bool)$p['surat'], 'status' => $p['status'], 'msg' => (string)$p['pesan'], 'sentAt' => $p['kirim_at'], 'verAt' => $p['verif_at'], 'verBy' => (string)$p['verif_by']];
    if ($withUser) $o['username'] = $p['user_id'] ? (string)qval('SELECT username FROM users WHERE id = ?', [$p['user_id']]) : null;
    return $o;
}
function bl_profil(int $userId): array
{
    $u = q1('SELECT id, nama, role FROM users WHERE id = ?', [$userId]);
    $p = q1('SELECT * FROM bl_profil WHERE user_id = ?', [$userId]) ?: [];
    return ['id' => $userId, 'name' => $u ? $u['nama'] : '?', 'role' => $u ? $u['role'] : '', 'instansi' => (string)($p['instansi'] ?? ''), 'jabatan' => (string)($p['jabatan'] ?? ''),
        'ringkasan' => (string)($p['ringkasan'] ?? ''), 'foto' => !empty($p['foto']), 'v' => substr(sha1((string)($p['updated_at'] ?? '')), 0, 6)];
}
/** Narasumber kegiatan: Fasilitator yang ditugaskan + PIC (akun) segmen materi/praktik */
function bl_narsum_ids(array $k): array
{
    $ids = bl_ids($k['fasil']);
    foreach (qall("SELECT DISTINCT pic_id FROM bl_seg WHERE keg_id = ? AND jenis IN ('materi','praktik') AND pic_id IS NOT NULL", [$k['id']]) as $r) $ids[] = (int)$r['pic_id'];
    $ids = array_values(array_unique(array_filter($ids)));
    sort($ids);
    return $ids;
}

/* ---------- urutan segmen ---------- */
/** Pretest pertama; materi pertama tepat setelah pretest; praktik hanya setelah materi; posttest lalu penutup di akhir */
function bl_order_ok(array $jenis): bool
{
    $n = count($jenis);
    if ($n < 4 || $jenis[0] !== 'pretest' || $jenis[1] !== 'materi' || $jenis[$n - 2] !== 'posttest' || $jenis[$n - 1] !== 'penutup') return false;
    for ($i = 1; $i < $n - 2; $i++) if (!in_array($jenis[$i], ['materi', 'praktik'], true)) return false;
    return true;
}
function bl_renumber(int $kegId): void
{
    foreach (bl_segs($kegId) as $i => $s) q('UPDATE bl_seg SET urut = ? WHERE id = ?', [$i + 1, $s['id']]);
}

/* ---------- soal & urutan acak posttest ---------- */
function bl_soal(int $kegId): array { return qall('SELECT * FROM bl_soal WHERE keg_id = ? ORDER BY urut, id', [$kegId]); }
/** Urutan soal posttest per peserta: acak tetapi tetap sama setiap kali dibuka */
function bl_order_for(array $soal, array $seg, int $pesertaId): array
{
    if ($seg['jenis'] !== 'posttest') return $soal;
    usort($soal, fn($a, $b) => strcmp(sha1($pesertaId . ':' . $seg['id'] . ':' . $a['id']), sha1($pesertaId . ':' . $seg['id'] . ':' . $b['id'])));
    return $soal;
}

/* ---------- nilai & peringkat ---------- */
function bl_rank(array $vals): array
{
    // peringkat kompetisi (1, 2, 2, 4); nilai kosong tidak diberi peringkat
    $r = [];
    foreach ($vals as $id => $v) {
        if ($v === null) { $r[$id] = null; continue; }
        $better = 0; foreach ($vals as $w) if ($w !== null && $w > $v + 1e-9) $better++;
        $r[$id] = $better + 1;
    }
    return $r;
}
function bl_round($v): ?float { return $v === null ? null : round((float)$v, 1); }

/** Rekap nilai semua peserta yang diterima pada satu kegiatan */
function bl_scores(array $k): array
{
    $kid = (int)$k['id'];
    $segs = bl_segs($kid);
    $peserta = qall("SELECT * FROM bl_peserta WHERE keg_id = ? AND status = 'diterima' ORDER BY nama", [$kid]);
    $soal = bl_soal($kid); $kunci = []; foreach ($soal as $q) $kunci[(int)$q['id']] = $q['kunci'];
    $nSoal = max(1, count($soal));
    $segBy = []; foreach ($segs as $s) $segBy[$s['jenis']][] = $s;
    $pre = $segBy['pretest'][0] ?? null; $post = $segBy['posttest'][0] ?? null;
    $selesai = []; foreach (qall('SELECT f.* FROM bl_selesai f JOIN bl_seg s ON s.id = f.seg_id WHERE s.keg_id = ?', [$kid]) as $f) $selesai[(int)$f['peserta_id']][(int)$f['seg_id']] = $f['at'];
    $benar = []; $dijawab = [];
    foreach (qall('SELECT j.* FROM bl_jawab j JOIN bl_seg s ON s.id = j.seg_id WHERE s.keg_id = ?', [$kid]) as $j) {
        $p = (int)$j['peserta_id']; $sg = (int)$j['seg_id'];
        $dijawab[$p][$sg] = ($dijawab[$p][$sg] ?? 0) + 1;
        if (($kunci[(int)$j['soal_id']] ?? null) === $j['pilih']) $benar[$p][$sg] = ($benar[$p][$sg] ?? 0) + 1;
    }
    $aspek = qall('SELECT * FROM bl_aspek WHERE keg_id = ? ORDER BY id', [$kid]);
    $nilai = []; foreach (qall('SELECT n.* FROM bl_nilai n JOIN bl_aspek a ON a.id = n.aspek_id WHERE a.keg_id = ?', [$kid]) as $n) $nilai[(int)$n['peserta_id']][(int)$n['aspek_id']] = (int)$n['nilai'];
    $sikapAsp = array_values(array_filter($aspek, fn($a) => $a['jenis'] === 'sikap'));
    $praktikSegs = $segBy['praktik'] ?? [];
    $praktikAsp = []; foreach ($aspek as $a) if ($a['jenis'] === 'praktik') $praktikAsp[(int)$a['seg_id']][] = $a;
    $praktikSegsWithAsp = array_values(array_filter($praktikSegs, fn($s) => !empty($praktikAsp[(int)$s['id']])));
    $materiIds = array_map(fn($s) => (int)$s['id'], $segBy['materi'] ?? []);
    // komponen yang dimiliki kegiatan; bobot komponen yang tidak ada dibagi proporsional ke komponen lain
    $has = ['post' => true, 'praktik' => count($praktikSegsWithAsp) > 0, 'sikap' => count($sikapAsp) > 0, 'progres' => true];
    $wsum = 0; foreach (BL_BOBOT as $c => $w) if ($has[$c]) $wsum += $w;
    $bobot = []; foreach (BL_BOBOT as $c => $w) $bobot[$c] = $has[$c] ? $w / $wsum * 100 : 0;
    $avg = function (array $asp, int $pid) use ($nilai) {
        if (!$asp) return null;
        $s = 0; foreach ($asp as $a) $s += $nilai[$pid][(int)$a['id']] ?? (int)$a['bawaan'];
        return $s / count($asp);
    };
    $rows = [];
    foreach ($peserta as $p) {
        $pid = (int)$p['id'];
        $done = $selesai[$pid] ?? [];
        $sc = fn($seg) => ($seg && isset($done[(int)$seg['id']])) ? ($benar[$pid][(int)$seg['id']] ?? 0) / $nSoal * 100 : null;
        $vPre = $sc($pre); $vPost = $sc($post);
        $prog = ($vPre !== null && $vPost !== null) ? $vPost - $vPre : null;
        $gain = null;
        if ($prog !== null) $gain = $vPre < 100 ? $prog / (100 - $vPre) * 100 : ($vPost >= 100 ? 100 : $prog);
        $materiDone = count(array_intersect($materiIds, array_keys($done))) > 0;
        $vSikap = ($has['sikap'] && $materiDone) ? $avg($sikapAsp, $pid) : null;
        $vPraktik = null;
        if ($has['praktik']) {
            $sum = 0; $any = false;
            foreach ($praktikSegsWithAsp as $s) if (isset($done[(int)$s['id']])) { $sum += $avg($praktikAsp[(int)$s['id']], $pid); $any = true; }
            $vPraktik = $any ? $sum / count($praktikSegsWithAsp) : null;
        }
        $complete = count($segs) ? count(array_intersect(array_map(fn($s) => (int)$s['id'], $segs), array_keys($done))) / count($segs) * 100 : 0;
        $terbaik = $bobot['post'] * ($vPost ?? 0) / 100 + $bobot['praktik'] * ($vPraktik ?? 0) / 100 + $bobot['sikap'] * ($vSikap ?? 0) / 100 + $bobot['progres'] * max(0, min(100, $gain ?? 0)) / 100;
        $rows[$pid] = ['pesertaId' => $pid, 'name' => $p['nama'], 'pkmId' => (int)$p['puskesmas_id'], 'pre' => bl_round($vPre), 'post' => bl_round($vPost), 'progres' => bl_round($prog), 'gain' => bl_round($gain),
            'sikap' => bl_round($vSikap), 'praktik' => bl_round($vPraktik), 'complete' => bl_round($complete), 'terbaik' => bl_round($terbaik), 'doneSegs' => array_map('intval', array_keys($done)),
            'answered' => $dijawab[$pid] ?? (object)[], 'allDone' => count($segs) > 0 && count($done) >= count($segs)];
    }
    $rk = fn($key) => bl_rank(array_map(fn($r) => $r[$key], $rows));
    $rPost = $rk('post'); $rProg = $rk('progres'); $rComp = $rk('complete'); $rBest = $rk('terbaik');
    foreach ($rows as $pid => &$r) $r['rank'] = ['post' => $rPost[$pid], 'progres' => $rProg[$pid], 'complete' => $rComp[$pid], 'terbaik' => $rBest[$pid]];
    unset($r);
    // predikat diumumkan setelah semua segmen ditutup
    $final = count($segs) > 0 && !array_filter($segs, fn($s) => $s['status'] !== 'tutup');
    $winners = fn($key) => array_values(array_map(fn($r) => $r['pesertaId'], array_filter($rows, fn($r) => $r['rank'][$key] === 1)));
    $predikat = $final ? ['post' => $winners('post'), 'progres' => $winners('progres'), 'terbaik' => $winners('terbaik')] : null;
    return ['rows' => array_values($rows), 'bobot' => array_map('bl_round', $bobot), 'final' => $final, 'predikat' => $predikat, 'nSoal' => count($soal)];
}

/** Rekap penilaian peserta terhadap narasumber (rata-rata per aspek) */
function bl_eval_summary(array $k): array
{
    $out = [];
    foreach (bl_narsum_ids($k) as $nid) {
        $rows = qall("SELECT e.aspek, AVG(e.nilai) AS rata, COUNT(*) AS n FROM bl_eval e JOIN bl_peserta p ON p.id = e.peserta_id WHERE p.keg_id = ? AND e.narsum_id = ? GROUP BY e.aspek", [$k['id'], $nid]);
        $per = array_fill(0, BL_NEVAL, null); $n = 0;
        foreach ($rows as $r) { $per[(int)$r['aspek']] = round((float)$r['rata'], 1); $n = max($n, (int)$r['n']); }
        $vals = array_filter($per, fn($v) => $v !== null);
        $out[] = ['narsumId' => $nid, 'per' => $per, 'avg' => $vals ? round(array_sum($vals) / count($vals), 1) : null, 'n' => $n];
    }
    return $out;
}

/* ---------- ringkasan untuk status aplikasi ---------- */
function bl_state(array $u): array
{
    $role = $u['role'];
    $rows = [];
    if ($role === 'admin' || $role === 'pembina') $rows = qall('SELECT * FROM bl_keg ORDER BY id DESC');
    elseif ($role === 'fasil') $rows = array_values(array_filter(qall('SELECT * FROM bl_keg ORDER BY id DESC'), fn($k) => bl_is_staff($u, $k)));
    elseif ($role === 'binaan') $rows = array_values(array_filter(qall("SELECT * FROM bl_keg WHERE status <> 'draf' ORDER BY id DESC"), fn($k) => bl_can_view($u, $k)));
    elseif ($role === 'peserta') $rows = array_values(array_filter(qall("SELECT * FROM bl_keg WHERE status <> 'draf' ORDER BY id DESC"), fn($k) => bl_can_view($u, $k)));
    $out = [];
    foreach ($rows as $k) {
        $o = bl_out_keg($k);
        $o['nPeserta'] = (int)qval("SELECT COUNT(*) FROM bl_peserta WHERE keg_id = ? AND status = 'diterima'", [$k['id']]);
        if ($role === 'admin') $o['nWaiting'] = (int)qval("SELECT COUNT(*) FROM bl_peserta WHERE keg_id = ? AND status = 'menunggu'", [$k['id']]);
        if ($role === 'binaan') $o['mine'] = array_map(fn($p) => ['status' => $p['status']], qall('SELECT status FROM bl_peserta WHERE keg_id = ? AND puskesmas_id = ?', [$k['id'], $u['puskesmas_id']]));
        if ($role === 'peserta') { $r = bl_my_reg($u, (int)$k['id']); $o['myStatus'] = $r ? $r['status'] : null; }
        $out[] = $o;
    }
    return $out;
}

/* ---------- rincian satu kegiatan, sesuai peran ---------- */
function bl_detail(array $u, array $k): array
{
    $kid = (int)$k['id'];
    $role = $u['role'];
    $segs = bl_segs($kid);
    $d = ['keg' => bl_out_keg($k), 'segs' => array_map('bl_out_seg', $segs), 'narsum' => array_map('bl_profil', bl_narsum_ids($k)),
        'canOpen' => bl_can_open($u, $k), 'canContent' => bl_can_content($u, $k), 'bobot' => BL_BOBOT];
    $materi = fn() => array_map(fn($m) => ['id' => (int)$m['id'], 'segId' => $m['seg_id'] ? (int)$m['seg_id'] : null, 'title' => $m['judul'], 'size' => (int)$m['ukuran'], 'at' => $m['at'], 'by' => (string)$m['by_name']],
        qall('SELECT * FROM bl_materi WHERE keg_id = ? ORDER BY id', [$kid]));
    $aspek = fn() => array_map(fn($a) => ['id' => (int)$a['id'], 'jenis' => $a['jenis'], 'segId' => $a['seg_id'] ? (int)$a['seg_id'] : null, 'name' => $a['nama'], 'def' => (int)$a['bawaan']],
        qall('SELECT * FROM bl_aspek WHERE keg_id = ? ORDER BY id', [$kid]));
    if (bl_is_staff($u, $k)) {
        $d['peserta'] = array_map('bl_out_peserta', qall('SELECT * FROM bl_peserta WHERE keg_id = ? ORDER BY kirim_at, id', [$kid]));
        $d['soal'] = array_map(fn($q) => ['id' => (int)$q['id'], 'text' => $q['teks'], 'a' => $q['a'], 'b' => $q['b'], 'c' => $q['c'], 'd' => $q['d'], 'key' => $q['kunci']], bl_soal($kid));
        $d['materi'] = $materi();
        $d['aspek'] = $aspek();
        $d['nilai'] = array_map(fn($n) => ['pesertaId' => (int)$n['peserta_id'], 'aspekId' => (int)$n['aspek_id'], 'v' => (int)$n['nilai']],
            qall('SELECT n.* FROM bl_nilai n JOIN bl_aspek a ON a.id = n.aspek_id WHERE a.keg_id = ?', [$kid]));
        $d['scores'] = bl_scores($k);
        if ($role !== 'fasil') $d['eval'] = bl_eval_summary($k);
        $d['staffUsers'] = array_map(fn($x) => ['id' => (int)$x['id'], 'role' => $x['role'], 'name' => $x['nama'], 'active' => (bool)$x['aktif']],
            qall("SELECT id, role, nama, aktif FROM users WHERE role IN ('pembina','fasil') ORDER BY role, nama"));
    } elseif ($role === 'binaan') {
        $mine = qall('SELECT * FROM bl_peserta WHERE keg_id = ? AND puskesmas_id = ? ORDER BY id', [$kid, $u['puskesmas_id']]);
        $d['peserta'] = array_map('bl_out_peserta', $mine);
        $sc = bl_scores($k); $ids = array_map(fn($p) => (int)$p['id'], $mine);
        $d['scores'] = ['rows' => array_values(array_filter($sc['rows'], fn($r) => in_array($r['pesertaId'], $ids, true))), 'final' => $sc['final'], 'nSoal' => $sc['nSoal']];
    } elseif ($role === 'peserta' && !bl_me_peserta($u, $kid)) {
        $reg = bl_my_reg($u, $kid);
        $per = $u['personel_id'] ? q1('SELECT * FROM personel WHERE id = ?', [$u['personel_id']]) : null;
        $d = ['keg' => bl_out_keg($k), 'reg' => $reg ? bl_out_peserta($reg, false) : null, 'self' => ['name' => $per ? $per['nama'] : $u['nama'], 'jabatan' => (string)($per['jabatan'] ?? $u['jabatan']), 'nip' => (string)($per['nip'] ?? $u['nip'])]];
    } elseif ($role === 'peserta') {
        $me = bl_me_peserta($u, $kid);
        $pid = (int)$me['id'];
        $d['me'] = bl_out_peserta($me, false);
        $done = []; foreach (qall('SELECT * FROM bl_selesai WHERE peserta_id = ?', [$pid]) as $f) $done[(int)$f['seg_id']] = $f['at'];
        $d['done'] = (object)$done;
        $preSeg = null; foreach ($segs as $s) if ($s['jenis'] === 'pretest') $preSeg = $s;
        $preDone = $preSeg && isset($done[(int)$preSeg['id']]);
        $d['materi'] = $preDone ? $materi() : [];
        $d['materiLocked'] = !$preDone;
        // soal hanya untuk segmen tes yang sedang dibuka dan belum diselesaikan peserta
        $soal = bl_soal($kid); $d['tests'] = [];
        foreach ($segs as $s) {
            if (!in_array($s['jenis'], ['pretest', 'posttest'], true) || $s['status'] !== 'buka' || isset($done[(int)$s['id']])) continue;
            $ans = []; foreach (qall('SELECT soal_id, pilih FROM bl_jawab WHERE peserta_id = ? AND seg_id = ?', [$pid, $s['id']]) as $j) $ans[(int)$j['soal_id']] = $j['pilih'];
            $d['tests'][(int)$s['id']] = ['soal' => array_map(fn($q) => ['id' => (int)$q['id'], 'text' => $q['teks'], 'a' => $q['a'], 'b' => $q['b'], 'c' => $q['c'], 'd' => $q['d']], bl_order_for($soal, $s, $pid)), 'answers' => (object)$ans];
        }
        $d['tests'] = (object)$d['tests'];
        $ev = []; foreach (qall('SELECT * FROM bl_eval WHERE peserta_id = ?', [$pid]) as $e) $ev[(int)$e['narsum_id']][(int)$e['aspek']] = (int)$e['nilai'];
        $d['myEval'] = (object)array_map(fn($x) => (object)$x, $ev);
        $sc = bl_scores($k);
        $mine = null; foreach ($sc['rows'] as $r) if ($r['pesertaId'] === $pid) $mine = $r;
        // hasil tampil setelah peserta menyelesaikan semua segmen, atau setelah semua segmen ditutup
        $d['result'] = ($mine && ($mine['allDone'] || $sc['final'])) ? ['mine' => $mine, 'n' => count($sc['rows']), 'final' => $sc['final'],
            'predikat' => $sc['predikat'] ? array_map(fn($ids) => array_map(fn($i) => ['name' => (string)qval('SELECT nama FROM bl_peserta WHERE id = ?', [$i]), 'pkm' => (string)qval('SELECT p.nama FROM puskesmas p JOIN bl_peserta b ON b.puskesmas_id = p.id WHERE b.id = ?', [$i]), 'me' => $i === $pid], $ids), $sc['predikat']) : null]
            : null;
    }
    return $d;
}

/* ---------- berkas PDF ---------- */
function bl_pdf_upload(string $field, int $max, string $sub): array
{
    need(isset($_FILES[$field]) && is_uploaded_file($_FILES[$field]['tmp_name']), 'galat.unggah', 400);
    need($_FILES[$field]['size'] <= $max, $max === BL_SURAT_MAX ? 'bl.galat.surat_besar' : 'bl.galat.materi_besar', 400);
    $raw = (string)file_get_contents($_FILES[$field]['tmp_name']);
    need(substr($raw, 0, 5) === '%PDF-', 'bl.galat.bukan_pdf', 400);
    $file = $sub . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.pdf';
    file_put_contents(data_dir($sub) . '/' . $file, $raw);
    return [$file, strlen($raw)];
}

/* ---------- sub-akun peserta ---------- */
function bl_slug(string $nama): string
{
    $nama = preg_replace('/^(dr|drg|ns|apt|h|hj|ir)\.?\s+/i', '', trim($nama));
    $w = strtolower((string)preg_replace('/[^A-Za-z]/', '', (string)strtok($nama, " ,.")));
    return substr($w !== '' ? $w : 'peserta', 0, 20);
}
/** Sub-akun personel Puskesmas binaan (dibuat saat data personel disimpan); mengembalikan [user_id, username, sandi sementara|null bila sudah ada] */
function bl_sub_for_personel(array $per): array
{
    $ex = q1("SELECT * FROM users WHERE role = 'peserta' AND personel_id = ?", [$per['id']]);
    if ($ex) return [(int)$ex['id'], $ex['username'], null];
    $base = (string)(qval("SELECT username FROM users WHERE role = 'binaan' AND puskesmas_id = ? ORDER BY id LIMIT 1", [$per['puskesmas_id']]) ?: 'p' . (int)$per['puskesmas_id']);
    $un = $base . '.' . bl_slug($per['nama']); $try = $un; $i = 2;
    while (qval('SELECT COUNT(*) FROM users WHERE username = ?', [$try])) $try = $un . $i++;
    $pw = temp_password();
    $id = sp_insert('users', ['role' => 'peserta', 'nama' => $per['nama'], 'username' => $try, 'pass_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change' => 1,
        'puskesmas_id' => $per['puskesmas_id'], 'personel_id' => $per['id'], 'nip' => (string)$per['nip'], 'jabatan' => (string)$per['jabatan'], 'aktif' => $per['aktif'] ? 1 : 0, 'created_at' => now_str()]);
    return [(int)$id, $try, $pw];
}
/** Baris pendaftaran milik sub-akun ini (status apa pun) */
function bl_my_reg(array $u, int $kegId): ?array
{
    if ($u['role'] !== 'peserta') return null;
    return q1('SELECT * FROM bl_peserta WHERE keg_id = ? AND user_id = ?', [$kegId, $u['id']]);
}

/** Fasilitator yang tidak lagi punya kegiatan aktif dinonaktifkan (berlaku sampai kegiatan diarsipkan) */
function bl_fasil_cleanup(): void
{
    $active = [];
    foreach (qall("SELECT fasil FROM bl_keg WHERE status <> 'arsip'") as $k) foreach (bl_ids($k['fasil']) as $i) $active[$i] = true;
    $assigned = [];
    foreach (qall('SELECT fasil FROM bl_keg') as $k) foreach (bl_ids($k['fasil']) as $i) $assigned[$i] = true;
    foreach (array_keys($assigned) as $id) if (!isset($active[$id])) q("UPDATE users SET aktif = 0 WHERE id = ? AND role = 'fasil'", [$id]);
}

/* ---------- aksi API (semua diawali bl_) ---------- */
function bl_api(string $a, array $d, array $u)
{
    $role = $u['role'];
    $isA = $role === 'admin';
    $kegOf = function (int $id) use ($u): array {
        $k = bl_keg($id);
        need($k && bl_can_view($u, $k), 'bl.galat.tidak_ada', 404);
        return $k;
    };
    $segOf = function (int $id) use ($kegOf): array {
        $s = bl_seg_row($id);
        need((bool)$s, 'bl.galat.tidak_ada', 404);
        return [$s, $kegOf((int)$s['keg_id'])];
    };
    $notArsip = fn(array $k) => need($k['status'] !== 'arsip', 'bl.galat.arsip', 400);
    switch ($a) {
        case 'bl_get':
            $k = $kegOf(int_in($d, 'id'));
            return bl_detail($u, $k);

        /* --- Admin: kegiatan --- */
        case 'bl_keg_add':
            need($isA);
            $judul = str_in($d, 'title', 200);
            need($judul !== '', 'bl.galat.isi_judul', 400);
            $id = sp_insert('bl_keg', ['judul' => $judul, 'penyelenggara' => str_in($d, 'org', 200), 'status' => 'draf', 'daftar_buka' => 0, 'pembuka' => '[]', 'fasil' => '[]', 'created_at' => now_str()]);
            foreach (['pretest', 'materi', 'posttest', 'penutup'] as $i => $j)
                sp_insert('bl_seg', ['keg_id' => $id, 'urut' => $i + 1, 'jenis' => $j, 'judul' => t('bl.seg_judul.' . $j, ['n' => 1]), 'sesi' => '[]', 'status' => 'belum']);
            audit('bl_keg_add', ['id' => $id]);
            return ['id' => $id];
        case 'bl_keg_update':
            need($isA);
            $k = $kegOf(int_in($d, 'id')); $notArsip($k);
            $map = ['title' => 'judul', 'org' => 'penyelenggara', 'place' => 'lokasi', 'start' => 'tgl_mulai', 'end' => 'tgl_selesai', 'desc' => 'deskripsi'];
            $kk = (string)($d['k'] ?? '');
            need(isset($map[$kk]), 'galat.data_tidak_valid', 400);
            $v = in_array($kk, ['start', 'end'], true) ? date_in($d, 'v') : str_in($d, 'v', $kk === 'desc' ? 4000 : 200);
            need($kk !== 'title' || $v !== '', 'bl.galat.isi_judul', 400);
            q("UPDATE bl_keg SET {$map[$kk]} = ? WHERE id = ?", [$v, $k['id']]);
            return null;
        case 'bl_keg_status':
            need($isA);
            $k = $kegOf(int_in($d, 'id')); $notArsip($k);
            $v = (string)($d['v'] ?? '');
            if ($v === 'arsip') {
                q("UPDATE bl_keg SET status = 'arsip', daftar_buka = 0, arsip_at = ? WHERE id = ?", [now_str(), $k['id']]);
                bl_fasil_cleanup();
            } elseif ($v === 'terbit' || $v === 'draf') {
                if ($v === 'draf') need(!qval('SELECT COUNT(*) FROM bl_peserta WHERE keg_id = ?', [$k['id']]), 'bl.galat.sudah_ada_pendaftar', 400);
                q('UPDATE bl_keg SET status = ?, daftar_buka = ? WHERE id = ?', [$v, $v === 'terbit' ? (int)$k['daftar_buka'] : 0, $k['id']]);
            } else fail('galat.data_tidak_valid');
            audit('bl_keg_status', ['id' => $k['id'], 'v' => $v]);
            return null;
        case 'bl_keg_daftar':
            need($isA);
            $k = $kegOf(int_in($d, 'id')); $notArsip($k);
            need($k['status'] === 'terbit' || empty($d['v']), 'bl.galat.belum_terbit', 400);
            q('UPDATE bl_keg SET daftar_buka = ? WHERE id = ?', [!empty($d['v']) ? 1 : 0, $k['id']]);
            return null;
        case 'bl_keg_people':
            need($isA);
            $k = $kegOf(int_in($d, 'id')); $notArsip($k);
            $kk = (string)($d['k'] ?? '');
            need(in_array($kk, ['fasil', 'pembuka'], true), 'galat.data_tidak_valid', 400);
            $ids = array_values(array_unique(array_map('intval', (array)($d['ids'] ?? []))));
            $roles = $kk === 'fasil' ? ['fasil'] : ['pembina', 'fasil'];
            foreach ($ids as $i) need(in_array((string)qval('SELECT role FROM users WHERE id = ?', [$i]), $roles, true), 'galat.data_tidak_valid', 400);
            q("UPDATE bl_keg SET $kk = ? WHERE id = ?", [jenc($ids), $k['id']]);
            if ($kk === 'fasil') foreach ($ids as $i) q("UPDATE users SET aktif = 1 WHERE id = ? AND role = 'fasil'", [$i]);
            return null;

        /* --- Admin: segmen --- */
        case 'bl_seg_add':
            need($isA);
            $k = $kegOf(int_in($d, 'kegId')); $notArsip($k);
            $j = (string)($d['jenis'] ?? '');
            need(in_array($j, ['materi', 'praktik'], true), 'galat.data_tidak_valid', 400);
            $segs = bl_segs((int)$k['id']);
            $n = count(array_filter($segs, fn($s) => $s['jenis'] === $j)) + 1;
            $postUrut = 0; foreach ($segs as $s) if ($s['jenis'] === 'posttest') $postUrut = (int)$s['urut'];
            q('UPDATE bl_seg SET urut = urut + 1 WHERE keg_id = ? AND urut >= ?', [$k['id'], $postUrut]);
            $id = sp_insert('bl_seg', ['keg_id' => $k['id'], 'urut' => $postUrut, 'jenis' => $j, 'judul' => t('bl.seg_judul.' . $j, ['n' => $n]), 'sesi' => '[]', 'status' => 'belum']);
            bl_renumber((int)$k['id']);
            return ['id' => $id];
        case 'bl_seg_update':
            need($isA);
            [$s, $k] = $segOf(int_in($d, 'id')); $notArsip($k);
            $kk = (string)($d['k'] ?? '');
            $map = ['title' => 'judul', 'picId' => 'pic_id', 'picText' => 'pic_nama', 'date' => 'tanggal', 'from' => 'jam_mulai', 'to' => 'jam_selesai'];
            need(isset($map[$kk]), 'galat.data_tidak_valid', 400);
            if ($kk === 'date') $v = date_in($d, 'v');
            elseif ($kk === 'from' || $kk === 'to') { $v = str_in($d, 'v', 5); need($v === '' || (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v), 'bl.galat.jam', 400); }
            elseif ($kk === 'picId') { $v = int_in($d, 'v') ?: null; need($v === null || in_array((string)qval('SELECT role FROM users WHERE id = ?', [$v]), ['pembina', 'fasil'], true), 'galat.data_tidak_valid', 400); }
            else $v = str_in($d, 'v', 200);
            q("UPDATE bl_seg SET {$map[$kk]} = ? WHERE id = ?", [$v, $s['id']]);
            return null;
        case 'bl_seg_sesi':
            need($isA);
            [$s, $k] = $segOf(int_in($d, 'id')); $notArsip($k);
            need($s['jenis'] === 'materi', 'galat.data_tidak_valid', 400);
            $list = [];
            foreach (array_slice((array)($d['sesi'] ?? []), 0, 20) as $x) {
                $judul = mb_substr(trim((string)($x['title'] ?? '')), 0, 200);
                if ($judul === '') continue;
                $tgl = trim((string)($x['date'] ?? '')); need($tgl === '' || (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl), 'galat.tanggal', 400);
                foreach (['from', 'to'] as $f) { $x[$f] = trim((string)($x[$f] ?? '')); need($x[$f] === '' || (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $x[$f]), 'bl.galat.jam', 400); }
                $list[] = ['title' => $judul, 'date' => $tgl, 'from' => $x['from'], 'to' => $x['to'], 'pic' => mb_substr(trim((string)($x['pic'] ?? '')), 0, 150)];
            }
            q('UPDATE bl_seg SET sesi = ? WHERE id = ?', [jenc($list), $s['id']]);
            return null;
        case 'bl_seg_move':
            need($isA);
            [$s, $k] = $segOf(int_in($d, 'id')); $notArsip($k);
            $segs = bl_segs((int)$k['id']);
            $i = array_search((int)$s['id'], array_map(fn($x) => (int)$x['id'], $segs), true);
            $j = $i + (int_in($d, 'dir') < 0 ? -1 : 1);
            need($j >= 0 && $j < count($segs), 'bl.galat.urutan', 400);
            $order = $segs; [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
            need(bl_order_ok(array_map(fn($x) => $x['jenis'], $order)), 'bl.galat.urutan', 400);
            foreach ($order as $n => $x) q('UPDATE bl_seg SET urut = ? WHERE id = ?', [$n + 1, $x['id']]);
            return null;
        case 'bl_seg_del':
            need($isA);
            [$s, $k] = $segOf(int_in($d, 'id')); $notArsip($k);
            need(in_array($s['jenis'], ['materi', 'praktik'], true) && $s['status'] === 'belum', 'bl.galat.hapus_segmen', 400);
            $rest = array_values(array_filter(bl_segs((int)$k['id']), fn($x) => (int)$x['id'] !== (int)$s['id']));
            need(bl_order_ok(array_map(fn($x) => $x['jenis'], $rest)), 'bl.galat.urutan', 400);
            q('DELETE FROM bl_seg WHERE id = ?', [$s['id']]);
            q('DELETE FROM bl_nilai WHERE aspek_id IN (SELECT id FROM bl_aspek WHERE seg_id = ?)', [$s['id']]);
            q('DELETE FROM bl_aspek WHERE seg_id = ?', [$s['id']]);
            q('UPDATE bl_materi SET seg_id = NULL WHERE seg_id = ?', [$s['id']]);
            bl_renumber((int)$k['id']);
            return null;
        case 'bl_seg_state':
            [$s, $k] = $segOf(int_in($d, 'id'));
            need(bl_can_open($u, $k));
            $v = (string)($d['v'] ?? '');
            need($k['status'] === 'terbit', 'bl.galat.belum_terbit', 400);
            if ($v === 'buka') {
                need($s['status'] === 'belum', 'bl.galat.status_segmen', 400);
                if (in_array($s['jenis'], ['pretest', 'posttest'], true)) need((int)qval('SELECT COUNT(*) FROM bl_soal WHERE keg_id = ?', [$k['id']]) === BL_NSOAL, 'bl.galat.soal_kurang', 400);
                q("UPDATE bl_seg SET status = 'buka', dibuka_at = ? WHERE id = ?", [now_str(), $s['id']]);
            } elseif ($v === 'tutup') {
                need($s['status'] === 'buka', 'bl.galat.status_segmen', 400);
                q("UPDATE bl_seg SET status = 'tutup', ditutup_at = ? WHERE id = ?", [now_str(), $s['id']]);
            } else fail('galat.data_tidak_valid');
            audit('bl_seg_state', ['seg' => $s['id'], 'v' => $v]);
            return null;

        /* --- Admin: verifikasi peserta --- */
        case 'bl_verif':
            need($isA);
            $p = q1('SELECT * FROM bl_peserta WHERE id = ?', [int_in($d, 'id')]);
            need((bool)$p, 'bl.galat.tidak_ada', 404);
            $k = $kegOf((int)$p['keg_id']); $notArsip($k);
            need($p['status'] === 'menunggu', 'bl.galat.sudah_diverifikasi', 400);
            $v = (string)($d['v'] ?? ''); $msg = str_in($d, 'msg', 2000);
            need(in_array($v, ['diterima', 'lengkapi', 'ditolak'], true), 'galat.data_tidak_valid', 400);
            need($v === 'diterima' || $msg !== '', 'bl.galat.isi_pesan', 400);
            $res = null;
            q('UPDATE bl_peserta SET status = ?, pesan = ?, verif_at = ?, verif_by = ? WHERE id = ?', [$v, $msg, now_str(), $u['nama'], $p['id']]);
            notify('pkm:' . $p['puskesmas_id'], t('bl.notif.' . $v, ['nama' => $p['nama'], 'keg' => $k['judul']]), 'bl-' . $k['id']);
            audit('bl_verif', ['peserta' => $p['id'], 'v' => $v]);
            return $res;

        /* --- Sub-akun: mendaftarkan diri; akun Puskesmas: mereset sandi sub-akun personelnya --- */
        case 'bl_daftar':
            need($role === 'peserta' && $u['personel_id']);
            $k = $kegOf(int_in($d, 'kegId'));
            need($k['status'] === 'terbit' && $k['daftar_buka'], 'bl.galat.pendaftaran_tutup', 400);
            need(!bl_my_reg($u, (int)$k['id']), 'bl.galat.sudah_terdaftar', 400);
            $nama = str_in($d, 'name', 200); $jab = str_in($d, 'jabatan', 150); $nip = str_in($d, 'nip', 60);
            need($nama !== '' && $jab !== '' && $nip !== '', 'bl.galat.lengkapi_data', 400);
            [$file] = bl_pdf_upload('surat', BL_SURAT_MAX, 'surat');
            sp_insert('bl_peserta', ['keg_id' => $k['id'], 'puskesmas_id' => $u['puskesmas_id'], 'personel_id' => $u['personel_id'], 'nama' => $nama, 'jabatan' => $jab, 'nip' => $nip, 'surat' => $file, 'status' => 'menunggu', 'user_id' => $u['id'], 'kirim_at' => now_str()]);
            audit('bl_daftar', ['keg' => $k['id']]);
            return null;
        case 'bl_kirim_ulang':
            need($role === 'peserta');
            $k = $kegOf(int_in($d, 'kegId')); $notArsip($k);
            $p = bl_my_reg($u, (int)$k['id']);
            need($p && $p['status'] === 'lengkapi', 'bl.galat.tidak_bisa_kirim_ulang', 400);
            $nama = str_in($d, 'name', 200); $jab = str_in($d, 'jabatan', 150); $nip = str_in($d, 'nip', 60);
            need($nama !== '' && $jab !== '' && $nip !== '', 'bl.galat.lengkapi_data', 400);
            $file = $p['surat'];
            if (isset($_FILES['surat']) && $_FILES['surat']['error'] !== UPLOAD_ERR_NO_FILE) [$file] = bl_pdf_upload('surat', BL_SURAT_MAX, 'surat');
            q("UPDATE bl_peserta SET nama = ?, jabatan = ?, nip = ?, surat = ?, status = 'menunggu', kirim_at = ? WHERE id = ?", [$nama, $jab, $nip, $file, now_str(), $p['id']]);
            audit('bl_kirim_ulang', ['peserta' => $p['id']]);
            return null;
        case 'bl_reset_sub':
            need($role === 'binaan');
            $sub = q1("SELECT * FROM users WHERE role = 'peserta' AND personel_id = ? AND puskesmas_id = ?", [int_in($d, 'personelId'), $u['puskesmas_id']]);
            need((bool)$sub, 'bl.galat.tidak_ada_sub', 404);
            $pw = temp_password();
            q('UPDATE users SET pass_hash = ?, must_change = 1 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $sub['id']]);
            audit('bl_reset_sub', ['user' => $sub['id']]);
            return ['username' => $sub['username'], 'password' => $pw];

        /* --- Pembina/Fasilitator: soal, materi, aspek, nilai, profil --- */
        case 'bl_soal_save':
            $k = $kegOf(int_in($d, 'kegId'));
            need(bl_can_content($u, $k));
            need(!qval("SELECT COUNT(*) FROM bl_seg WHERE keg_id = ? AND jenis IN ('pretest','posttest') AND status <> 'belum'", [$k['id']]), 'bl.galat.soal_terkunci', 400);
            // pilihan dikirim sebagai oa..od (kunci "a" dipakai untuk nama aksi)
            $f = ['text' => str_in($d, 'text', 2000)]; foreach (['a', 'b', 'c', 'd'] as $x) $f[$x] = str_in($d, 'o' . $x, 2000);
            foreach ($f as $v) need($v !== '', 'bl.galat.soal_lengkap', 400);
            $key = strtolower((string)($d['key'] ?? ''));
            need(in_array($key, ['a', 'b', 'c', 'd'], true), 'bl.galat.soal_kunci', 400);
            $row = ['teks' => $f['text'], 'a' => $f['a'], 'b' => $f['b'], 'c' => $f['c'], 'd' => $f['d'], 'kunci' => $key];
            $id = int_in($d, 'id');
            if ($id) {
                need((bool)qval('SELECT COUNT(*) FROM bl_soal WHERE id = ? AND keg_id = ?', [$id, $k['id']]), 'bl.galat.tidak_ada', 404);
                sp_update('bl_soal', $id, $row);
            } else {
                need((int)qval('SELECT COUNT(*) FROM bl_soal WHERE keg_id = ?', [$k['id']]) < BL_NSOAL, 'bl.galat.soal_penuh', 400);
                $id = sp_insert('bl_soal', $row + ['keg_id' => $k['id'], 'urut' => (int)qval('SELECT COALESCE(MAX(urut), 0) FROM bl_soal WHERE keg_id = ?', [$k['id']]) + 1]);
            }
            return ['id' => $id];
        case 'bl_soal_del':
            $q = q1('SELECT * FROM bl_soal WHERE id = ?', [int_in($d, 'id')]);
            need((bool)$q, 'bl.galat.tidak_ada', 404);
            $k = $kegOf((int)$q['keg_id']);
            need(bl_can_content($u, $k));
            need(!qval("SELECT COUNT(*) FROM bl_seg WHERE keg_id = ? AND jenis IN ('pretest','posttest') AND status <> 'belum'", [$k['id']]), 'bl.galat.soal_terkunci', 400);
            q('DELETE FROM bl_soal WHERE id = ?', [$q['id']]);
            foreach (bl_soal((int)$k['id']) as $i => $x) q('UPDATE bl_soal SET urut = ? WHERE id = ?', [$i + 1, $x['id']]);
            return null;
        case 'bl_materi_upload':
            $k = $kegOf(int_in($d, 'kegId'));
            need(bl_can_content($u, $k));
            $judul = str_in($d, 'title', 200);
            need($judul !== '', 'bl.galat.isi_judul_materi', 400);
            $segId = int_in($d, 'segId') ?: null;
            if ($segId) need((bool)qval("SELECT COUNT(*) FROM bl_seg WHERE id = ? AND keg_id = ? AND jenis IN ('materi','praktik')", [$segId, $k['id']]), 'galat.data_tidak_valid', 400);
            [$file, $size] = bl_pdf_upload('file', BL_MATERI_MAX, 'materi');
            $id = sp_insert('bl_materi', ['keg_id' => $k['id'], 'seg_id' => $segId, 'judul' => $judul, 'file' => $file, 'ukuran' => $size, 'at' => now_str(), 'by_name' => $u['nama']]);
            audit('bl_materi_upload', ['id' => $id]);
            return ['id' => $id];
        case 'bl_materi_del':
            $m = q1('SELECT * FROM bl_materi WHERE id = ?', [int_in($d, 'id')]);
            need((bool)$m, 'bl.galat.tidak_ada', 404);
            $k = $kegOf((int)$m['keg_id']);
            need(bl_can_content($u, $k));
            q('DELETE FROM bl_materi WHERE id = ?', [$m['id']]);
            @unlink(data_dir('materi') . '/' . basename($m['file']));
            return null;
        case 'bl_aspek_add':
            $k = $kegOf(int_in($d, 'kegId'));
            need(bl_can_content($u, $k));
            $j = (string)($d['jenis'] ?? ''); $nama = str_in($d, 'name', 200); $def = int_in($d, 'def');
            need(in_array($j, ['sikap', 'praktik'], true), 'galat.data_tidak_valid', 400);
            need($nama !== '', 'bl.galat.isi_aspek', 400);
            need($def >= 0 && $def <= 100, 'bl.galat.skala', 400);
            $segId = null;
            if ($j === 'praktik') { $segId = int_in($d, 'segId'); need((bool)qval("SELECT COUNT(*) FROM bl_seg WHERE id = ? AND keg_id = ? AND jenis = 'praktik'", [$segId, $k['id']]), 'bl.galat.pilih_praktik', 400); }
            return ['id' => sp_insert('bl_aspek', ['keg_id' => $k['id'], 'jenis' => $j, 'seg_id' => $segId, 'nama' => $nama, 'bawaan' => $def])];
        case 'bl_aspek_update':
        case 'bl_aspek_del':
            $as = q1('SELECT * FROM bl_aspek WHERE id = ?', [int_in($d, 'id')]);
            need((bool)$as, 'bl.galat.tidak_ada', 404);
            $k = $kegOf((int)$as['keg_id']);
            need(bl_can_content($u, $k));
            if ($a === 'bl_aspek_del') { q('DELETE FROM bl_nilai WHERE aspek_id = ?', [$as['id']]); q('DELETE FROM bl_aspek WHERE id = ?', [$as['id']]); return null; }
            $kk = (string)($d['k'] ?? '');
            if ($kk === 'name') { $v = str_in($d, 'v', 200); need($v !== '', 'bl.galat.isi_aspek', 400); q('UPDATE bl_aspek SET nama = ? WHERE id = ?', [$v, $as['id']]); }
            elseif ($kk === 'def') { $v = trim((string)($d['v'] ?? '')); need(ctype_digit($v) && (int)$v <= 100, 'bl.galat.skala', 400); q('UPDATE bl_aspek SET bawaan = ? WHERE id = ?', [(int)$v, $as['id']]); }
            else fail('galat.data_tidak_valid');
            return null;
        case 'bl_nilai':
            $as = q1('SELECT * FROM bl_aspek WHERE id = ?', [int_in($d, 'aspekId')]);
            need((bool)$as, 'bl.galat.tidak_ada', 404);
            $k = $kegOf((int)$as['keg_id']);
            need(bl_can_content($u, $k));
            $p = q1("SELECT * FROM bl_peserta WHERE id = ? AND keg_id = ? AND status = 'diterima'", [int_in($d, 'pesertaId'), $k['id']]);
            need((bool)$p, 'bl.galat.tidak_ada', 404);
            $v = trim((string)($d['v'] ?? ''));
            q('DELETE FROM bl_nilai WHERE peserta_id = ? AND aspek_id = ?', [$p['id'], $as['id']]);
            if ($v !== '') { need(ctype_digit($v) && (int)$v <= 100, 'bl.galat.skala', 400); sp_insert('bl_nilai', ['peserta_id' => $p['id'], 'aspek_id' => $as['id'], 'nilai' => (int)$v]); }
            return null;
        case 'bl_get_profil':
            need(in_array($role, ['pembina', 'fasil'], true));
            return bl_profil((int)$u['id']);
        case 'bl_profil_save':
            need(in_array($role, ['pembina', 'fasil'], true));
            $row = ['instansi' => str_in($d, 'instansi', 200), 'jabatan' => str_in($d, 'jabatan', 200), 'ringkasan' => str_in($d, 'ringkasan', 3000), 'updated_at' => now_str()];
            if (qval('SELECT COUNT(*) FROM bl_profil WHERE user_id = ?', [$u['id']])) q('UPDATE bl_profil SET instansi = ?, jabatan = ?, ringkasan = ?, updated_at = ? WHERE user_id = ?', [$row['instansi'], $row['jabatan'], $row['ringkasan'], $row['updated_at'], $u['id']]);
            else sp_insert('bl_profil', $row + ['user_id' => $u['id']]);
            return null;
        case 'bl_profil_foto':
            need(in_array($role, ['pembina', 'fasil'], true));
            need(isset($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name']), 'galat.unggah', 400);
            need($_FILES['file']['size'] <= 5 * 1024 * 1024, 'galat.foto_besar', 400);
            $raw = (string)file_get_contents($_FILES['file']['tmp_name']);
            $info = @getimagesizefromstring($raw);
            need($info !== false && in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true), 'galat.bukan_foto', 400);
            [$img] = fit_image($raw, 600, 800, $info[2] === IMAGETYPE_JPEG);
            $file = 'u' . (int)$u['id'] . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.jpg';
            imagejpeg($img, data_dir('profil') . '/' . $file, 85); imagedestroy($img);
            if (qval('SELECT COUNT(*) FROM bl_profil WHERE user_id = ?', [$u['id']])) q('UPDATE bl_profil SET foto = ?, updated_at = ? WHERE user_id = ?', [$file, now_str(), $u['id']]);
            else sp_insert('bl_profil', ['user_id' => $u['id'], 'foto' => $file, 'updated_at' => now_str()]);
            return null;

        /* --- Peserta: menjawab, menyelesaikan segmen, menilai narasumber --- */
        case 'bl_jawab':
        case 'bl_selesai':
        case 'bl_eval':
            need($role === 'peserta');
            [$s, $k] = $segOf(int_in($d, 'segId'));
            $me = bl_me_peserta($u, (int)$k['id']);
            need((bool)$me);
            need($s['status'] === 'buka', $s['status'] === 'tutup' ? 'bl.galat.segmen_ditutup' : 'bl.galat.segmen_belum', 400);
            need(!qval('SELECT COUNT(*) FROM bl_selesai WHERE peserta_id = ? AND seg_id = ?', [$me['id'], $s['id']]), 'bl.galat.sudah_selesai', 400);
            if ($a === 'bl_jawab') {
                need(in_array($s['jenis'], ['pretest', 'posttest'], true), 'galat.data_tidak_valid', 400);
                $soalId = int_in($d, 'soalId'); $pil = strtolower((string)($d['v'] ?? ''));
                need((bool)qval('SELECT COUNT(*) FROM bl_soal WHERE id = ? AND keg_id = ?', [$soalId, $k['id']]) && in_array($pil, ['a', 'b', 'c', 'd'], true), 'galat.data_tidak_valid', 400);
                q('DELETE FROM bl_jawab WHERE peserta_id = ? AND seg_id = ? AND soal_id = ?', [$me['id'], $s['id'], $soalId]);
                sp_insert('bl_jawab', ['peserta_id' => $me['id'], 'seg_id' => $s['id'], 'soal_id' => $soalId, 'pilih' => $pil, 'at' => now_str()]);
                return null;
            }
            if ($a === 'bl_eval') {
                need($s['jenis'] === 'penutup', 'galat.data_tidak_valid', 400);
                $nid = int_in($d, 'narsumId'); $asp = int_in($d, 'aspek'); $v = trim((string)($d['v'] ?? ''));
                need(in_array($nid, bl_narsum_ids($k), true) && $asp >= 0 && $asp < BL_NEVAL, 'galat.data_tidak_valid', 400);
                q('DELETE FROM bl_eval WHERE peserta_id = ? AND narsum_id = ? AND aspek = ?', [$me['id'], $nid, $asp]);
                if ($v !== '') { need(ctype_digit($v) && (int)$v <= 100, 'bl.galat.skala', 400); sp_insert('bl_eval', ['peserta_id' => $me['id'], 'narsum_id' => $nid, 'aspek' => $asp, 'nilai' => (int)$v]); }
                return null;
            }
            if ($s['jenis'] === 'penutup') {
                $need = count(bl_narsum_ids($k)) * BL_NEVAL;
                need((int)qval('SELECT COUNT(*) FROM bl_eval WHERE peserta_id = ?', [$me['id']]) >= $need, 'bl.galat.eval_belum', 400);
            }
            sp_insert('bl_selesai', ['peserta_id' => $me['id'], 'seg_id' => $s['id'], 'at' => now_str()]);
            return null;
    }
    fail('galat.aksi_tidak_dikenal');
}
