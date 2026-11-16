<?php
/* Si PANDU – Mesin hitung QC & Westgard Multirules (port PHP dari engine.js, wajib setara).
   Acuan: QC_Kimia_Westgard.xlsx, QC_Hematologi_Westgard.xlsx,
   Westgard JO dkk. Clin Chem 1981;27(3):493-501. */
declare(strict_types=1);

final class QCEngine
{
    public const RULES = ['1_2s' => '1-2s', '1_3s' => '1-3s', '2_2s' => '2-2s', '2of3_2s' => '2of3-2s', 'R_4s' => 'R-4s', '4_1s' => '4-1s', '3_1s' => '3-1s', '10x' => '10x', '6x' => '6x'];
    public const SYST = ['2_2s', '2of3_2s', '4_1s', '3_1s', '10x', '6x'];
    public const ASPECTS = ['alat', 'bahan', 'lingkungan', 'personel'];
    public const BASE = [
        'Acak' => ['personel' => 40, 'bahan' => 30, 'lingkungan' => 20, 'alat' => 10],
        'Sistematik' => ['alat' => 40, 'bahan' => 30, 'lingkungan' => 20, 'personel' => 10],
        'Sistematik Spontan' => ['alat' => 40, 'bahan' => 30, 'lingkungan' => 20, 'personel' => 10],
        'Acak + Sistematik' => ['personel' => 25, 'bahan' => 30, 'lingkungan' => 20, 'alat' => 25],
        'Belum spesifik' => ['personel' => 25, 'bahan' => 25, 'lingkungan' => 25, 'alat' => 25],
    ];

    public static function defaultEnabled(): array
    {
        return array_fill_keys(array_keys(self::RULES), true);
    }

    public static function isNum($v): bool
    {
        return (is_int($v) || is_float($v)) && is_finite((float)$v);
    }

    public static function ruleSet(int $n): array
    {
        if ($n === 1) return ['1_2s', '4_1s'];
        if ($n === 2) return ['1_2s', '1_3s', '2_2s', 'R_4s', '4_1s', '10x'];
        return ['1_2s', '1_3s', '2of3_2s', 'R_4s', '3_1s', '6x'];
    }

    public static function rulesNA(int $maxLevels, int $n): array
    {
        if ($n !== 1) return [];
        $act = self::ruleSet(1);
        return array_values(array_filter(self::ruleSet($maxLevels), fn($r) => !in_array($r, $act, true)));
    }

    public static function refValid(array $l): bool
    {
        return self::isNum($l['mean'] ?? null) && self::isNum($l['sd'] ?? null) && $l['sd'] > 0;
    }

    private static function sameSide(?array $arr, float $thr): bool
    {
        if ($arr === null) return false;
        foreach ($arr as $v) if (!self::isNum($v)) return false;
        $up = true; $dn = true;
        foreach ($arr as $v) { if (!($v > $thr)) $up = false; if (!($v < -$thr)) $dn = false; }
        return $up || $dn;
    }

    private static function sortRuns(array $runs): array
    {
        $idx = [];
        foreach ($runs as $i => $r) $idx[] = ['r' => $r, 'i' => $i];
        usort($idx, function ($a, $b) {
            $c = strcmp((string)$a['r']['date'], (string)$b['r']['date']);
            if ($c !== 0) return $c < 0 ? -1 : 1;
            $s = ((int)($a['r']['seq'] ?? 0)) - ((int)($b['r']['seq'] ?? 0));
            return $s !== 0 ? $s : $a['i'] - $b['i'];
        });
        return array_map(fn($x) => $x['r'], $idx);
    }

    /** @param array $sheet ['levels'=>[['mean','sd','active','name']], 'maxLevels'=>int, 'runs'=>[['id','date','seq','values'=>[], 'excluded']]] */
    public static function evaluate(array $sheet, ?array $enabled = null, array $opts = []): array
    {
        $en = $enabled ?? self::defaultEnabled();
        $levels = $sheet['levels'];
        $active = [];
        foreach ($levels as $i => $l) if (($l['active'] ?? true) !== false) $active[] = $i;
        $n = count($active);
        $maxL = (int)($sheet['maxLevels'] ?? count($levels));
        $set = self::ruleSet($n);
        $na = self::rulesNA($maxL, $n);
        $refsOK = true;
        foreach ($active as $i) if (!self::refValid($levels[$i])) $refsOK = false;
        $history = [];
        $out = [];

        foreach (self::sortRuns($sheet['runs'] ?? []) as $run) {
            $raw = $run['values'] ?? [];
            $entered = array_values(array_filter($active, fn($i) => array_key_exists($i, $raw) && $raw[$i] !== null && $raw[$i] !== ''));
            $res = ['run' => $run, 'z' => [], 'flags' => [], 'across' => [], 'within' => [], 'status' => '', 'indication' => '', 'note' => '', 'scope' => [], 'violations' => [], 'trend' => [], 'complete' => false, 'ruleSet' => $set, 'rulesNA' => $na, 'nActive' => $n];
            if (!$entered) { $res['status'] = 'BELUM DIISI'; $out[] = $res; continue; }
            $bad = false;
            foreach ($entered as $i) if (!self::isNum($raw[$i])) $bad = true;
            if ($bad || !$refsOK || $n === 0) { $res['status'] = 'DATA/ACUAN INVALID'; $out[] = $res; continue; }
            foreach ($active as $i) {
                $v = $raw[$i] ?? null;
                $res['z'][$i] = self::isNum($v) ? ($v - $levels[$i]['mean']) / $levels[$i]['sd'] : null;
            }
            $cur = [];
            foreach ($active as $i) if (self::isNum($res['z'][$i])) $cur[] = $res['z'][$i];
            $res['complete'] = count($cur) === $n;
            $mx = max($cur); $mn = min($cur);

            $within = function (int $L, int $m) use (&$res, &$history): ?array {
                if (!self::isNum($res['z'][$L] ?? null)) return null;
                $h = count($history);
                if ($h < $m - 1) return null;
                $arr = [];
                for ($k = $h - ($m - 1); $k < $h; $k++) $arr[] = $history[$k]['z'][$L] ?? null;
                $arr[] = $res['z'][$L];
                return $arr;
            };
            $acrossRuns = function (int $m) use (&$res, &$history, $active): ?array {
                if (!$res['complete']) return null;
                $h = count($history);
                if ($h < $m - 1) return null;
                $arr = [];
                for ($k = $h - ($m - 1); $k < $h; $k++) {
                    $hh = $history[$k];
                    if (!$hh['complete']) return null;
                    foreach ($active as $i) $arr[] = $hh['z'][$i] ?? null;
                }
                foreach ($active as $i) $arr[] = $res['z'][$i];
                return $arr;
            };
            $anyWithin = function (int $m, float $thr, string $tag) use (&$res, $active, $within): bool {
                $hit = [];
                foreach ($active as $L) if (self::sameSide($within($L, $m), $thr)) $hit[] = $L;
                if ($hit) $res['within'][$tag] = $hit;
                return (bool)$hit;
            };
            $setAcross = function (string $tag, bool $v) use (&$res): bool {
                if ($v) $res['across'][$tag] = true;
                return $v;
            };

            $f = [];
            $f['1_2s'] = !empty($en['1_2s']) && ($mx > 2 || $mn < -2);
            $f['1_3s'] = !empty($en['1_3s']) && in_array('1_3s', $set, true) && ($mx > 3 || $mn < -3);
            if ($n === 2) {
                $a22 = $setAcross('2_2s', $res['complete'] && self::sameSide($cur, 2));
                $w22 = $anyWithin(2, 2, '2_2s');
                $f['2_2s'] = !empty($en['2_2s']) && ($a22 || $w22);
                $f['R_4s'] = !empty($en['R_4s']) && count($cur) >= 2 && $mx > 2 && $mn < -2;
                $a41 = $setAcross('4_1s', self::sameSide($acrossRuns(2), 1));
                $w41 = $anyWithin(4, 1, '4_1s');
                $f['4_1s'] = !empty($en['4_1s']) && ($a41 || $w41);
                $a10 = $setAcross('10x', self::sameSide($acrossRuns(5), 0));
                $w10 = $anyWithin(10, 0, '10x');
                $f['10x'] = !empty($en['10x']) && ($a10 || $w10);
            } elseif ($n >= 3) {
                $pair = false;
                $c = count($cur);
                for ($p = 0; $p < $c; $p++) for ($q = $p + 1; $q < $c; $q++) if (self::sameSide([$cur[$p], $cur[$q]], 2)) $pair = true;
                $setAcross('2of3_2s', $pair);
                $w2 = $anyWithin(2, 2, '2of3_2s');
                $f['2of3_2s'] = !empty($en['2of3_2s']) && ($pair || $w2);
                $f['R_4s'] = !empty($en['R_4s']) && count($cur) >= 2 && $mx > 2 && $mn < -2;
                $a31 = $setAcross('3_1s', $res['complete'] && self::sameSide($cur, 1));
                $w31 = $anyWithin(3, 1, '3_1s');
                $f['3_1s'] = !empty($en['3_1s']) && ($a31 || $w31);
                $a6 = $setAcross('6x', self::sameSide($acrossRuns(2), 0));
                $w6 = $anyWithin(6, 0, '6x');
                $f['6x'] = !empty($en['6x']) && ($a6 || $w6);
            } elseif ($n === 1) {
                $f['4_1s'] = !empty($en['4_1s']) && $anyWithin(4, 1, '4_1s');
            }
            foreach ($f as $k => $v) $f[$k] = (bool)$v;
            $res['flags'] = $f;

            $reject = array_values(array_filter($set, fn($r) => $r !== '1_2s' && !empty($f[$r])));
            if ($n === 1) {
                $res['violations'] = array_values(array_filter($set, fn($r) => !empty($f[$r])));
                if ($res['violations']) $res['status'] = 'TERTOLAK';
            } elseif (!empty($opts['noGate']) || $f['1_2s']) {
                if ($reject) { $res['status'] = 'TERTOLAK'; $res['violations'] = $reject; }
                elseif ($f['1_2s']) { $res['status'] = 'PERINGATAN'; $res['violations'] = ['1_2s']; }
            } else {
                $res['trend'] = $reject;
            }
            if ($res['status'] === '') $res['status'] = $res['complete'] ? 'DITERIMA' : 'BELUM LENGKAP';
            $res['indication'] = self::indication($res, $n);
            $res['scope'] = self::scope($res);
            $out[] = $res;
            if ($res['status'] !== 'TERTOLAK' || !empty($opts['includeRejected'])) $history[] = $res;
        }
        return $out;
    }

    private static function indication(array &$res, int $n): string
    {
        $f = $res['flags'];
        if ($res['status'] === 'PERINGATAN') return 'Belum spesifik';
        if ($res['status'] !== 'TERTOLAK') return '';
        $sys = false;
        foreach (self::SYST as $r) if (!empty($f[$r]) && in_array($r, $res['violations'], true)) $sys = true;
        if ($n === 1) {
            $big = false;
            foreach ($res['z'] as $z) if (self::isNum($z) && abs($z) > 3) $big = true;
            if ($big && $sys) return 'Acak + Sistematik';
            if ($sys) return 'Sistematik';
            if ($big) return 'Acak';
            if (!empty($f['1_2s'])) return 'Belum spesifik';
            return '';
        }
        $rand = !empty($f['R_4s']); $r13 = !empty($f['1_3s']);
        if ($rand && $sys) return 'Acak + Sistematik';
        if ($r13 && $sys) return 'Sistematik Spontan';
        if ($sys) return 'Sistematik';
        if ($rand) return 'Acak';
        if ($r13) { $res['note'] = 'kemungkinan Sistematik Spontan'; return 'Acak'; }
        return '';
    }

    /** Cakupan konsentrasi: [{type:'antar'}|{type:'dalam',level:int}] */
    private static function scope(array $res): array
    {
        $s = [];
        if ($res['status'] !== 'TERTOLAK') return $s;
        foreach (self::SYST as $r) if (!empty($res['across'][$r]) && !empty($res['flags'][$r])) { $s[] = ['type' => 'antar']; break; }
        $lv = [];
        foreach (self::SYST as $r) if (!empty($res['flags'][$r]) && !empty($res['within'][$r])) foreach ($res['within'][$r] as $L) $lv[$L] = 1;
        ksort($lv);
        foreach (array_keys($lv) as $L) $s[] = ['type' => 'dalam', 'level' => (int)$L];
        return $s;
    }

    public static function stats(array $evals, array $levels, float $k = 2.0, $tea = null, ?callable $filter = null): array
    {
        $out = [];
        foreach ($levels as $i => $l) {
            if (($l['active'] ?? true) === false) { $out[] = ['level' => $i, 'active' => false]; continue; }
            $xs = [];
            foreach ($evals as $e) {
                if (($e['status'] === 'DITERIMA' || $e['status'] === 'PERINGATAN') && $e['complete'] && empty($e['run']['excluded']) && (!$filter || $filter($e))) {
                    $v = $e['run']['values'][$i] ?? null;
                    if (self::isNum($v)) $xs[] = (float)$v;
                }
            }
            $nn = count($xs); $sum = 0.0;
            foreach ($xs as $x) $sum += $x;
            $mean = $nn ? $sum / $nn : null;
            $sd = null;
            if ($nn >= 2) { $ss = 0.0; foreach ($xs as $x) $ss += ($x - $mean) * ($x - $mean); $sd = sqrt($ss / ($nn - 1)); }
            $cv = ($sd !== null && $mean !== null && $mean != 0) ? $sd / abs($mean) * 100 : null;
            $target = self::isNum($l['mean'] ?? null) ? (float)$l['mean'] : null;
            $d = ($mean !== null && $target !== null && $target != 0) ? ($mean - $target) / abs($target) * 100 : null;
            $te = ($d !== null && $cv !== null) ? abs($d) + $k * $cv : null;
            $teaN = self::isNum($tea) ? (float)$tea : null;
            $out[] = ['level' => $i, 'active' => true, 'n' => $nn, 'total' => $nn ? $sum : null, 'mean' => $mean, 'sd' => $sd, 'cv' => $cv, 'av' => $target, 'd' => $d, 'te' => $te, 'tea' => $teaN, 'teOK' => ($te !== null && $teaN !== null) ? $te <= $teaN : null];
        }
        return $out;
    }

    public static function priority(string $ind, array $risk): array
    {
        $base = self::BASE[$ind] ?? self::BASE['Belum spesifik'];
        $rows = [];
        foreach (self::ASPECTS as $a) {
            $r = self::isNum($risk[$a] ?? null) ? max(0, min(25, $risk[$a])) : 0;
            $rows[] = ['aspect' => $a, 'base' => $base[$a], 'risk' => $r, 'total' => $base[$a] + $r];
        }
        usort($rows, fn($x, $y) => ($y['total'] <=> $x['total']) ?: ($y['base'] <=> $x['base']));
        return $rows;
    }
}
