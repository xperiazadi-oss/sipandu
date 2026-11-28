<?php
/* Subdomain pembelajaran (Si PANDU – Peningkatan Kapasitas Kompetensi SDM): kegiatan, segmen, peserta, soal, penilaian, rekap.
   Akun memakai tabel bl_users (terpisah dari Si PANDU). Peran: admin, pembina, fasil (Fasilitator), pengawas, peserta. */
// Tanpa strict_types: nilai dari basis data selalu berupa string dan dikonversi otomatis oleh PHP.

const BL_JENIS = ['pretest', 'materi', 'praktik', 'posttest', 'penutup'];
const BL_NSOAL = 25;        // jumlah soal maksimal
const BL_NSOAL_MIN = 10;    // jumlah soal minimal untuk membuka Pretest/Post test (nilai = benar ÷ jumlah soal × 100)
const BL_NEVAL = 10;
/** Bobot Peserta Terbaik (kesepakatan): posttest 30, praktik 30, sikap 25, progres 15 */
const BL_BOBOT = ['post' => 30, 'praktik' => 30, 'sikap' => 25, 'progres' => 15];
const BL_SURAT_MAX = 1048576;      // 1 MB
const BL_MATERI_MAX = 10485760;    // 10 MB
/** Status pendaftaran yang mengisi kuota */
const BL_ISI_KUOTA = ['menunggu', 'lengkapi', 'diterima'];

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
/** Pengawas yang ditugaskan pada kegiatan ini */
function bl_is_pengawas(array $u, array $k): bool
{
    return $u['role'] === 'pengawas' && in_array((int)$u['id'], bl_ids($k['pengawas']), true);
}
function bl_me_peserta(array $u, int $kegId): ?array
{
    if ($u['role'] !== 'peserta') return null;
    return q1("SELECT * FROM bl_peserta WHERE keg_id = ? AND user_id = ? AND status = 'diterima'", [$kegId, $u['id']]);
}
function bl_can_view(array $u, array $k): bool
{
    if (bl_is_staff($u, $k)) return true;
    if (bl_is_pengawas($u, $k)) return $k['status'] !== 'draf';
    if ($u['role'] === 'peserta') return $k['status'] === 'terbit' || ($k['status'] === 'arsip' && (bool)bl_my_reg($u, (int)$k['id']));
    return false;
}

/* ---------- kuota ---------- */
/** Jumlah pendaftar yang mengisi kuota (menunggu, diminta melengkapi, diterima) */
function bl_terisi(int $kegId): int
{
    return (int)qval("SELECT COUNT(*) FROM bl_peserta WHERE keg_id = ? AND status IN ('menunggu','lengkapi','diterima')", [$kegId]);
}
/** Formulir pendaftaran terbuka: terbit, pendaftaran dibuka Admin, dan kuota belum penuh */
function bl_form_open(array $k): bool
{
    return $k['status'] === 'terbit' && (bool)$k['daftar_buka'] && bl_terisi((int)$k['id']) < (int)$k['kuota'];
}

/* ---------- keluaran ---------- */
function bl_out_keg(array $k): array
{
    return ['id' => (int)$k['id'], 'title' => $k['judul'], 'org' => (string)$k['penyelenggara'], 'place' => (string)$k['lokasi'], 'start' => (string)$k['tgl_mulai'], 'end' => (string)$k['tgl_selesai'],
        'desc' => (string)$k['deskripsi'], 'status' => $k['status'], 'open' => (bool)$k['daftar_buka'], 'fasil' => bl_ids($k['fasil']), 'pembuka' => bl_ids($k['pembuka']),
        'pengawas' => bl_ids($k['pengawas']), 'kuota' => (int)$k['kuota'], 'terisi' => bl_terisi((int)$k['id']), 'formOpen' => bl_form_open($k)];
}
function bl_out_seg(array $s): array
{
    return ['id' => (int)$s['id'], 'urut' => (int)$s['urut'], 'jenis' => $s['jenis'], 'title' => (string)$s['judul'], 'picId' => $s['pic_id'] ? (int)$s['pic_id'] : null, 'picName' => bl_pic_name($s),
        'picText' => (string)$s['pic_nama'], 'date' => (string)$s['tanggal'], 'from' => (string)$s['jam_mulai'], 'to' => (string)$s['jam_selesai'], 'sesi' => jdec($s['sesi']), 'status' => $s['status'],
        'openedAt' => $s['dibuka_at'], 'closedAt' => $s['ditutup_at']];
}
function bl_pic_name(array $s): string
{
    if ($s['pic_id']) { $n = qval('SELECT nama FROM bl_users WHERE id = ?', [$s['pic_id']]); if ($n) return (string)$n; }
    return (string)$s['pic_nama'];
}
function bl_out_peserta(array $p, bool $withUser = true): array
{
    $o = ['id' => (int)$p['id'], 'kegId' => (int)$p['keg_id'], 'name' => $p['nama'], 'jabatan' => (string)$p['jabatan'], 'nip' => (string)$p['nip'], 'instansi' => (string)$p['instansi'],
        'email' => (string)$p['email'], 'hp' => (string)$p['hp'], 'hasSurat' => (bool)$p['surat'], 'status' => $p['status'], 'msg' => (string)$p['pesan'], 'sentAt' => $p['kirim_at'], 'verAt' => $p['verif_at'], 'verBy' => (string)$p['verif_by']];
    if ($withUser) $o['username'] = (string)qval('SELECT username FROM bl_users WHERE id = ?', [$p['user_id']]);
    return $o;
}
function bl_profil(int $userId): array
{
    $u = q1('SELECT id, nama, role FROM bl_users WHERE id = ?', [$userId]);
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
        $rows[$pid] = ['pesertaId' => $pid, 'name' => $p['nama'], 'instansi' => (string)$p['instansi'], 'pre' => bl_round($vPre), 'post' => bl_round($vPost), 'progres' => bl_round($prog), 'gain' => bl_round($gain),
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
    $all = qall('SELECT * FROM bl_keg ORDER BY id DESC');
    if ($role === 'admin' || $role === 'pembina') $rows = $all;
    elseif ($role === 'fasil') $rows = array_values(array_filter($all, fn($k) => bl_is_staff($u, $k)));
    else $rows = array_values(array_filter($all, fn($k) => $k['status'] !== 'draf' && bl_can_view($u, $k)));
    $out = [];
    foreach ($rows as $k) {
        $o = bl_out_keg($k);
        $o['nPeserta'] = (int)qval("SELECT COUNT(*) FROM bl_peserta WHERE keg_id = ? AND status = 'diterima'", [$k['id']]);
        if ($role === 'admin') $o['nWaiting'] = (int)qval("SELECT COUNT(*) FROM bl_peserta WHERE keg_id = ? AND status = 'menunggu'", [$k['id']]);
        if ($role === 'peserta') { $r = bl_my_reg($u, (int)$k['id']); $o['myStatus'] = $r ? $r['status'] : null; }
        $out[] = $o;
    }
    return $out;
}

/** Nama peraih predikat (untuk peserta dan Pengawas) */
function bl_predikat_names(?array $pred, int $pidMe = 0): ?array
{
    if (!$pred) return null;
    return array_map(fn($ids) => array_map(function ($i) use ($pidMe) {
        $p = q1('SELECT nama, instansi FROM bl_peserta WHERE id = ?', [$i]);
        return ['name' => (string)($p['nama'] ?? ''), 'instansi' => (string)($p['instansi'] ?? ''), 'me' => $i === $pidMe];
    }, $ids), $pred);
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
            qall("SELECT id, role, nama, aktif FROM bl_users WHERE role IN ('pembina','fasil','pengawas') ORDER BY role, nama"));
    } elseif (bl_is_pengawas($u, $k)) {
        // Pengawas: progres per segmen (timeline sesuai rundown) dan hasil akhir
        $sc = bl_scores($k);
        $n = count($sc['rows']);
        $d['timeline'] = array_map(function ($s) use ($sc, $n) {
            $done = count(array_filter($sc['rows'], fn($r) => in_array((int)$s['id'], $r['doneSegs'], true)));
            $o = ['segId' => (int)$s['id'], 'done' => $done, 'n' => $n];
            if (in_array($s['jenis'], ['pretest', 'posttest'], true)) {
                $vals = array_values(array_filter(array_map(fn($r) => $s['jenis'] === 'pretest' ? $r['pre'] : $r['post'], $sc['rows']), fn($v) => $v !== null));
                $o['avg'] = $vals ? round(array_sum($vals) / count($vals), 1) : null;
            }
            return $o;
        }, $segs);
        $d['scores'] = ['rows' => $sc['rows'], 'final' => $sc['final'], 'nSoal' => $sc['nSoal'], 'bobot' => $sc['bobot']];
        $d['predikat'] = bl_predikat_names($sc['predikat']);
    } elseif ($role === 'peserta' && !bl_me_peserta($u, $kid)) {
        $reg = bl_my_reg($u, $kid);
        $d = ['keg' => bl_out_keg($k), 'reg' => $reg ? bl_out_peserta($reg, false) : null,
            'self' => ['name' => $u['nama'], 'jabatan' => '', 'nip' => '', 'instansi' => (string)$u['instansi'], 'email' => (string)$u['email'], 'hp' => (string)$u['hp']]];
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
        // soal untuk segmen tes yang sudah dibuka (termasuk yang sudah ditutup) dan belum diselesaikan peserta
        $soal = bl_soal($kid); $d['tests'] = []; $d['myTest'] = [];
        foreach ($segs as $s) {
            if (!in_array($s['jenis'], ['pretest', 'posttest'], true)) continue;
            if (isset($done[(int)$s['id']])) { $d['myTest'][(int)$s['id']] = bl_nilai_tes($soal, (int)$s['id'], $pid); continue; }
            if (!bl_seg_bisa_diisi($k, $s) || bl_pre_terlambat($s, $segs, $done)) continue;
            $ans = []; foreach (qall('SELECT soal_id, pilih FROM bl_jawab WHERE peserta_id = ? AND seg_id = ?', [$pid, $s['id']]) as $j) $ans[(int)$j['soal_id']] = $j['pilih'];
            $d['tests'][(int)$s['id']] = ['soal' => array_map(fn($q) => ['id' => (int)$q['id'], 'text' => $q['teks'], 'a' => $q['a'], 'b' => $q['b'], 'c' => $q['c'], 'd' => $q['d']], bl_order_for($soal, $s, $pid)), 'answers' => (object)$ans];
        }
        $d['tests'] = (object)$d['tests']; $d['myTest'] = (object)$d['myTest'];
        $d['preBlocked'] = false; foreach ($segs as $s) if ($s['jenis'] === 'pretest' && !isset($done[(int)$s['id']]) && bl_pre_terlambat($s, $segs, $done)) $d['preBlocked'] = true;
        $ev = []; foreach (qall('SELECT * FROM bl_eval WHERE peserta_id = ?', [$pid]) as $e) $ev[(int)$e['narsum_id']][(int)$e['aspek']] = (int)$e['nilai'];
        $d['myEval'] = (object)array_map(fn($x) => (object)$x, $ev);
        $d['result'] = bl_my_result($k, $pid);
    }
    return $d;
}

/** Segmen dapat dikerjakan peserta: sudah dibuka (status buka atau tutup) dan kegiatan belum diarsipkan */
function bl_seg_bisa_diisi(array $k, array $s): bool { return $k['status'] !== 'arsip' && in_array($s['status'], ['buka', 'tutup'], true); }
/** Pretest tidak boleh dikerjakan setelah peserta menyelesaikan Post test (nilai Pre dan Progres menjadi tidak sahih) */
function bl_pre_terlambat(array $s, array $segs, array $done): bool
{
    if ($s['jenis'] !== 'pretest') return false;
    foreach ($segs as $x) if ($x['jenis'] === 'posttest' && isset($done[(int)$x['id']])) return true;
    return false;
}
/** Nilai tes satu segmen milik peserta (sama dengan rumus rekap: benar ÷ jumlah soal × 100) */
function bl_nilai_tes(array $soal, int $segId, int $pid): array
{
    $kunci = []; foreach ($soal as $q) $kunci[(int)$q['id']] = $q['kunci'];
    $benar = 0; foreach (qall('SELECT soal_id, pilih FROM bl_jawab WHERE peserta_id = ? AND seg_id = ?', [$pid, $segId]) as $j) if (($kunci[(int)$j['soal_id']] ?? null) === $j['pilih']) $benar++;
    $n = count($soal);
    return ['nilai' => bl_round($benar / max(1, $n) * 100), 'benar' => $benar, 'n' => $n];
}

/** Hasil peserta: tampil setelah peserta menyelesaikan semua segmen, atau setelah semua segmen ditutup */
function bl_my_result(array $k, int $pid): ?array
{
    $sc = bl_scores($k);
    $mine = null; foreach ($sc['rows'] as $r) if ($r['pesertaId'] === $pid) $mine = $r;
    if (!$mine || !($mine['allDone'] || $sc['final'])) return null;
    return ['mine' => $mine, 'n' => count($sc['rows']), 'final' => $sc['final'], 'predikat' => bl_predikat_names($sc['predikat'], $pid)];
}

/** Riwayat saya: semua pendaftaran peserta beserta status dan hasilnya */
function bl_riwayat(array $u): array
{
    $out = [];
    foreach (qall('SELECT p.*, k.judul, k.penyelenggara, k.tgl_mulai, k.tgl_selesai, k.status AS kstatus FROM bl_peserta p JOIN bl_keg k ON k.id = p.keg_id WHERE p.user_id = ? ORDER BY k.tgl_mulai DESC, p.id DESC', [$u['id']]) as $p) {
        $o = ['kegId' => (int)$p['keg_id'], 'title' => $p['judul'], 'org' => (string)$p['penyelenggara'], 'start' => (string)$p['tgl_mulai'], 'end' => (string)$p['tgl_selesai'], 'kegStatus' => $p['kstatus'],
            'status' => $p['status'], 'sentAt' => $p['kirim_at'], 'result' => null];
        if ($p['status'] === 'diterima') {
            $r = bl_my_result(bl_keg((int)$p['keg_id']), (int)$p['id']);
            if ($r) {
                $mine = $r['mine'];
                $pred = $r['predikat'] ? array_keys(array_filter($r['predikat'], fn($list) => (bool)array_filter($list, fn($x) => $x['me']))) : [];
                $o['result'] = ['pre' => $mine['pre'], 'post' => $mine['post'], 'progres' => $mine['progres'], 'complete' => $mine['complete'], 'rank' => $mine['rank'], 'n' => $r['n'], 'final' => $r['final'], 'predikat' => $pred];
            }
        }
        $out[] = $o;
    }
    return $out;
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

/* ---------- akun subdomain ---------- */
function bl_out_user(array $u): array
{
    return ['id' => (int)$u['id'], 'role' => $u['role'], 'name' => $u['nama'], 'username' => $u['username'], 'email' => (string)$u['email'], 'hp' => (string)$u['hp'], 'instansi' => (string)$u['instansi'],
        'active' => (bool)$u['aktif'], 'mustChange' => (bool)$u['must_change'], 'createdAt' => $u['created_at'], 'lastLogin' => $u['last_login']];
}
/** Status aplikasi subdomain */
function bl_app_state(array $u): array
{
    $S = ['me' => bl_out_user($u), 'settings' => ['tampilan' => setting('tampilan', [])], 'bl' => bl_state($u), 'bobot' => BL_BOBOT];
    if ($u['role'] === 'admin') $S['users'] = array_map('bl_out_user', qall('SELECT * FROM bl_users ORDER BY role, nama, id'));
    return $S;
}
function bl_valid_email(string $e): bool { return (bool)filter_var($e, FILTER_VALIDATE_EMAIL); }
function bl_valid_hp(string $h): bool { return (bool)preg_match('/^\+?[0-9][0-9 \-]{7,19}$/', $h); }

/** Baris pendaftaran milik akun peserta ini (status apa pun) */
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
    foreach (array_keys($assigned) as $id) if (!isset($active[$id])) q("UPDATE bl_users SET aktif = 0 WHERE id = ? AND role = 'fasil'", [$id]);
}

/* ---------- hapus peserta (Admin) ---------- */
const BL_ALASAN_MIN = 10;
/** Hapus baris pendaftaran beserta jawaban, penyelesaian, nilai, dan evaluasinya; berkas surat dikembalikan untuk dihapus setelah commit */
function bl_hapus_pendaftaran(array $rows): array
{
    $files = [];
    foreach ($rows as $p) {
        foreach (['bl_jawab', 'bl_selesai', 'bl_nilai', 'bl_eval'] as $tb) q("DELETE FROM $tb WHERE peserta_id = ?", [$p['id']]);
        q('DELETE FROM bl_peserta WHERE id = ?', [$p['id']]);
        if ($p['surat']) $files[] = data_dir('surat') . '/' . basename($p['surat']);
    }
    return $files;
}
/** Salinan identitas peserta untuk audit trail */
function bl_hapus_identitas(array $u, ?array $p = null): array
{
    return ['nama' => (string)($p['nama'] ?? $u['nama']), 'username' => (string)$u['username'], 'instansi' => (string)($p['instansi'] ?? $u['instansi']), 'email' => (string)($p['email'] ?? $u['email']), 'hp' => (string)($p['hp'] ?? $u['hp'])];
}
/** Jalankan penghapusan dalam satu transaksi; berkas dihapus setelah commit */
function bl_hapus_tx(callable $fn): void
{
    sp_db()->beginTransaction();
    try { $files = $fn(); sp_db()->commit(); } catch (Throwable $e) { if (sp_db()->inTransaction()) sp_db()->rollBack(); throw $e; }
    foreach ($files as $f) @unlink($f);
}
/** Catatan penghapusan peserta (menu Audit trail) */
function bl_audit_hapus(): array
{
    $out = [];
    foreach (qall("SELECT * FROM bl_audit WHERE action IN ('bl_hapus_akun', 'bl_hapus_peserta') ORDER BY id DESC LIMIT 1000") as $r) {
        $x = (array)jdec($r['detail']);
        $out[] = ['at' => $r['at'], 'jenis' => $r['action'] === 'bl_hapus_akun' ? 'akun' : 'kegiatan', 'oleh' => (string)($x['oleh'] ?? ''), 'ip' => (string)$r['ip'],
            'peserta' => (array)($x['peserta'] ?? []), 'keg' => array_values((array)($x['keg'] ?? [])), 'alasan' => (string)($x['alasan'] ?? '')];
    }
    return $out;
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
            $kuota = trim((string)($d['kuota'] ?? ''));
            need(ctype_digit($kuota) && (int)$kuota >= 1 && (int)$kuota <= 10000, 'bl.galat.kuota', 400);
            $id = sp_insert('bl_keg', ['judul' => $judul, 'penyelenggara' => str_in($d, 'org', 200), 'kuota' => (int)$kuota, 'status' => 'draf', 'daftar_buka' => 0, 'pembuka' => '[]', 'fasil' => '[]', 'pengawas' => '[]', 'created_at' => now_str()]);
            foreach (['pretest', 'materi', 'posttest', 'penutup'] as $i => $j)
                sp_insert('bl_seg', ['keg_id' => $id, 'urut' => $i + 1, 'jenis' => $j, 'judul' => t('bl.seg_judul.' . $j, ['n' => 1]), 'sesi' => '[]', 'status' => 'belum']);
            audit('bl_keg_add', ['id' => $id]);
            return ['id' => $id];
        case 'bl_keg_update':
            need($isA);
            $k = $kegOf(int_in($d, 'id')); $notArsip($k);
            $map = ['title' => 'judul', 'org' => 'penyelenggara', 'place' => 'lokasi', 'start' => 'tgl_mulai', 'end' => 'tgl_selesai', 'desc' => 'deskripsi'];
            $kk = (string)($d['k'] ?? '');
            if ($kk === 'kuota') {
                $v = trim((string)($d['v'] ?? ''));
                need(ctype_digit($v) && (int)$v >= 1 && (int)$v <= 10000, 'bl.galat.kuota', 400);
                need((int)$v >= (int)qval("SELECT COUNT(*) FROM bl_peserta WHERE keg_id = ? AND status = 'diterima'", [$k['id']]), 'bl.galat.kuota_kurang', 400);
                q('UPDATE bl_keg SET kuota = ? WHERE id = ?', [(int)$v, $k['id']]);
                audit('bl_keg_kuota', ['id' => $k['id'], 'v' => (int)$v]);
                return null;
            }
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
            need(in_array($kk, ['fasil', 'pembuka', 'pengawas'], true), 'galat.data_tidak_valid', 400);
            $ids = array_values(array_unique(array_map('intval', (array)($d['ids'] ?? []))));
            $roles = ['fasil' => ['fasil'], 'pembuka' => ['pembina', 'fasil'], 'pengawas' => ['pengawas']][$kk];
            foreach ($ids as $i) need(in_array((string)qval('SELECT role FROM bl_users WHERE id = ?', [$i]), $roles, true), 'galat.data_tidak_valid', 400);
            q("UPDATE bl_keg SET $kk = ? WHERE id = ?", [jenc($ids), $k['id']]);
            if ($kk === 'fasil') foreach ($ids as $i) q("UPDATE bl_users SET aktif = 1 WHERE id = ? AND role = 'fasil'", [$i]);
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
            elseif ($kk === 'picId') { $v = int_in($d, 'v') ?: null; need($v === null || in_array((string)qval('SELECT role FROM bl_users WHERE id = ?', [$v]), ['pembina', 'fasil'], true), 'galat.data_tidak_valid', 400); }
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
                if (in_array($s['jenis'], ['pretest', 'posttest'], true)) { $ns = (int)qval('SELECT COUNT(*) FROM bl_soal WHERE keg_id = ?', [$k['id']]); need($ns >= BL_NSOAL_MIN && $ns <= BL_NSOAL, 'bl.galat.soal_kurang', 400); }
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
            audit('bl_verif', ['peserta' => $p['id'], 'v' => $v]);
            return $res;

        /* --- Admin: hapus peserta dari satu kegiatan (akun tetap) --- */
        case 'bl_pes_del':
            need($isA);
            $p = q1('SELECT * FROM bl_peserta WHERE id = ?', [int_in($d, 'id')]);
            need((bool)$p, 'bl.galat.tidak_ada', 404);
            $k = $kegOf((int)$p['keg_id']); $notArsip($k);
            $why = str_in($d, 'alasan', 1000);
            need(mb_strlen($why) >= BL_ALASAN_MIN, 'bl.galat.alasan_hapus', 400);
            $pu = q1('SELECT * FROM bl_users WHERE id = ?', [$p['user_id']]) ?: ['nama' => $p['nama'], 'username' => '', 'instansi' => '', 'email' => '', 'hp' => ''];
            // penghapusan dan catatan audit berada dalam satu transaksi: tidak ada penghapusan tanpa jejak
            bl_hapus_tx(function () use ($p, $pu, $k, $u, $why) {
                $files = bl_hapus_pendaftaran([$p]);
                audit('bl_hapus_peserta', ['oleh' => $u['nama'], 'peserta' => bl_hapus_identitas($pu, $p), 'user_id' => (int)$p['user_id'],
                    'keg' => [['id' => (int)$k['id'], 'judul' => $k['judul'], 'status' => $p['status']]], 'alasan' => $why]);
                return $files;
            });
            return null;

        /* --- Peserta: mendaftarkan diri pada kegiatan --- */
        case 'bl_daftar':
        case 'bl_kirim_ulang':
            need($role === 'peserta');
            $k = $kegOf(int_in($d, 'kegId')); $notArsip($k);
            $f = ['nama' => str_in($d, 'name', 200), 'jabatan' => str_in($d, 'jabatan', 150), 'nip' => str_in($d, 'nip', 60), 'instansi' => str_in($d, 'instansi', 200), 'email' => str_in($d, 'email', 150), 'hp' => str_in($d, 'hp', 30)];
            foreach ($f as $v) need($v !== '', 'bl.galat.lengkapi_data', 400);
            need(bl_valid_email($f['email']), 'bl.galat.email', 400);
            need(bl_valid_hp($f['hp']), 'bl.galat.hp', 400);
            if ($a === 'bl_daftar') {
                // pemeriksaan kuota dan pendaftaran dikunci bersama agar kiriman bersamaan tidak melampaui kuota
                sp_db()->beginTransaction();
                try {
                    $k = sp_driver() === 'mysql' ? q1('SELECT * FROM bl_keg WHERE id = ? FOR UPDATE', [$k['id']]) : bl_keg((int)$k['id']);
                    need($k['status'] === 'terbit' && $k['daftar_buka'], 'bl.galat.pendaftaran_tutup', 400);
                    need(bl_terisi((int)$k['id']) < (int)$k['kuota'], 'bl.galat.kuota_penuh', 400);
                    need(!bl_my_reg($u, (int)$k['id']), 'bl.galat.sudah_terdaftar', 400);
                    [$file] = bl_pdf_upload('surat', BL_SURAT_MAX, 'surat');
                    sp_insert('bl_peserta', $f + ['keg_id' => $k['id'], 'user_id' => $u['id'], 'surat' => $file, 'status' => 'menunggu', 'kirim_at' => now_str()]);
                    sp_db()->commit();
                } catch (Throwable $e) { if (sp_db()->inTransaction()) sp_db()->rollBack(); throw $e; }
                audit('bl_daftar', ['keg' => $k['id']]);
                return null;
            }
            $p = bl_my_reg($u, (int)$k['id']);
            need($p && $p['status'] === 'lengkapi', 'bl.galat.tidak_bisa_kirim_ulang', 400);
            $file = $p['surat'];
            if (isset($_FILES['surat']) && $_FILES['surat']['error'] !== UPLOAD_ERR_NO_FILE) [$file] = bl_pdf_upload('surat', BL_SURAT_MAX, 'surat');
            q("UPDATE bl_peserta SET nama = ?, jabatan = ?, nip = ?, instansi = ?, email = ?, hp = ?, surat = ?, status = 'menunggu', kirim_at = ? WHERE id = ?", [$f['nama'], $f['jabatan'], $f['nip'], $f['instansi'], $f['email'], $f['hp'], $file, now_str(), $p['id']]);
            audit('bl_kirim_ulang', ['peserta' => $p['id']]);
            return null;
        case 'bl_riwayat':
            need($role === 'peserta');
            return bl_riwayat($u);

        /* --- Fasilitator: Laporan Kegiatan --- */
        case 'bl_lap_list':
            bl_veridoc_antrean();
            if ($role === 'fasil') {
                return array_values(array_map(function ($k) use ($u) {
                    $r = q1('SELECT * FROM bl_laporan WHERE keg_id = ? AND user_id = ?', [$k['id'], $u['id']]);
                    return ['id' => (int)$k['id'], 'title' => $k['judul'], 'start' => (string)$k['tgl_mulai'], 'end' => (string)$k['tgl_selesai'], 'status' => $k['status'], 'updated' => $r['updated_at'] ?? null,
                        'lapStatus' => bl_lap_status_tampil($r)];
                }, array_filter(qall('SELECT * FROM bl_keg ORDER BY id DESC'), fn($k) => bl_lap_can($u, $k))));
            }
            // Pembina (laporan yang diajukan kepadanya / yang ia sahkan) dan Admin (semua yang sudah diajukan atau terbit)
            need($role === 'pembina' || $isA);
            $out = [];
            foreach (qall('SELECT l.*, k.judul, k.tgl_mulai, k.tgl_selesai, k.status AS keg_status, u.nama AS fasil_nama FROM bl_laporan l JOIN bl_keg k ON k.id = l.keg_id LEFT JOIN bl_users u ON u.id = l.user_id ORDER BY l.id DESC') as $r) {
                $k = bl_keg((int)$r['keg_id']);
                if (!bl_lap_hak($u, $r, $k)['lihat'] || ($isA && $r['status'] === 'draf' && !bl_dok_terakhir((int)$r['id']))) continue;
                $out[] = ['lapId' => (int)$r['id'], 'title' => $r['judul'], 'start' => (string)$r['tgl_mulai'], 'end' => (string)$r['tgl_selesai'], 'fasil' => (string)$r['fasil_nama'],
                    'updated' => $r['updated_at'], 'lapStatus' => bl_lap_status_tampil($r)];
            }
            return $out;
        case 'bl_lap_get':
        case 'bl_lap_save':
        case 'bl_lap_bab':
        case 'bl_lap_foto':
        case 'bl_lap_lamp':
        case 'bl_lap_lamp_unggah':
        case 'bl_lap_tabel':
        case 'bl_lap_tor':
        case 'bl_lap_terbit':
        case 'bl_lap_sahkan':
        case 'bl_lap_kembali':
        case 'bl_lap_cabut':
        case 'bl_lap_ganti':
            if (!empty($d['lapId'])) {
                $r = bl_lap_by_id(int_in($d, 'lapId'));
                need((bool)$r, 'bl.galat.tidak_ada', 404);
                $k = $kegOf((int)$r['keg_id']);
            } else {
                $k = $kegOf(int_in($d, 'kegId'));
                need(bl_lap_can($u, $k));
                $r = bl_lap_row($u, $k);
            }
            $hak = bl_lap_hak($u, $r, $k);
            need($hak['lihat']);
            if ($a === 'bl_lap_tabel') return bl_lap_tabel($k, $d);
            if ($a === 'bl_lap_save') {
                need($hak['sunting']);
                $kk = (string)($d['k'] ?? '');
                $sampulKeys = ['judulLaporan' => 300, 'judulKegiatan' => 300, 'nama' => 200, 'jabatan' => 200];
                if (isset($sampulKeys[$kk])) {
                    $v = trim(preg_replace('/\s+/u', ' ', (string)($d['v'] ?? '')));
                    need(mb_strlen($v) <= $sampulKeys[$kk], 'lap.galat.terlalu_panjang', 400);
                    $S = bl_lap_struktur($r, $k);
                    $S['sampul'][$kk] = $v;
                    bl_lap_simpan($r, $S);
                    return bl_lap_out($u, bl_lap_by_id((int)$r['id']), $k);
                }
                $map = ['ttdTempat' => ['ttd_tempat', 100], 'ttdTanggal' => ['ttd_tanggal', 10], 'pembinaId' => ['pembina_id', 0],
                    'kendali' => ['kendali', 0], 'berlakuMode' => ['berlaku_mode', 0], 'berlakuSampai' => ['berlaku_sampai', 10]];
                need(isset($map[$kk]), 'galat.data_tidak_valid', 400);
                [$col, $max] = $map[$kk];
                if ($kk === 'pembinaId') {
                    $v = (int)($d['v'] ?? 0) ?: null;
                    need($v === null || qval("SELECT role FROM bl_users WHERE id = ?", [$v]) === 'pembina', 'galat.data_tidak_valid', 400);
                } elseif ($kk === 'kendali' || $kk === 'berlakuMode') {
                    $v = (string)($d['v'] ?? '') ?: null;
                    need($v === null || in_array($v, $kk === 'kendali' ? ['terkendali', 'tidak'] : ['tanggal', 'cabut'], true), 'galat.data_tidak_valid', 400);
                } elseif ($kk === 'ttdTanggal' || $kk === 'berlakuSampai') {
                    $v = trim((string)($d['v'] ?? ''));
                    need($v === '' || (bool)DateTimeImmutable::createFromFormat('Y-m-d', $v), 'galat.data_tidak_valid', 400);
                    if ($v === '') $v = null;
                } else {
                    $v = trim(str_replace("\r\n", "\n", (string)($d['v'] ?? '')));
                    need(mb_strlen($v) <= $max, 'lap.galat.terlalu_panjang', 400);
                }
                q("UPDATE bl_laporan SET $col = ?, updated_at = ? WHERE id = ?", [$v, now_str(), $r['id']]);
            } elseif ($a === 'bl_lap_bab') {
                // bab & sub-bab: isi (HTML dibersihkan), tambah, ubah nama, hapus sub-bab
                need($hak['sunting']);
                $S = bl_lap_struktur($r, $k);
                $bi = (int)($d['bab'] ?? -1);
                need(isset($S['bab'][$bi]), 'galat.data_tidak_valid', 400);
                $op = (string)($d['op'] ?? '');
                $sid = (string)($d['sub'] ?? '');
                $si = null;
                foreach ($S['bab'][$bi]['sub'] as $i => $x) if ($x['id'] === $sid) $si = $i;
                $judul = trim(preg_replace('/\s+/u', ' ', (string)($d['judul'] ?? '')));
                if ($op === 'isi') {
                    $raw = (string)($d['html'] ?? '');
                    need(strlen($raw) <= BL_RT_MAX * 3, 'lap.galat.terlalu_panjang', 400);
                    $h = bl_rt_clean($raw);
                    need(strlen($h) <= BL_RT_MAX, 'lap.galat.terlalu_panjang', 400);
                    if ($sid === '') $S['bab'][$bi]['html'] = $h;
                    else { need($si !== null, 'galat.data_tidak_valid', 400); $S['bab'][$bi]['sub'][$si]['html'] = $h; }
                } elseif ($op === 'tambah') {
                    need($judul !== '' && mb_strlen($judul) <= 200 && count($S['bab'][$bi]['sub']) < 30, 'galat.data_tidak_valid', 400);
                    $S['bab'][$bi]['sub'][] = ['id' => bl_lap_uid(), 'judul' => $judul, 'html' => ''];
                } elseif ($op === 'nama') {
                    need($si !== null && $judul !== '' && mb_strlen($judul) <= 200, 'galat.data_tidak_valid', 400);
                    $S['bab'][$bi]['sub'][$si]['judul'] = $judul;
                } elseif ($op === 'hapus') {
                    need($si !== null, 'galat.data_tidak_valid', 400);
                    array_splice($S['bab'][$bi]['sub'], $si, 1);
                } else fail('galat.data_tidak_valid', [], 400);
                bl_lap_simpan($r, $S);
            } elseif ($a === 'bl_lap_foto') {
                need($hak['sunting']);
                $S = bl_lap_struktur($r, $k);
                $lama = (string)($S['sampul']['foto'] ?? '');
                if (!empty($d['hapus'])) $S['sampul']['foto'] = null;
                else {
                    need(isset($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name']), 'galat.unggah', 400);
                    need($_FILES['file']['size'] <= BL_LAP_FILE_MAX, 'lap.galat.file_besar', 400);
                    [$file] = bl_lap_simpan_gambar((string)file_get_contents($_FILES['file']['tmp_name']), (int)$r['id'], 2400);
                    $S['sampul']['foto'] = $file;
                }
                bl_lap_simpan($r, $S);
                bl_lap_hapus_berkas($r, $lama);
            } elseif ($a === 'bl_lap_lamp') {
                // lampiran: tambah, ubah judul, hapus lampiran, hapus satu file
                need($hak['sunting']);
                $S = bl_lap_struktur($r, $k);
                $op = (string)($d['op'] ?? '');
                $id = (string)($d['id'] ?? '');
                $li = null;
                foreach ($S['lampiran'] as $i => $x) if ($x['id'] === $id) $li = $i;
                $judul = trim(preg_replace('/\s+/u', ' ', (string)($d['judul'] ?? '')));
                $buang = [];
                if ($op === 'tambah') {
                    need($judul !== '' && mb_strlen($judul) <= 300 && count($S['lampiran']) < 30, 'galat.data_tidak_valid', 400);
                    $S['lampiran'][] = ['id' => bl_lap_uid(), 'judul' => $judul, 'files' => []];
                } elseif ($op === 'judul') {
                    need($li !== null && $judul !== '' && mb_strlen($judul) <= 300, 'galat.data_tidak_valid', 400);
                    $S['lampiran'][$li]['judul'] = $judul;
                } elseif ($op === 'hapus') {
                    need($li !== null, 'galat.data_tidak_valid', 400);
                    foreach ($S['lampiran'][$li]['files'] as $f) foreach ($f['hal'] as $h) $buang[] = $h['f'];
                    array_splice($S['lampiran'], $li, 1);
                } elseif ($op === 'hapusFile') {
                    need($li !== null, 'galat.data_tidak_valid', 400);
                    $fid = (string)($d['file'] ?? '');
                    $S['lampiran'][$li]['files'] = array_values(array_filter($S['lampiran'][$li]['files'], function ($f) use ($fid, &$buang) {
                        if ($f['id'] !== $fid) return true;
                        foreach ($f['hal'] as $h) $buang[] = $h['f'];
                        return false;
                    }));
                } else fail('galat.data_tidak_valid', [], 400);
                bl_lap_simpan($r, $S);
                foreach ($buang as $f) bl_lap_hapus_berkas($r, $f);
            } elseif ($a === 'bl_lap_lamp_unggah') {
                // satu gambar per permintaan: foto (jenis img) atau satu halaman PDF yang sudah diubah menjadi gambar di browser (jenis pdf)
                need($hak['sunting']);
                need(isset($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name']), 'galat.unggah', 400);
                need($_FILES['file']['size'] <= BL_LAP_FILE_MAX, 'lap.galat.file_besar', 400);
                $S = bl_lap_struktur($r, $k);
                $li = null;
                foreach ($S['lampiran'] as $i => $x) if ($x['id'] === (string)($d['id'] ?? '')) $li = $i;
                need($li !== null, 'galat.data_tidak_valid', 400);
                $total = 0;
                foreach ($S['lampiran'] as $x) foreach ($x['files'] as $f) $total += count($f['hal']);
                need($total < BL_LAP_GAMBAR_MAX, 'galat.data_tidak_valid', 400);
                $jenis = ($d['jenis'] ?? '') === 'pdf' ? 'pdf' : 'img';
                [$file, $w, $h] = bl_lap_simpan_gambar((string)file_get_contents($_FILES['file']['tmp_name']), (int)$r['id'], $jenis === 'pdf' ? 2000 : 2400);
                $fid = (string)($d['fileId'] ?? '');
                $fi = null;
                foreach ($S['lampiran'][$li]['files'] as $i => $f) if ($f['id'] === $fid) $fi = $i;
                if ($fi === null) {
                    $nama = trim(preg_replace('/[\x00-\x1F]/u', '', (string)($d['nama'] ?? ''))) ?: 'berkas';
                    $S['lampiran'][$li]['files'][] = ['id' => $fid !== '' ? substr(preg_replace('/[^a-z0-9]/', '', $fid), 0, 16) : bl_lap_uid(), 'nama' => mb_substr($nama, 0, 200), 'jenis' => $jenis, 'hal' => []];
                    $fi = count($S['lampiran'][$li]['files']) - 1;
                }
                $S['lampiran'][$li]['files'][$fi]['hal'][] = ['f' => $file, 'w' => $w, 'h' => $h];
                bl_lap_simpan($r, $S);
            } elseif ($a === 'bl_lap_tor') {
                need($hak['sunting']);
                need(isset($_FILES['tor']) && is_uploaded_file($_FILES['tor']['tmp_name']), 'galat.unggah', 400);
                need($_FILES['tor']['size'] <= BL_TOR_MAX, 'lap.galat.tor_besar', 400);
                $tmp = $_FILES['tor']['tmp_name'];
                $zip = new ZipArchive();
                $okDocx = $zip->open($tmp) === true && $zip->locateName('word/document.xml') !== false;
                if ($okDocx) $zip->close();
                need($okDocx, 'lap.galat.bukan_docx', 400);
                $file = 'tor_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.docx';
                copy($tmp, data_dir('tor') . '/' . $file);
                $txt = bl_tor_pendahuluan(data_dir('tor') . '/' . $file);
                q('UPDATE bl_laporan SET tor = ?, updated_at = ? WHERE id = ?', [$file, now_str(), $r['id']]);
                if ($txt !== '') {
                    // isi TOR masuk ke sub-bab pertama bab A (Latar Belakang), atau ke bab A bila sub-bab sudah dihapus
                    $S = bl_lap_struktur(bl_lap_by_id((int)$r['id']), $k);
                    if (isset($S['bab'][0]['sub'][0])) $S['bab'][0]['sub'][0]['html'] = bl_rt_dari_teks($txt);
                    else $S['bab'][0]['html'] = bl_rt_dari_teks($txt);
                    bl_lap_simpan($r, $S);
                }
                audit('bl_lap_tor', ['keg' => $k['id'], 'found' => $txt !== '']);
                return ['found' => $txt !== '', 'lap' => bl_lap_out($u, bl_lap_by_id((int)$r['id']), $k)];
            } elseif ($a === 'bl_lap_terbit') {
                // Fasilitator (atau Admin untuk draf pengganti setelah arsip) mengajukan laporan kepada Pembina terpilih
                need($hak['sunting']);
                need($r['pembina_id'] && qval("SELECT COUNT(*) FROM bl_users WHERE id = ? AND role = 'pembina' AND aktif = 1", [$r['pembina_id']]), 'galat.data_tidak_valid', 400);
                need(in_array($r['kendali'], ['terkendali', 'tidak'], true) && in_array($r['berlaku_mode'], ['tanggal', 'cabut'], true), 'galat.data_tidak_valid', 400);
                need($r['berlaku_mode'] === 'cabut' || ($r['berlaku_sampai'] && $r['berlaku_sampai'] >= bl_hari_ini()), 'galat.data_tidak_valid', 400);
                q("UPDATE bl_laporan SET status = 'menunggu', diajukan_at = ?, catatan_kembali = NULL, updated_at = ? WHERE id = ?", [now_str(), now_str(), $r['id']]);
                audit('bl_lap_terbit', ['lap' => (int)$r['id'], 'pembina' => (int)$r['pembina_id']]);
            } elseif ($a === 'bl_lap_kembali') {
                need($hak['sahkan']);
                $cat = trim(str_in($d, 'catatan', 2000));
                need($cat !== '', 'galat.data_tidak_valid', 400);
                q("UPDATE bl_laporan SET status = 'draf', catatan_kembali = ?, updated_at = ? WHERE id = ?", [$cat, now_str(), $r['id']]);
                audit('bl_lap_kembali', ['lap' => (int)$r['id'], 'catatan' => $cat]);
            } elseif ($a === 'bl_lap_sahkan') {
                need($hak['sahkan']);
                // masa berlaku yang sudah lewat tidak boleh disahkan
                need($r['berlaku_mode'] === 'cabut' || ($r['berlaku_sampai'] && $r['berlaku_sampai'] >= bl_hari_ini()), 'galat.data_tidak_valid', 400);
                bl_lap_sahkan($u, $r, $k);
            } elseif ($a === 'bl_lap_cabut' || $a === 'bl_lap_ganti') {
                need($hak['ubahDok']);
                $why = trim(str_in($d, 'alasan', 1000));
                need(mb_strlen($why) >= BL_ALASAN_DOK_MIN, 'galat.data_tidak_valid', 400);
                $dok = bl_dok_terakhir((int)$r['id']);
                if ($a === 'bl_lap_cabut') bl_dok_cabut($u, $dok, $why);
                else {
                    // draf pengganti: dokumen lama tetap berlaku sampai pengganti disahkan
                    q("UPDATE bl_laporan SET status = 'draf', ganti_dok_id = ?, catatan_kembali = NULL, updated_at = ? WHERE id = ?", [$dok['id'], now_str(), $r['id']]);
                    audit('bl_dok_ganti', ['dok' => (int)$dok['id'], 'nomor' => $dok['nomor'], 'alasan' => $why]);
                }
            }
            return bl_lap_out($u, bl_lap_by_id((int)$r['id']), $k);
        case 'bl_jenis_get':
            need($isA);
            return bl_jenis_out(bl_jenis(BL_JENIS_LAPORAN));
        case 'bl_jenis_save':
            need($isA);
            $j = bl_jenis(BL_JENIS_LAPORAN);
            $kk = (string)($d['k'] ?? '');
            if ($kk === 'sifat') { $v = (string)($d['v'] ?? ''); need(in_array($v, ['rahasia', 'umum'], true), 'galat.data_tidak_valid', 400); $col = 'sifat'; }
            elseif ($kk === 'format') { $v = trim(str_in($d, 'v', 120)); need(str_contains($v, '{urut}'), 'galat.data_tidak_valid', 400); $col = 'format'; }
            elseif ($kk === 'next') { $v = (int)($d['v'] ?? 0); need($v >= 1, 'galat.data_tidak_valid', 400); $col = 'next_no'; }
            elseif ($kk === 'penerbit') { $v = trim(str_in($d, 'v', 200)); need($v !== '', 'galat.data_tidak_valid', 400); $col = 'penerbit'; }
            else fail('galat.data_tidak_valid', [], 400);
            q("UPDATE bl_jenis_dok SET $col = ?" . ($kk === 'next' ? ', tahun = ?' : '') . ' WHERE id = ?', $kk === 'next' ? [$v, (int)date('Y'), $j['id']] : [$v, $j['id']]);
            audit('bl_jenis_save', ['k' => $kk, 'v' => $v]);
            return bl_jenis_out(bl_jenis(BL_JENIS_LAPORAN));

        /* --- Admin: akun subdomain --- */
        case 'bl_user_add':
            need($isA);
            $r = (string)($d['role'] ?? '');
            need(in_array($r, ['pembina', 'fasil', 'pengawas'], true), 'galat.data_tidak_valid', 400);
            $uname = strtolower(str_in($d, 'username', 40)); $nama = str_in($d, 'name', 150);
            need((bool)preg_match('/^[a-z0-9._-]{3,40}$/', $uname), 'galat.username_format', 400);
            need($nama !== '', 'galat.isi_nama', 400);
            need(!qval('SELECT COUNT(*) FROM bl_users WHERE username = ?', [$uname]), 'galat.username_dipakai', 400);
            $pw = temp_password();
            $id = sp_insert('bl_users', ['role' => $r, 'nama' => $nama, 'username' => $uname, 'pass_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change' => 1, 'instansi' => str_in($d, 'instansi', 200), 'aktif' => 1, 'created_at' => now_str()]);
            audit('bl_user_add', ['id' => $id, 'role' => $r]);
            return ['id' => $id, 'username' => $uname, 'password' => $pw];
        case 'bl_user_update':
            need($isA);
            $t = q1('SELECT * FROM bl_users WHERE id = ?', [int_in($d, 'id')]);
            need((bool)$t, 'galat.data_tidak_valid', 400);
            $kk = (string)($d['k'] ?? ''); $v = str_in($d, 'v', 200);
            if ($kk === 'username') {
                $v = strtolower($v);
                need((bool)preg_match('/^[a-z0-9._-]{3,40}$/', $v), 'galat.username_format', 400);
                need(!qval('SELECT COUNT(*) FROM bl_users WHERE username = ? AND id <> ?', [$v, $t['id']]), 'galat.username_dipakai', 400);
            } elseif ($kk === 'nama') need($v !== '', 'galat.isi_nama', 400);
            elseif ($kk === 'email') need($v === '' || bl_valid_email($v), 'bl.galat.email', 400);
            elseif ($kk === 'hp') need($v === '' || bl_valid_hp($v), 'bl.galat.hp', 400);
            elseif ($kk !== 'instansi') fail('galat.data_tidak_valid');
            q("UPDATE bl_users SET $kk = ? WHERE id = ?", [$v, $t['id']]);
            audit('bl_user_update', ['id' => $t['id'], 'k' => $kk]);
            return null;
        case 'bl_user_active':
        case 'bl_user_reset':
            need($isA);
            $t = q1('SELECT * FROM bl_users WHERE id = ?', [int_in($d, 'id')]);
            need($t && $t['role'] !== 'admin', 'galat.data_tidak_valid', 400);
            if ($a === 'bl_user_active') {
                q('UPDATE bl_users SET aktif = ? WHERE id = ?', [!empty($d['v']) ? 1 : 0, $t['id']]);
                audit('bl_user_active', ['id' => $t['id'], 'v' => !empty($d['v'])]);
                return null;
            }
            $pw = temp_password();
            q('UPDATE bl_users SET pass_hash = ?, must_change = 1 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $t['id']]);
            audit('bl_user_reset', ['id' => $t['id']]);
            return ['username' => $t['username'], 'password' => $pw];

        /* --- Admin: hapus akun peserta beserta seluruh datanya --- */
        case 'bl_user_del':
            need($isA);
            $t = q1('SELECT * FROM bl_users WHERE id = ?', [int_in($d, 'id')]);
            need($t && $t['role'] === 'peserta', 'galat.data_tidak_valid', 400);
            $why = str_in($d, 'alasan', 1000);
            need(mb_strlen($why) >= BL_ALASAN_MIN, 'bl.galat.alasan_hapus', 400);
            $rows = qall('SELECT p.*, k.judul AS keg_judul, k.status AS keg_status FROM bl_peserta p JOIN bl_keg k ON k.id = p.keg_id WHERE p.user_id = ? ORDER BY p.id', [$t['id']]);
            foreach ($rows as $p) need($p['keg_status'] !== 'arsip', 'bl.galat.akun_arsip', 400);
            bl_hapus_tx(function () use ($rows, $t, $u, $why) {
                $files = bl_hapus_pendaftaran($rows);
                $pr = q1('SELECT foto FROM bl_profil WHERE user_id = ?', [$t['id']]);
                if ($pr && $pr['foto']) $files[] = data_dir('profil') . '/' . basename($pr['foto']);
                q('DELETE FROM bl_profil WHERE user_id = ?', [$t['id']]);
                q('DELETE FROM bl_users WHERE id = ?', [$t['id']]);
                audit('bl_hapus_akun', ['oleh' => $u['nama'], 'peserta' => bl_hapus_identitas($t), 'user_id' => (int)$t['id'],
                    'keg' => array_map(fn($p) => ['id' => (int)$p['keg_id'], 'judul' => $p['keg_judul'], 'status' => $p['status']], $rows), 'alasan' => $why]);
                return $files;
            });
            return null;
        case 'bl_audit_list':
            need($isA);
            return bl_audit_hapus();

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
            // segmen yang sudah ditutup Fasilitator tetap dapat diselesaikan peserta sampai kegiatan diarsipkan
            need($k['status'] !== 'arsip', 'bl.galat.arsip', 400);
            need(bl_seg_bisa_diisi($k, $s), 'bl.galat.segmen_belum', 400);
            need(!qval('SELECT COUNT(*) FROM bl_selesai WHERE peserta_id = ? AND seg_id = ?', [$me['id'], $s['id']]), 'bl.galat.sudah_selesai', 400);
            $myDone = []; foreach (qall('SELECT seg_id FROM bl_selesai WHERE peserta_id = ?', [$me['id']]) as $f) $myDone[(int)$f['seg_id']] = 1;
            need(!bl_pre_terlambat($s, bl_segs((int)$k['id']), $myDone), 'bl.galat.pretest_setelah_post', 400);
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
