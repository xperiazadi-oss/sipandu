<?php
/* Uji mesin Westgard PHP: kesetaraan dengan Excel + skenario paper Westgard 1981.
   Dipakai oleh uji CLI (tests/run_engine.php) dan uji mandiri server (selftest.php). */
declare(strict_types=1);
require_once __DIR__ . '/../app/engine.php';

function sipandu_engine_tests(): array
{
    $res = ['checks' => 0, 'fail' => 0, 'messages' => []];
    $ok = function (bool $c, string $m) use (&$res) { $res['checks']++; if (!$c) { $res['fail']++; $res['messages'][] = 'GAGAL: ' . $m; } };

    // 1) Setara Excel: flag tiap aturan, z, status (tanpa pintu & run tertolak ikut jendela, seperti Excel), statistik
    $cases = json_decode((string)file_get_contents(__DIR__ . '/data/excel_cases.json'), true);
    $keys2 = ['1_2s', '1_3s', '2_2s', 'R_4s', '4_1s', '10x']; $keys3 = ['1_2s', '1_3s', '2of3_2s', 'R_4s', '3_1s', '6x'];
    $map = ['OUT OF CONTROL' => 'TERTOLAK', 'IN CONTROL' => 'DITERIMA', 'PERINGATAN' => 'PERINGATAN'];
    $excelChecks = 0;
    foreach ($cases as $ci => $c) {
        $n = count($c['means']);
        $levels = [];
        foreach ($c['means'] as $i => $m) $levels[] = ['name' => 'L' . ($i + 1), 'mean' => $m, 'sd' => $c['sds'][$i], 'active' => true];
        $runs = [];
        foreach ($c['vals'] as $d => $v) $runs[] = ['id' => 'R' . $d, 'date' => sprintf('2026-10-%02d', $d + 1), 'values' => array_map(fn($x) => (float)$x, $v), 'excluded' => false];
        $sheet = ['maxLevels' => $n, 'levels' => $levels, 'runs' => $runs];
        $ev = QCEngine::evaluate($sheet, null, ['includeRejected' => true, 'noGate' => true]);
        $keys = $n === 2 ? $keys2 : $keys3;
        foreach ($ev as $d => $e) {
            $x = $c['excel'][$d];
            foreach ($keys as $j => $k) { $excelChecks++; $ok(($e['flags'][$k] ?? false) === (bool)$x['f'][$j], "kasus $ci hari " . ($d + 1) . " aturan $k"); }
            for ($L = 0; $L < $n; $L++) { $excelChecks++; $ok(abs($e['z'][$L] - $x['z'][$L]) < 1e-9, "kasus $ci hari " . ($d + 1) . " z$L"); }
            $excelChecks++; $ok(($map[$x['status']] ?? '?') === $e['status'], "kasus $ci hari " . ($d + 1) . " status");
        }
        $st = QCEngine::stats($ev, $levels, 2.0, null);
        foreach ($st as $L => $s) {
            $xs = array_map(fn($r) => $r[$L], $c['xlstats']);
            foreach ([$s['mean'], $s['av'], $s['sd'], $s['cv'], $s['d'], $s['te']] as $j => $v) {
                $excelChecks++;
                if ($xs[$j] === null && $v === null) continue;
                $ok($v !== null && $xs[$j] !== null && abs($v - $xs[$j]) < 1e-9, "kasus $ci statistik L$L #$j");
            }
        }
    }
    $res['excel_checks'] = $excelChecks;

    // 2) Skenario paper Westgard 1981 (Gbr. 3) dan logika khusus aplikasi
    $mk = function (array $rows, ?array $lv = null): array {
        $lv = $lv ?? [['name' => 'Tinggi', 'mean' => 0, 'sd' => 1], ['name' => 'Rendah', 'mean' => 0, 'sd' => 1]];
        $runs = [];
        foreach ($rows as $i => $v) $runs[] = ['id' => 'R' . $i, 'date' => sprintf('2026-10-%02d', $i + 1), 'values' => $v];
        return ['maxLevels' => count($lv), 'levels' => $lv, 'runs' => $runs];
    };
    $rows = [];
    for ($d = 1; $d <= 30; $d++) $rows[] = [($d % 2 ? 0.5 : -0.5), ($d % 3 ? 0.4 : -0.6)];
    $rows[4] = [0.3, -3.4]; $rows[5] = [2.3, 0.2]; $rows[7] = [2.4, 2.2]; $rows[10] = [2.5, -2.3]; $rows[12] = [-2.2, 0.1]; $rows[13] = [-2.4, 0.3];
    $rows[15] = [1.3, 1.4]; $rows[16] = [1.5, 2.3];
    for ($d = 18; $d <= 27; $d++) $rows[$d - 1] = [($d % 2 ? 0.6 : -0.6), -0.5 - (($d % 3) * 0.2)];
    $rows[24][1] = -2.2; $rows[26][1] = -2.1; $rows[28] = [3.3, 2.4];
    $ev = QCEngine::evaluate($mk($rows));
    $g = fn($d) => $ev[$d - 1];
    $ok($g(5)['status'] === 'TERTOLAK' && $g(5)['indication'] === 'Acak' && str_contains($g(5)['note'], 'Sistematik Spontan'), 'paper hari 5');
    $ok($g(6)['status'] === 'PERINGATAN' && $g(6)['indication'] === 'Belum spesifik', 'paper hari 6');
    $ok($g(8)['status'] === 'TERTOLAK' && $g(8)['indication'] === 'Sistematik' && in_array(['type' => 'antar'], $g(8)['scope'], true), 'paper hari 8');
    $ok($g(11)['status'] === 'TERTOLAK' && in_array('R_4s', $g(11)['violations'], true) && $g(11)['indication'] === 'Acak', 'paper hari 11');
    $ok($g(13)['status'] === 'PERINGATAN', 'paper hari 13');
    $ok($g(14)['status'] === 'TERTOLAK' && $g(14)['flags']['2_2s'] && in_array(['type' => 'dalam', 'level' => 0], $g(14)['scope'], true), 'paper hari 14');
    $ok($g(17)['status'] === 'TERTOLAK' && $g(17)['flags']['4_1s'] && $g(17)['indication'] === 'Sistematik', 'paper hari 17');
    $ok($g(25)['status'] === 'PERINGATAN', 'paper hari 25');
    $ok($g(27)['status'] === 'TERTOLAK' && $g(27)['flags']['10x'] && in_array(['type' => 'dalam', 'level' => 1], $g(27)['scope'], true), 'paper hari 27');
    $ok($g(29)['status'] === 'TERTOLAK' && $g(29)['indication'] === 'Sistematik Spontan', 'paper hari 29');
    $e2 = QCEngine::evaluate($mk([[1.2, 1.3], [1.4, 1.1], [1.5, 1.6], [1.2, 1.8]]));
    $ok($e2[1]['status'] === 'DITERIMA' && in_array('4_1s', $e2[1]['trend'], true), 'pintu 1-2s: catatan tren');
    $e3 = QCEngine::evaluate($mk([[3.5, 0.1], [2.2, 0.1]]));
    $ok($e3[0]['status'] === 'TERTOLAK' && $e3[1]['status'] === 'PERINGATAN', 'run tertolak tidak dipakai menilai run berikutnya');
    $s4 = ['maxLevels' => 2, 'levels' => [['name' => 'L1', 'mean' => 0, 'sd' => 1], ['name' => 'L2', 'mean' => 0, 'sd' => 1]], 'runs' => [['id' => 'a', 'date' => '2026-10-30', 'values' => [2.3, 0]], ['id' => 'b', 'date' => '2026-11-03', 'values' => [2.4, 0]]]];
    $e4 = QCEngine::evaluate($s4);
    $ok($e4[1]['status'] === 'TERTOLAK' && $e4[1]['flags']['2_2s'], 'lintas bulan & hari kosong');
    $s5 = ['maxLevels' => 2, 'levels' => [['name' => 'L1', 'mean' => 0, 'sd' => 1], ['name' => 'L2', 'mean' => 0, 'sd' => 1, 'active' => false]], 'runs' => []];
    foreach ([0.3, 2.4, 1.2, 1.3, 1.1, 1.5] as $i => $v) $s5['runs'][] = ['id' => "x$i", 'date' => sprintf('2026-10-0%d', $i + 1), 'values' => [$v, null]];
    $e5 = QCEngine::evaluate($s5);
    $ok($e5[1]['status'] === 'TERTOLAK' && $e5[1]['indication'] === 'Belum spesifik', '1 level: 1-2s menolak');
    $ok($e5[5]['status'] === 'TERTOLAK' && $e5[5]['flags']['4_1s'] && $e5[5]['indication'] === 'Sistematik', '1 level: 4-1s');
    $ok($e5[0]['rulesNA'] === ['1_3s', '2_2s', 'R_4s', '10x'], '1 level: aturan tidak dapat dinilai');
    $e5b = QCEngine::evaluate(['maxLevels' => 1, 'levels' => [['name' => 'L1', 'mean' => 0, 'sd' => 1]], 'runs' => [['id' => 'y', 'date' => '2026-10-01', 'values' => [3.4]]]]);
    $ok($e5b[0]['indication'] === 'Acak', '1 level: >3SD acak');
    $e6 = QCEngine::evaluate(['maxLevels' => 3, 'levels' => [['name' => 'Low', 'mean' => 0, 'sd' => 1], ['name' => 'Normal', 'mean' => 0, 'sd' => 1], ['name' => 'High', 'mean' => 0, 'sd' => 1, 'active' => false]], 'runs' => [['id' => 'z', 'date' => '2026-10-01', 'values' => [2.3, 2.2, null]]]]);
    $ok(in_array('2_2s', $e6[0]['ruleSet'], true) && $e6[0]['status'] === 'TERTOLAK', '2 dari 3 level: set N=2');
    $e7 = QCEngine::evaluate($mk([[2.3, 0.1], [2.5, -2.4]]));
    $ok($e7[1]['indication'] === 'Acak + Sistematik', 'R-4s + 2-2s');
    $e8 = QCEngine::evaluate($mk([[0.5, null]]));
    $ok($e8[0]['status'] === 'BELUM LENGKAP', 'run tidak lengkap');
    $e9 = QCEngine::evaluate(['maxLevels' => 2, 'levels' => [['name' => 'L1', 'mean' => 0, 'sd' => 0], ['name' => 'L2', 'mean' => 0, 'sd' => 1]], 'runs' => [['id' => 'w', 'date' => '2026-10-01', 'values' => [1, 1]]]]);
    $ok($e9[0]['status'] === 'DATA/ACUAN INVALID', 'SD 0 invalid');
    $p = QCEngine::priority('Sistematik', ['alat' => 4, 'bahan' => 2, 'lingkungan' => 20, 'personel' => 25]);
    $ok(implode(',', array_column($p, 'aspect')) === 'alat,lingkungan,personel,bahan', 'prioritas sistematik + risiko');
    $p2 = QCEngine::priority('Acak', []);
    $ok(implode(',', array_column($p2, 'aspect')) === 'personel,bahan,lingkungan,alat', 'prioritas acak');
    return $res;
}
