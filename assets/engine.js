/* Si PANDU – Mesin hitung QC & Westgard Multirules (klien; wajib setara app/engine.php)
   Acuan: file QC_Kimia_Westgard.xlsx, QC_Hematologi_Westgard.xlsx,
   dan Westgard JO dkk. Clin Chem 1981;27(3):493-501 (sesuai kesepakatan). */
var QCEngine = (function () {
  'use strict';
  var isNum = function (v) { return typeof v === 'number' && isFinite(v); };

  var RULES = {
    '1_2s': { label: '1-2s' }, '1_3s': { label: '1-3s' }, '2_2s': { label: '2-2s' },
    '2of3_2s': { label: '2of3-2s' }, 'R_4s': { label: 'R-4s' }, '4_1s': { label: '4-1s' },
    '3_1s': { label: '3-1s' }, '10x': { label: '10x' }, '6x': { label: '6x' }
  };
  var SYST = ['2_2s', '2of3_2s', '4_1s', '3_1s', '10x', '6x'];
  var DEFAULT_SETTINGS = {
    enabled: { '1_2s': true, '1_3s': true, '2_2s': true, '2of3_2s': true, 'R_4s': true, '4_1s': true, '3_1s': true, '10x': true, '6x': true },
    k: 2
  };

  // Set aturan menurut jumlah level aktif (paper Tabel 4 utk N=1; Excel utk N=2 & N=3)
  function ruleSet(n) {
    if (n === 1) return ['1_2s', '4_1s'];
    if (n === 2) return ['1_2s', '1_3s', '2_2s', 'R_4s', '4_1s', '10x'];
    return ['1_2s', '1_3s', '2of3_2s', 'R_4s', '3_1s', '6x'];
  }
  // Aturan pada templat yang tidak dapat dinilai karena level aktif kurang
  function rulesNA(maxLevels, n) {
    // Hanya bila 1 level aktif: aturan templat selain 1-2s & 4-1s tidak dapat dinilai.
    // Bila 2 dari 3 level aktif, set aturan N=2 dipakai (bukan "tidak dapat dinilai").
    if (n !== 1) return [];
    var full = ruleSet(maxLevels), act = ruleSet(1);
    return full.filter(function (r) { return act.indexOf(r) < 0; });
  }

  function refValid(l) { return isNum(l.mean) && isNum(l.sd) && l.sd > 0; }
  function limits(l) {
    if (!refValid(l)) return null;
    var m = l.mean, s = l.sd;
    return { m3: m - 3 * s, m2: m - 2 * s, m1: m - s, mean: m, p1: m + s, p2: m + 2 * s, p3: m + 3 * s };
  }
  function sameSide(arr, thr) {
    if (!arr) return false;
    for (var i = 0; i < arr.length; i++) if (!isNum(arr[i])) return false;
    var up = arr.every(function (v) { return v > thr; });
    var dn = arr.every(function (v) { return v < -thr; });
    return up || dn;
  }

  function sortRuns(runs) {
    return runs.map(function (r, i) { return { r: r, i: i }; }).sort(function (a, b) {
      if (a.r.date < b.r.date) return -1; if (a.r.date > b.r.date) return 1;
      return (a.r.seq || 0) - (b.r.seq || 0) || a.i - b.i;
    }).map(function (x) { return x.r; });
  }

  /* evaluate(sheet, settings, opts)
     sheet.levels: [{name, mean, sd, active}], sheet.maxLevels, sheet.runs: [{id,date,seq,values:[..],excluded,reason}]
     opts.includeRejected (hanya untuk uji kesetaraan dgn Excel), opts.noGate (idem) */
  function evaluate(sheet, settings, opts) {
    settings = settings || DEFAULT_SETTINGS; opts = opts || {};
    var en = settings.enabled || DEFAULT_SETTINGS.enabled;
    var levels = sheet.levels;
    var active = [];
    levels.forEach(function (l, i) { if (l.active !== false) active.push(i); });
    var n = active.length;
    var maxL = sheet.maxLevels || levels.length;
    var set = ruleSet(n);
    var na = rulesNA(maxL, n);
    var refsOK = active.every(function (i) { return refValid(levels[i]); });
    var history = []; // run sebelumnya yang dipakai jendela lintas-run
    var out = [];

    sortRuns(sheet.runs || []).forEach(function (run) {
      var raw = run.values || [];
      var entered = active.filter(function (i) { return raw[i] !== null && raw[i] !== undefined && raw[i] !== ''; });
      var res = { run: run, z: {}, flags: {}, across: {}, within: {}, status: '', indication: '', note: '', scope: [], violations: [], trend: [], complete: false, ruleSet: set, rulesNA: na, nActive: n };
      if (entered.length === 0) { res.status = 'BELUM DIISI'; out.push(res); return; }
      var badVal = entered.some(function (i) { return !isNum(raw[i]); });
      if (badVal || !refsOK || n === 0) { res.status = 'DATA/ACUAN INVALID'; out.push(res); return; }
      active.forEach(function (i) {
        res.z[i] = isNum(raw[i]) ? (raw[i] - levels[i].mean) / levels[i].sd : null;
      });
      var cur = active.map(function (i) { return res.z[i]; }).filter(isNum);
      res.complete = cur.length === n;
      var mx = Math.max.apply(null, cur), mn = Math.min.apply(null, cur);

      // jendela dalam-level: m-1 run riwayat terakhir + run ini, semua harus berisi level L
      function within(L, m) {
        if (!isNum(res.z[L])) return null;
        if (history.length < m - 1) return null;
        var arr = [];
        for (var k = history.length - (m - 1); k < history.length; k++) arr.push(history[k].z[L]);
        arr.push(res.z[L]);
        return arr;
      }
      // jendela antar-level: m-1 run riwayat terakhir + run ini, semua harus lengkap
      function acrossRuns(m) {
        if (!res.complete) return null;
        if (history.length < m - 1) return null;
        var arr = [];
        for (var k = history.length - (m - 1); k < history.length; k++) {
          var h = history[k]; if (!h.complete) return null;
          active.forEach(function (i) { arr.push(h.z[i]); });
        }
        active.forEach(function (i) { arr.push(res.z[i]); });
        return arr;
      }
      function within2s(L) { return sameSide(within(L, 2), 2); }
      function anyWithin(m, thr, tag) {
        var hit = []; active.forEach(function (L) { if (sameSide(within(L, m), thr)) hit.push(L); });
        if (hit.length) res.within[tag] = hit; return hit.length > 0;
      }
      function setAcross(tag, v) { if (v) res.across[tag] = true; return v; }

      var f = {};
      f['1_2s'] = en['1_2s'] && (mx > 2 || mn < -2);
      f['1_3s'] = en['1_3s'] && set.indexOf('1_3s') >= 0 && (mx > 3 || mn < -3);
      if (n === 2) {
        var a22 = setAcross('2_2s', res.complete && sameSide(cur, 2));
        var w22 = anyWithin(2, 2, '2_2s');
        f['2_2s'] = en['2_2s'] && (a22 || w22);
        f['R_4s'] = en['R_4s'] && cur.length >= 2 && mx > 2 && mn < -2;
        var a41 = setAcross('4_1s', sameSide(acrossRuns(2), 1));
        var w41 = anyWithin(4, 1, '4_1s');
        f['4_1s'] = en['4_1s'] && (a41 || w41);
        var a10 = setAcross('10x', sameSide(acrossRuns(5), 0));
        var w10 = anyWithin(10, 0, '10x');
        f['10x'] = en['10x'] && (a10 || w10);
      } else if (n >= 3) {
        var pair = false;
        for (var p = 0; p < cur.length; p++) for (var q = p + 1; q < cur.length; q++) if (sameSide([cur[p], cur[q]], 2)) pair = true;
        setAcross('2of3_2s', pair);
        var w2 = anyWithin(2, 2, '2of3_2s');
        f['2of3_2s'] = en['2of3_2s'] && (pair || w2);
        f['R_4s'] = en['R_4s'] && cur.length >= 2 && mx > 2 && mn < -2;
        var a31 = setAcross('3_1s', res.complete && sameSide(cur, 1));
        var w31 = anyWithin(3, 1, '3_1s');
        f['3_1s'] = en['3_1s'] && (a31 || w31);
        var a6 = setAcross('6x', sameSide(acrossRuns(2), 0));
        var w6 = anyWithin(6, 0, '6x');
        f['6x'] = en['6x'] && (a6 || w6);
      } else if (n === 1) {
        f['4_1s'] = en['4_1s'] && anyWithin(4, 1, '4_1s');
      }
      Object.keys(f).forEach(function (k) { f[k] = !!f[k]; });
      res.flags = f;

      var rejectRules = set.filter(function (r) { return r !== '1_2s' && f[r]; });
      if (n === 1) {
        // Paper Tabel 4: N=1 -> 1-2s aturan penolakan, 4-1s lintas run
        res.violations = set.filter(function (r) { return f[r]; });
        if (res.violations.length) res.status = 'TERTOLAK';
      } else if (opts.noGate || f['1_2s']) {
        if (rejectRules.length) { res.status = 'TERTOLAK'; res.violations = rejectRules.slice(); }
        else if (f['1_2s']) { res.status = 'PERINGATAN'; res.violations = ['1_2s']; }
      } else {
        // Pintu 1-2s (paper Gbr. 1): tanpa nilai > ±2SD run diterima; pola lintas-run jadi catatan tren
        res.trend = rejectRules.slice();
      }
      if (!res.status) res.status = res.complete ? 'DITERIMA' : 'BELUM LENGKAP';

      res.indication = indication(res, n);
      res.scope = scope(res, levels);
      out.push(res);
      if (res.status !== 'TERTOLAK' || opts.includeRejected) history.push(res);
    });
    return out;
  }

  function indication(res, n) {
    var f = res.flags;
    if (res.status === 'PERINGATAN') return 'Belum spesifik';
    if (res.status !== 'TERTOLAK') return '';
    var sys = SYST.some(function (r) { return f[r] && res.violations.indexOf(r) >= 0; });
    if (n === 1) {
      var zs = Object.keys(res.z).map(function (k) { return res.z[k]; }).filter(isNum);
      var big = zs.some(function (z) { return Math.abs(z) > 3; });
      var r12 = f['1_2s'];
      if (big && sys) return 'Acak + Sistematik';
      if (sys) return 'Sistematik';
      if (big) return 'Acak';
      if (r12) return 'Belum spesifik';
      return '';
    }
    var rand = f['R_4s'], r13 = f['1_3s'];
    if (rand && sys) return 'Acak + Sistematik';
    if (r13 && sys) return 'Sistematik Spontan';
    if (sys) return 'Sistematik';
    if (rand) return 'Acak';
    if (r13) { res.note = 'kemungkinan Sistematik Spontan'; return 'Acak'; }
    return '';
  }

  function scope(res, levels) {
    var s = [];
    if (res.status !== 'TERTOLAK') return s;
    var acrossHit = SYST.some(function (r) { return res.across[r] && res.flags[r]; });
    if (acrossHit) s.push({ type: 'antar' });
    var lv = {};
    SYST.forEach(function (r) { if (res.flags[r] && res.within[r]) res.within[r].forEach(function (L) { lv[L] = 1; }); });
    Object.keys(lv).forEach(function (L) {
      s.push({ type: 'dalam', level: +L });
    });
    return s;
  }

  /* Statistik: hanya run DITERIMA/PERINGATAN, lengkap, tidak dieksklusi */
  function stats(evals, levels, k, tea, filterFn) {
    k = isNum(k) ? k : 2;
    return levels.map(function (l, i) {
      if (l.active === false) return { level: i, active: false };
      var xs = [];
      evals.forEach(function (e) {
        if ((e.status === 'DITERIMA' || e.status === 'PERINGATAN') && e.complete && !e.run.excluded && (!filterFn || filterFn(e))) {
          var v = e.run.values[i]; if (isNum(v)) xs.push(v);
        }
      });
      var nn = xs.length, sum = xs.reduce(function (a, b) { return a + b; }, 0);
      var mean = nn ? sum / nn : null;
      var sd = nn >= 2 ? Math.sqrt(xs.reduce(function (a, b) { return a + (b - mean) * (b - mean); }, 0) / (nn - 1)) : null;
      var cv = (isNum(sd) && isNum(mean) && mean !== 0) ? sd / Math.abs(mean) * 100 : null;
      var target = isNum(l.mean) ? l.mean : null;
      var d = (isNum(mean) && isNum(target) && target !== 0) ? (mean - target) / Math.abs(target) * 100 : null;
      var te = (isNum(d) && isNum(cv)) ? Math.abs(d) + k * cv : null;
      return { level: i, active: true, n: nn, total: nn ? sum : null, mean: mean, sd: sd, cv: cv, av: target, d: d, te: te, tea: isNum(tea) ? tea : null, teOK: (isNum(te) && isNum(tea)) ? te <= tea : null };
    });
  }

  /* Prioritas investigasi: dasar dari paper (jenis kesalahan) + skor risiko terakhir (0-25) */
  var ASPECTS = ['alat', 'bahan', 'lingkungan', 'personel'];
  var BASE = {
    'Acak': { personel: 40, bahan: 30, lingkungan: 20, alat: 10 },
    'Sistematik': { alat: 40, bahan: 30, lingkungan: 20, personel: 10 },
    'Sistematik Spontan': { alat: 40, bahan: 30, lingkungan: 20, personel: 10 },
    'Acak + Sistematik': { personel: 25, bahan: 30, lingkungan: 20, alat: 25 },
    'Belum spesifik': { personel: 25, bahan: 25, lingkungan: 25, alat: 25 }
  };
  function priority(ind, risk) {
    var base = BASE[ind] || BASE['Belum spesifik']; risk = risk || {};
    return ASPECTS.map(function (a) {
      var r = isNum(risk[a]) ? Math.max(0, Math.min(25, risk[a])) : 0;
      return { aspect: a, base: base[a], risk: r, total: base[a] + r };
    }).sort(function (x, y) { return y.total - x.total || y.base - x.base; });
  }

  return { evaluate: evaluate, stats: stats, priority: priority, limits: limits, ruleSet: ruleSet, rulesNA: rulesNA, RULES: RULES, SYST: SYST, DEFAULT_SETTINGS: DEFAULT_SETTINGS, ASPECTS: ASPECTS, BASE: BASE, refValid: refValid };
})();
if (typeof module !== 'undefined') module.exports = QCEngine;
