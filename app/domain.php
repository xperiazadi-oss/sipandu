<?php
/* Si PANDU – logika bisnis: lembar QC, evaluasi, kasus, notifikasi, risiko, spesifikasi TEa */
// Tanpa strict_types: nilai dari basis data selalu berupa string dan dikonversi otomatis oleh PHP.
require_once __DIR__ . '/engine.php';

const SPEC_LEVELS = ['Optimum' => [0.25, 0.125], 'Desirable' => [0.5, 0.25], 'Minimum' => [0.75, 0.375]];

function num_or_null($v): ?float
{
    if ($v === null || $v === '') return null;
    if (is_int($v) || is_float($v)) return is_finite((float)$v) ? (float)$v : null;
    $s = str_replace(',', '.', trim((string)$v));
    return is_numeric($s) ? (float)$s : null;
}

/* ---------- Spesifikasi TEa ---------- */
function tea_spec(array $p): array
{
    if (($p['spec_src'] ?? 'lain') === 'bv') {
        $lv = isset(SPEC_LEVELS[$p['level'] ?? '']) ? $p['level'] : 'Desirable';
        $f = SPEC_LEVELS[$lv];
        $cvi = num_or_null($p['cvi'] ?? null); $cvg = num_or_null($p['cvg'] ?? null);
        if ($cvi === null || $cvg === null || $cvi < 0 || $cvg < 0) return ['tea' => null, 'src' => 'bv', 'level' => $lv];
        $cva = $f[0] * $cvi; $bias = $f[1] * sqrt($cvi * $cvi + $cvg * $cvg);
        return ['tea' => 1.65 * $cva + $bias, 'cva' => $cva, 'bias' => $bias, 'src' => 'bv', 'level' => $lv];
    }
    return ['tea' => num_or_null($p['tea'] ?? null), 'src' => 'lain'];
}

/* ---------- Lembar & evaluasi ---------- */
function sheet_row(int $id): ?array
{
    return q1('SELECT * FROM sheets WHERE id = ?', [$id]);
}
function runs_of(int $sheetId): array
{
    return qall('SELECT * FROM runs WHERE sheet_id = ? ORDER BY tgl, seq, id', [$sheetId]);
}
function engine_sheet(array $sh, array $runs): array
{
    $levels = jdec($sh['levels']);
    foreach ($levels as &$l) { $l['mean'] = num_or_null($l['mean'] ?? null); $l['sd'] = num_or_null($l['sd'] ?? null); }
    unset($l);
    $er = [];
    foreach ($runs as $r) {
        $vals = array_map(fn($v) => $v === null ? null : (float)$v, jdec($r['vals']));
        $er[] = ['id' => (int)$r['id'], 'date' => $r['tgl'], 'seq' => (int)$r['seq'], 'values' => $vals, 'excluded' => (bool)$r['excluded']];
    }
    return ['maxLevels' => (int)$sh['max_levels'], 'levels' => $levels, 'runs' => $er];
}
function rules_enabled(): array
{
    return array_merge(QCEngine::defaultEnabled(), setting('rules', []));
}

/** Evaluasi ulang satu lembar: simpan hasil per run, buka/batalkan kasus, kirim notifikasi */
function sheet_sync(int $sheetId, bool $silent = false): array
{
    $sh = sheet_row($sheetId);
    if (!$sh) return [];
    $runs = runs_of($sheetId);
    $ev = QCEngine::evaluate(engine_sheet($sh, $runs), rules_enabled());
    $old = [];
    foreach ($runs as $r) $old[(int)$r['id']] = $r['eval'];
    $pdo = sp_db();
    $pdo->beginTransaction();
    try {
        foreach ($ev as $e) {
            $rid = (int)$e['run']['id'];
            $store = ['status' => $e['status'], 'indication' => $e['indication'], 'note' => $e['note'], 'violations' => $e['violations'], 'trend' => $e['trend'], 'scope' => $e['scope'], 'complete' => $e['complete']];
            $js = jenc($store);
            if (($old[$rid] ?? null) !== $js) q('UPDATE runs SET eval = ? WHERE id = ?', [$js, $rid]);
            $viol = $e['status'] === 'TERTOLAK' || $e['status'] === 'PERINGATAN';
            $existing = q1("SELECT * FROM cases WHERE run_id = ? AND state <> 'Dibatalkan' ORDER BY id DESC LIMIT 1", [$rid]);
            if ($viol && !$existing) {
                $risk = risk_scores((int)$sh['puskesmas_id'], (int)$sh['alat_id']);
                $type = $e['status'] === 'TERTOLAK' ? 'Tertolak' : 'Peringatan';
                $cid = sp_insert('cases', [
                    'sheet_id' => $sheetId, 'run_id' => $rid, 'puskesmas_id' => (int)$sh['puskesmas_id'], 'run_date' => $e['run']['date'],
                    'type' => $type, 'indication' => $e['indication'], 'note' => $e['note'], 'violations' => jenc($e['violations']),
                    'scope' => jenc($e['scope']), 'priority' => jenc(QCEngine::priority($e['indication'], $risk)), 'mode' => null, 'state' => 'Terbuka',
                    'findings' => '[]', 'corrections' => '[]', 'log' => jenc([['at' => now_str(), 'by' => t('sistem.nama'), 'text' => t('kasus.log_dibuka')]]), 'created_at' => now_str(),
                ]);
                if (!$silent) {
                    $txt = t('notif.kasus_baru', ['jenis' => t('kasus.jenis.' . $type), 'param' => param_name((int)$sh['param_id']), 'tgl' => $e['run']['date'], 'indikasi' => $e['indication'], 'pkm' => pkm_name((int)$sh['puskesmas_id'])]);
                    notify('pembina', $txt, 'kasus-' . $cid);
                    notify('pkm:' . $sh['puskesmas_id'], $txt, 'kasus-' . $cid);
                }
            } elseif (!$viol && $existing && $existing['state'] !== 'Ditutup') {
                $log = jdec($existing['log']);
                $log[] = ['at' => now_str(), 'by' => t('sistem.nama'), 'text' => t('kasus.log_dibatalkan')];
                q("UPDATE cases SET state = 'Dibatalkan', log = ? WHERE id = ?", [jenc($log), $existing['id']]);
                if (!$silent) notify('pembina', t('notif.kasus_batal', ['id' => $existing['id'], 'pkm' => pkm_name((int)$sh['puskesmas_id'])]), 'kasus-' . $existing['id']);
            }
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return $ev;
}

function pkm_name(int $id): string
{
    return (string)(qval('SELECT nama FROM puskesmas WHERE id = ?', [$id]) ?? '?');
}
function param_name(int $id): string
{
    return (string)(qval('SELECT nama FROM params WHERE id = ?', [$id]) ?? '?');
}
function alat_name(int $id): string
{
    return (string)(qval('SELECT nama FROM alat WHERE id = ?', [$id]) ?? '?');
}
function matrix_name(array $sh): string
{
    return param_name((int)$sh['param_id']) . ' · ' . alat_name((int)$sh['alat_id']);
}

/* ---------- Notifikasi ---------- */
function notify(string $to, string $text, string $link = ''): void
{
    sp_insert('notifs', ['to_key' => $to, 'at' => now_str(), 'body' => $text, 'link' => $link]);
}
function notif_key_for(array $u): string
{
    return $u['role'] === 'binaan' ? 'pkm:' . $u['puskesmas_id'] : $u['role'];
}

/* ---------- Risiko ---------- */
function latest_risk(int $pkmId, string $aspect, ?int $alatId): ?array
{
    if ($aspect === 'alat') return q1('SELECT * FROM risks WHERE puskesmas_id = ? AND aspect = ? AND alat_id = ? ORDER BY tgl DESC, id DESC LIMIT 1', [$pkmId, $aspect, $alatId]);
    return q1('SELECT * FROM risks WHERE puskesmas_id = ? AND aspect = ? ORDER BY tgl DESC, id DESC LIMIT 1', [$pkmId, $aspect]);
}
function risk_max(?array $r): ?int
{
    if (!$r) return null;
    $m = 0;
    foreach (jdec($r['items']) as $it) $m = max($m, (int)($it['L'] ?? 0) * (int)($it['S'] ?? 0));
    return $m;
}
function risk_scores(int $pkmId, int $alatId): array
{
    $o = [];
    foreach (QCEngine::ASPECTS as $a) $o[$a] = risk_max(latest_risk($pkmId, $a, $a === 'alat' ? $alatId : null));
    return $o;
}

/* ---------- Kasus ---------- */
function case_log_add(array $c, string $text, string $by): void
{
    $log = jdec($c['log']);
    $log[] = ['at' => now_str(), 'by' => $by, 'text' => $text];
    q('UPDATE cases SET log = ? WHERE id = ?', [jenc($log), $c['id']]);
    notify('pembina', t('notif.kasus_langkah', ['id' => $c['id'], 'pkm' => pkm_name((int)$c['puskesmas_id']), 'teks' => $text]), 'kasus-' . $c['id']);
}

/* ---------- Bentuk data untuk klien ---------- */
function out_sheet(array $sh): array
{
    return [
        'id' => (int)$sh['id'], 'pkmId' => (int)$sh['puskesmas_id'], 'paramId' => (int)$sh['param_id'], 'alatId' => (int)$sh['alat_id'], 'cat' => $sh['kategori'],
        'maxLevels' => (int)$sh['max_levels'], 'levels' => jdec($sh['levels']), 'method' => $sh['metode'], 'reagen' => $sh['reagen'], 'reagenLot' => $sh['reagen_lot'],
        'unit' => $sh['satuan'], 'status' => $sh['status'], 'createdAt' => $sh['created_at'], 'closedAt' => $sh['closed_at'], 'closeReason' => $sh['close_reason'],
        'prevId' => $sh['prev_id'] === null ? null : (int)$sh['prev_id'], 'reagenEvents' => jdec($sh['reagen_events']),
        'reagenBhpId' => ($sh['reagen_bhp_id'] ?? null) === null ? null : (int)$sh['reagen_bhp_id'],
    ];
}
function out_run(array $r): array
{
    return ['id' => (int)$r['id'], 'date' => $r['tgl'], 'seq' => (int)$r['seq'], 'values' => array_map(fn($v) => $v === null ? null : (float)$v, jdec($r['vals'])), 'excluded' => (bool)$r['excluded'], 'reason' => (string)$r['reason'], 'note' => (string)$r['note'], 'by' => (string)$r['by_name']];
}
function out_case(array $c): array
{
    return [
        'id' => (int)$c['id'], 'sheetId' => (int)$c['sheet_id'], 'runId' => (int)$c['run_id'], 'runDate' => $c['run_date'], 'pkmId' => (int)$c['puskesmas_id'],
        'type' => $c['type'], 'indication' => $c['indication'], 'note' => (string)$c['note'], 'violations' => jdec($c['violations']), 'scope' => jdec($c['scope']),
        'priority' => jdec($c['priority']), 'mode' => $c['mode'], 'state' => $c['state'], 'findings' => jdec($c['findings']), 'corrections' => jdec($c['corrections']),
        'verifyRunId' => $c['verify_run_id'] === null ? null : (int)$c['verify_run_id'], 'verifyDate' => $c['verify_date'], 'log' => jdec($c['log']),
    ];
}
function out_param(array $p): array
{
    return ['id' => (int)$p['id'], 'name' => $p['nama'], 'cat' => $p['kategori'], 'unit' => (string)$p['satuan'], 'specSrc' => $p['spec_src'], 'level' => $p['level'],
        'cvi' => $p['cvi'] === null ? null : (float)$p['cvi'], 'cvg' => $p['cvg'] === null ? null : (float)$p['cvg'], 'bvSrc' => (string)$p['bv_src'],
        'tea' => $p['tea'] === null ? null : (float)$p['tea'], 'teaSrc' => (string)$p['tea_src'], 'teaOK' => (bool)$p['tea_ok']];
}
function out_user(array $u): array
{
    return ['id' => (int)$u['id'], 'role' => $u['role'], 'name' => $u['nama'], 'username' => $u['username'], 'pkmId' => $u['puskesmas_id'] === null ? null : (int)$u['puskesmas_id'],
        'nip' => (string)$u['nip'], 'jabatan' => (string)$u['jabatan'], 'active' => (bool)$u['aktif'], 'mustChange' => (bool)$u['must_change'], 'lastLogin' => $u['last_login']];
}
function out_risk(array $r): array
{
    return ['id' => (int)$r['id'], 'pkmId' => (int)$r['puskesmas_id'], 'aspect' => $r['aspect'], 'alatId' => $r['alat_id'] === null ? null : (int)$r['alat_id'], 'date' => $r['tgl'], 'by' => (string)$r['by_name'], 'items' => jdec($r['items'])];
}

/** Ringkasan per lembar: jumlah run, status run terakhir, tertolak bulan berjalan */
function sheet_summaries(array $sheetIds): array
{
    if (!$sheetIds) return [];
    $out = [];
    foreach ($sheetIds as $id) $out[$id] = ['runCount' => 0, 'lastStatus' => null, 'lastDate' => null, 'rejMonth' => 0];
    $ym = substr(today_str(), 0, 7);
    $in = implode(',', array_map('intval', $sheetIds));
    foreach (qall("SELECT sheet_id, tgl, seq, id, eval FROM runs WHERE sheet_id IN ($in) ORDER BY sheet_id, tgl, seq, id") as $r) {
        $s = &$out[(int)$r['sheet_id']];
        $s['runCount']++;
        $st = jdec($r['eval'])['status'] ?? null;
        if ($st && $st !== 'BELUM DIISI') { $s['lastStatus'] = $st; $s['lastDate'] = $r['tgl']; }
        if ($st === 'TERTOLAK' && substr($r['tgl'], 0, 7) === $ym) $s['rejMonth']++;
        unset($s);
    }
    return $out;
}

/** Status aplikasi untuk satu pengguna (dibatasi menurut peran) */
function app_state(array $u): array
{
    $role = $u['role'];
    $pk = $role === 'binaan' ? (int)$u['puskesmas_id'] : null;
    $S = [
        'me' => out_user($u),
        'settings' => ['tampilan' => setting('tampilan', []), 'rules' => rules_enabled(), 'k' => (float)setting('k', 2)],
        'params' => array_map(function ($p) { $o = out_param($p); $o['spec'] = tea_spec($p); return $o; }, qall('SELECT * FROM params ORDER BY kategori, id')),
    ];
    $S['pkms'] = array_map(fn($p) => ['id' => (int)$p['id'], 'kode' => $p['kode'], 'name' => $p['nama'], 'active' => (bool)$p['aktif']],
        $pk ? qall('SELECT * FROM puskesmas WHERE id = ?', [$pk]) : qall('SELECT * FROM puskesmas ORDER BY kode'));
    $S['alats'] = array_map('out_alat', $pk ? qall('SELECT * FROM alat WHERE puskesmas_id = ? ORDER BY id', [$pk]) : qall('SELECT * FROM alat ORDER BY puskesmas_id, id'));
    $S['personel'] = out_personel_list($pk ? qall('SELECT * FROM personel WHERE puskesmas_id = ? ORDER BY id', [$pk]) : qall('SELECT * FROM personel ORDER BY puskesmas_id, id'));
    $used = bhp_used_ids($pk);
    $S['bhp'] = array_map(fn($b) => out_bhp($b, isset($used[(int)$b['id']])), $pk ? qall('SELECT * FROM bhp WHERE puskesmas_id = ? ORDER BY jenis, nama, id', [$pk]) : qall('SELECT * FROM bhp ORDER BY puskesmas_id, jenis, nama, id'));
    $S['setup'] = $role === 'binaan' ? setup_status($pk) : null;
    if ($role === 'admin') {
        $S['users'] = array_map('out_user', qall('SELECT * FROM users ORDER BY role, id'));
        $S['sheets'] = []; $S['cases'] = []; $S['risks'] = [];
    } else {
        $S['users'] = [];
        $sheets = $pk ? qall('SELECT * FROM sheets WHERE puskesmas_id = ? ORDER BY id', [$pk]) : qall('SELECT * FROM sheets ORDER BY puskesmas_id, id');
        $sum = sheet_summaries(array_map(fn($s) => (int)$s['id'], $sheets));
        $S['sheets'] = array_map(function ($s) use ($sum) { return out_sheet($s) + ['summary' => $sum[(int)$s['id']]]; }, $sheets);
        $cases = $pk ? qall('SELECT * FROM cases WHERE puskesmas_id = ? ORDER BY id', [$pk]) : qall('SELECT * FROM cases ORDER BY id');
        $S['cases'] = array_map('out_case', $cases);
        $S['risks'] = array_map('out_risk', $pk ? qall('SELECT * FROM risks WHERE puskesmas_id = ? ORDER BY tgl, id', [$pk]) : qall('SELECT * FROM risks ORDER BY tgl, id'));
    }
    $key = notif_key_for($u);
    $S['notifs'] = array_map(fn($n) => ['id' => (int)$n['id'], 'at' => $n['at'], 'text' => $n['body'], 'link' => (string)$n['link'], 'read' => (bool)$n['rd']],
        qall('SELECT n.*, (SELECT COUNT(*) FROM notif_reads r WHERE r.notif_id = n.id AND r.user_id = ?) AS rd FROM notifs n WHERE n.to_key = ? ORDER BY n.id DESC LIMIT 300', [$u['id'], $key]));
    $kopKey = $role === 'binaan' ? 'pkm:' . $pk : ($role === 'pembina' ? 'pembina' : null);
    $S['kop'] = $kopKey ? kop_meta($kopKey) : null;
    return $S;
}

function kop_meta(string $key): ?array
{
    $k = q1('SELECT * FROM kops WHERE owner_key = ?', [$key]);
    if (!$k) return null;
    return ['key' => $key, 'info' => jdec($k['info']), 'updated' => $k['updated_at'], 'v' => substr(sha1($k['updated_at'] . $k['file']), 0, 8)];
}

/** Hak akses lembar: binaan hanya miliknya; pembina semua; admin tidak */
function can_view_pkm(array $u, int $pkmId): bool
{
    if ($u['role'] === 'pembina') return true;
    return $u['role'] === 'binaan' && (int)$u['puskesmas_id'] === $pkmId;
}

/* ---------- Sumber Daya (SDM, Peralatan, Bahan Habis Pakai) ---------- */
const ALAT_KAT = ['Kimia', 'Hematologi', 'Lainnya'];
const ALAT_KONDISI = ['baik', 'perbaikan', 'rusak'];
const BHP_JENIS = ['kontrol', 'reagen'];

/** Pemilik data Sumber Daya untuk akun: id Puskesmas (Binaan), 0 = Labkesmas (Pembina), null = hanya melihat (Admin) */
function sd_owner(array $u): ?int
{
    if ($u['role'] === 'binaan') return (int)$u['puskesmas_id'];
    if ($u['role'] === 'pembina') return 0;
    return null;
}
/** Hak melihat data Sumber Daya: Admin & Pembina semua; Binaan hanya miliknya */
function sd_can_view(array $u, int $pkmId): bool
{
    if ($u['role'] === 'admin' || $u['role'] === 'pembina') return true;
    return (int)$u['puskesmas_id'] === $pkmId;
}

function out_alat(array $a): array
{
    return ['id' => (int)$a['id'], 'pkmId' => (int)$a['puskesmas_id'], 'name' => $a['nama'], 'merk' => (string)$a['merk'], 'cat' => $a['kategori'], 'active' => (bool)$a['aktif'],
        'tipe' => (string)($a['tipe'] ?? ''), 'noSeri' => (string)($a['no_seri'] ?? ''), 'prinsip' => (string)($a['prinsip'] ?? ''), 'tahun' => (string)($a['tahun'] ?? ''),
        'kondisi' => (string)($a['kondisi'] ?? ''), 'kalTerakhir' => (string)($a['kal_terakhir'] ?? ''), 'kalBerikut' => (string)($a['kal_berikut'] ?? '')];
}
function out_personel_list(array $rows): array
{
    if (!$rows) return [];
    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
    $fotos = [];
    foreach (qall("SELECT id, personel_id, at, by_name FROM personel_foto WHERE personel_id IN ($ids) ORDER BY id DESC") as $f)
        $fotos[(int)$f['personel_id']][] = ['id' => (int)$f['id'], 'at' => $f['at'], 'by' => (string)$f['by_name']];
    return array_map(fn($p) => [
        'id' => (int)$p['id'], 'pkmId' => (int)$p['puskesmas_id'], 'name' => $p['nama'], 'nip' => (string)$p['nip'], 'jabatan' => (string)$p['jabatan'],
        'pendidikan' => (string)$p['pendidikan'], 'strNo' => (string)$p['str_no'], 'strBerlaku' => (string)$p['str_berlaku'], 'sipNo' => (string)$p['sip_no'],
        'pelatihan' => jdec($p['pelatihan']), 'fotoId' => $p['foto_id'] === null ? null : (int)$p['foto_id'], 'fotoAt' => $p['foto_at'],
        'fotos' => $fotos[(int)$p['id']] ?? [], 'active' => (bool)$p['aktif'],
    ], $rows);
}
function out_bhp(array $b, bool $used): array
{
    return ['id' => (int)$b['id'], 'pkmId' => (int)$b['puskesmas_id'], 'jenis' => $b['jenis'], 'name' => $b['nama'], 'produsen' => (string)$b['produsen'], 'cat' => $b['kategori'],
        'level' => (string)$b['level'], 'kemasan' => (string)$b['kemasan'], 'lot' => $b['lot'], 'ed' => (string)$b['ed'], 'suhu' => (string)$b['suhu'], 'status' => $b['status'],
        'prevId' => $b['prev_id'] === null ? null : (int)$b['prev_id'], 'used' => $used];
}

/** Id bahan habis pakai yang sudah dipakai di lembar QC (bahan kontrol per level, reagen, riwayat ganti LOT reagen) */
function bhp_used_ids(?int $pkmId): array
{
    $used = [];
    $rows = $pkmId === null ? qall('SELECT levels, reagen_bhp_id, reagen_events FROM sheets') : qall('SELECT levels, reagen_bhp_id, reagen_events FROM sheets WHERE puskesmas_id = ?', [$pkmId]);
    foreach ($rows as $r) {
        foreach (jdec($r['levels']) as $l) if (!empty($l['bhpId'])) $used[(int)$l['bhpId']] = true;
        if ($r['reagen_bhp_id'] !== null) $used[(int)$r['reagen_bhp_id']] = true;
        foreach (jdec($r['reagen_events']) as $e) if (!empty($e['bhpId'])) $used[(int)$e['bhpId']] = true;
    }
    return $used;
}

/** Alur wajib Binaan: SDM (≥1 personel aktif, semua personel aktif berfoto) → Peralatan (≥1 alat dipakai) → Manajemen Risiko lengkap */
function setup_status(int $pkmId): array
{
    $done = (string)(qval('SELECT setup_at FROM puskesmas WHERE id = ?', [$pkmId]) ?? '');
    $nAct = (int)qval('SELECT COUNT(*) FROM personel WHERE puskesmas_id = ? AND aktif = 1', [$pkmId]);
    $nNoFoto = (int)qval('SELECT COUNT(*) FROM personel WHERE puskesmas_id = ? AND aktif = 1 AND foto_id IS NULL', [$pkmId]);
    $alats = qall('SELECT id FROM alat WHERE puskesmas_id = ? AND aktif = 1', [$pkmId]);
    $riskMissing = [];
    foreach (['bahan', 'lingkungan', 'personel'] as $a) if (!latest_risk($pkmId, $a, null)) $riskMissing[] = $a;
    $alatMissing = [];
    foreach ($alats as $al) if (!latest_risk($pkmId, 'alat', (int)$al['id'])) $alatMissing[] = (int)$al['id'];
    $sdm = $nAct > 0 && $nNoFoto === 0;
    $alat = count($alats) > 0;
    $risk = $alat && !$riskMissing && !$alatMissing;
    return ['done' => $done !== '', 'doneAt' => $done ?: null, 'sdm' => $sdm, 'sdmActive' => $nAct, 'sdmNoFoto' => $nNoFoto, 'alat' => $alat,
        'risk' => $risk, 'riskMissing' => $riskMissing, 'alatRiskMissing' => $alatMissing];
}
/** Membuka akses penuh sekali saat semua langkah selesai; setelah itu akun tidak dikunci lagi */
function setup_check(int $pkmId): void
{
    $st = setup_status($pkmId);
    if ($st['done'] || !($st['sdm'] && $st['alat'] && $st['risk'])) return;
    q('UPDATE puskesmas SET setup_at = ? WHERE id = ?', [now_str(), $pkmId]);
    audit('setup_done', ['pkm' => $pkmId]);
}
