<?php
require __DIR__ . '/../app/engine.php';
$cases = json_decode(file_get_contents(__DIR__ . '/data/diff_cases.json'), true);
$fail = 0; $checks = 0; $runs = 0;
$eqf = fn($a, $b) => ($a === null && $b === null) || (is_numeric($a) && is_numeric($b) && abs($a - $b) < 1e-9);
foreach ($cases as $ci => $c) {
    $ev = QCEngine::evaluate($c['sheet'], $c['enabled']);
    $byId = []; foreach ($ev as $e) $byId[$e['run']['id']] = $e;
    $orderJs = array_column($c['ev'], 'id'); $orderPhp = array_map(fn($e) => $e['run']['id'], $ev);
    $checks++; if ($orderJs !== $orderPhp) { $fail++; echo "urutan beda kasus $ci\n"; }
    foreach ($c['ev'] as $j) {
        $runs++; $p = $byId[$j['id']];
        foreach (['status', 'indication', 'note', 'complete'] as $k) { $checks++; if ($p[$k] !== $j[$k]) { $fail++; if ($fail < 10) echo "kasus $ci run {$j['id']} $k: php=" . json_encode($p[$k]) . " js=" . json_encode($j[$k]) . "\n"; } }
        foreach (['violations', 'trend', 'rulesNA', 'scope'] as $k) { $checks++; if (json_encode($p[$k]) !== json_encode($j[$k])) { $fail++; if ($fail < 10) echo "kasus $ci run {$j['id']} $k beda\n"; } }
        $jf = $j['flags']; $pf = $p['flags']; ksort($jf); ksort($pf); $checks++; if ($jf != $pf) { $fail++; if ($fail < 10) echo "kasus $ci flags beda\n"; }
        foreach ($j['z'] as $L => $z) { $checks++; if (!$eqf($p['z'][$L] ?? null, $z)) { $fail++; } }
    }
    $st = QCEngine::stats($ev, $c['sheet']['levels'], 2.0, 7.5);
    foreach ($c['st'] as $L => $s) foreach ($s as $k => $v) { $checks++; $pv = $st[$L][$k] ?? null; if (is_bool($v) || is_int($v) && in_array($k, ['level', 'n'])) { if ($pv !== $v) { $fail++; echo "stat $ci $L $k\n"; } } elseif (!$eqf($pv, $v)) { $fail++; if ($fail < 10) echo "stat $ci $L $k php=$pv js=$v\n"; } }
}
echo "kasus: " . count($cases) . ", run: $runs, pemeriksaan: $checks, beda: $fail\n";
exit($fail ? 1 : 0);
