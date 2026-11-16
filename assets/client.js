/* Si PANDU – klien: status dari server, panggilan API, dan fungsi bantu (tanpa teks tampilan; teks dari app/teks.php) */
var Core = (function () {
  'use strict';
  var B = window.SIPANDU || {};
  var S = B.state || {};
  var T = B.T || {};
  var CSRF = B.csrf || '';
  var sheetCache = {};

  function t(key, vars) {
    var node = T, parts = key.split('.');
    for (var i = 0; i < parts.length; i++) { if (node == null || typeof node !== 'object' || !(parts[i] in node)) return key; node = node[parts[i]]; }
    if (typeof node !== 'string') return node;
    if (vars) Object.keys(vars).forEach(function (k) { node = node.split('{' + k + '}').join(String(vars[k])); });
    return node;
  }
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var isNum = function (v) { return typeof v === 'number' && isFinite(v); };
  var pad = function (n, w) { n = String(n); while (n.length < (w || 2)) n = '0' + n; return n; };
  function today() { var d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function fmt(v, dp) { if (!isNum(v)) return '–'; return v.toFixed(dp == null ? 2 : dp).replace('.', ','); }
  function num(v) { if (v === '' || v == null) return null; var x = Number(String(v).replace(',', '.')); return isFinite(x) ? x : NaN; }
  function monthLabel(ym) { if (!ym) return ''; var p = ym.split('-'); return t('bulan')[+p[1] - 1] + ' ' + p[0]; }

  /* ---------- API ---------- */
  function api(action, data, opts) {
    opts = opts || {};
    var body, headers = { 'X-CSRF-Token': CSRF };
    if (opts.form) { body = opts.form; body.append('a', action); }
    else { body = JSON.stringify(Object.assign({ a: action }, data || {})); headers['Content-Type'] = 'application/json'; }
    return fetch('api.php', { method: 'POST', headers: headers, body: body, credentials: 'same-origin' }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: t('galat.server') }; }).then(function (j) {
        if (r.status === 401) { location.reload(); throw new Error(j.error || ''); }
        if (!j.ok) throw new Error(j.error || t('galat.server'));
        if (j.state) S = j.state;
        return j.data;
      });
    });
  }
  function loadSheet(id) {
    return api('sheet_get', { id: +id }).then(function (d) {
      var sh = byId(S.sheets, id) || {};
      Object.assign(sh, d.sheet); sh.runs = d.runs; sh.comments = d.comments;
      sheetCache[id] = { runs: d.runs, comments: d.comments };
      return sh;
    });
  }
  function cachedSheet(id) {
    var sh = byId(S.sheets, id); if (!sh) return null;
    var c = sheetCache[id]; if (c) { sh.runs = c.runs; sh.comments = c.comments; }
    return sh;
  }
  function invalidate(id) { delete sheetCache[id]; }

  /* ---------- Akses data ---------- */
  function byId(arr, id) { arr = arr || []; for (var i = 0; i < arr.length; i++) if (String(arr[i].id) === String(id)) return arr[i]; return null; }
  function pkm(id) { return byId(S.pkms, id) || { name: '?' }; }
  function param(id) { return byId(S.params, id) || { name: '?' }; }
  function alat(id) { return byId(S.alats, id) || { name: '?' }; }
  function me() { return S.me; }
  function matrixName(sh) { return param(sh.paramId).name + ' · ' + alat(sh.alatId).name; }
  function settings() { return S.settings || {}; }
  function evalSheet(sh) { return QCEngine.evaluate(sh, { enabled: settings().rules, k: settings().k }); }

  var SPEC_LEVELS = { Optimum: [0.25, 0.125], Desirable: [0.5, 0.25], Minimum: [0.75, 0.375] };
  function teaSpec(p) {
    if (!p) return { tea: null, label: '' };
    if (p.specSrc === 'bv') {
      var lv = SPEC_LEVELS[p.level] ? p.level : 'Desirable', f = SPEC_LEVELS[lv];
      var base = t('spek.label_vb', { tingkat: lv }) + (p.bvSrc ? ' (' + p.bvSrc + ')' : '');
      if (!isNum(p.cvi) || !isNum(p.cvg) || p.cvi < 0 || p.cvg < 0) return { tea: null, src: 'bv', level: lv, label: base + t('spek.belum_cvi') };
      var cva = f[0] * p.cvi, bias = f[1] * Math.sqrt(p.cvi * p.cvi + p.cvg * p.cvg);
      return { tea: 1.65 * cva + bias, cva: cva, bias: bias, src: 'bv', level: lv, label: base };
    }
    return { tea: isNum(p.tea) ? p.tea : null, src: 'lain', label: t('spek.label_lain') + (p.teaSrc ? ': ' + p.teaSrc : '') };
  }

  function latestRisk(pkmId, aspect, alatId) {
    var list = (S.risks || []).filter(function (r) { return String(r.pkmId) === String(pkmId) && r.aspect === aspect && (aspect !== 'alat' || String(r.alatId) === String(alatId)); });
    if (!list.length) return null;
    list.sort(function (a, b) { return a.date < b.date ? 1 : a.date > b.date ? -1 : b.id - a.id; });
    return list[0];
  }
  function riskMax(r) { if (!r) return null; return Math.max.apply(null, r.items.map(function (i) { return (i.L || 0) * (i.S || 0); })); }
  function riskLevel(s) {
    if (!isNum(s) || s === 0) return { t: t('risiko.tingkat.belum'), c: 'muted' };
    if (s <= 4) return { t: t('risiko.tingkat.rendah'), c: 'ok' };
    if (s <= 9) return { t: t('risiko.tingkat.sedang'), c: 'warn' };
    if (s <= 16) return { t: t('risiko.tingkat.tinggi'), c: 'bad' };
    return { t: t('risiko.tingkat.sangat'), c: 'bad' };
  }
  function riskScores(pkmId, alatId) { var o = {}; QCEngine.ASPECTS.forEach(function (a) { o[a] = riskMax(latestRisk(pkmId, a, alatId)); }); return o; }

  function notIdeal(sh) {
    var out = [], act = sh.levels.filter(function (l) { return l.active !== false; }).length;
    if (act < sh.maxLevels) out.push(t('lembar.ni_level', { a: act, m: sh.maxLevels }));
    var na = QCEngine.rulesNA(sh.maxLevels, act);
    if (na.length) out.push(t('lembar.ni_aturan', { daftar: na.map(function (r) { return QCEngine.RULES[r].label; }).join(', ') }));
    if (act === 1) out.push(t('lembar.ni_satu_level'));
    return out;
  }
  function scopeText(sc, sh) {
    if (sc.type === 'antar') return t('kasus.cakupan_antar');
    var lv = sh && sh.levels[sc.level] ? sh.levels[sc.level].name : sc.level;
    return t('kasus.cakupan_dalam', { level: lv });
  }

  return {
    get S() { return S; }, T: T, t: t, api: api, loadSheet: loadSheet, cachedSheet: cachedSheet, invalidate: invalidate,
    esc: esc, fmt: fmt, num: num, isNum: isNum, today: today, pad: pad, monthLabel: monthLabel,
    byId: byId, pkm: pkm, param: param, alat: alat, me: me, matrixName: matrixName, settings: settings, evalSheet: evalSheet,
    teaSpec: teaSpec, SPEC_LEVELS: SPEC_LEVELS, latestRisk: latestRisk, riskMax: riskMax, riskLevel: riskLevel, riskScores: riskScores,
    notIdeal: notIdeal, scopeText: scopeText, csrf: function () { return CSRF; }
  };
})();
