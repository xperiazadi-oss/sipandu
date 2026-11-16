/* Si PANDU – antarmuka Tahap 1. Semua teks tampilan berasal dari app/teks.php (Core.t). */
var App = (function () {
  'use strict';
  var C = Core, E = QCEngine, esc = C.esc, fmt = C.fmt, t = C.t;
  var root, ui = { month: {}, filter: {}, loading: {} };

  /* ---------- util ---------- */
  function $(s, el) { return (el || document).querySelector(s); }
  function toast(msg, bad) { var d = document.createElement('div'); d.className = 'toast' + (bad ? ' bad' : ''); d.textContent = msg; document.body.appendChild(d); setTimeout(function () { d.remove(); }, bad ? 4500 : 2600); }
  function go(r) { if (location.hash.slice(1) === r) render(); else location.hash = r; }
  function route() { return decodeURIComponent(location.hash.slice(1)) || 'beranda'; }
  function chip(x, c) { return '<span class="chip c-' + c + '">' + esc(x) + '</span>'; }
  function statusChip(s) { var c = { DITERIMA: 'ok', PERINGATAN: 'warn', TERTOLAK: 'bad' }[s] || (s === 'DATA/ACUAN INVALID' ? 'bad' : 'muted'); return chip(t('status.' + s.replace('/', '_').replace(/ /g, '_')), c); }
  function caseChip(s) { return chip(t('kasus.tahap.' + s), { Terbuka: 'bad', Investigasi: 'warn', Koreksi: 'warn', Verifikasi: 'info', Ditutup: 'ok', Dibatalkan: 'muted' }[s] || 'muted'); }
  function typeChip(x) { return chip(t('kasus.jenis.' + x), x === 'Tertolak' ? 'bad' : 'warn'); }
  function rl(s) { return C.riskLevel(s); }
  function opt(v, x, sel) { return '<option value="' + esc(v) + '"' + (sel ? ' selected' : '') + '>' + esc(x) + '</option>'; }
  function canEditSheet(sh) { var u = C.me(); return u.role === 'binaan' && String(u.pkmId) === String(sh.pkmId) && sh.status === 'aktif'; }
  function keepScroll() { ui.keepScroll = window.scrollY; }
  function modal(html) { var bg = document.createElement('div'); bg.className = 'modal-bg'; bg.innerHTML = '<div class="modal">' + html + '</div>'; bg.addEventListener('click', function (e) { if (e.target === bg || e.target.dataset.close != null) bg.remove(); }); document.body.appendChild(bg); return bg; }
  function call(action, data, msg, opts) {
    return C.api(action, data, opts).then(function (r) { if (msg) toast(msg); render(); return r; }, function (e) { toast(e.message, true); render(); return new Promise(function () { }); });
  }
  function rules(rs) { return rs.map(function (r) { return E.RULES[r].label; }).join(', '); }
  function aspectName(a) { return t('aspek.' + a); }

  /* ---------- menu ---------- */
  var MENU = {
    binaan: [['beranda'], ['#sd'], ['sd-sdm', 1], ['sd-alat', 1], ['sd-bhp', 1], ['risiko'], ['#pmi'], ['qc', 1], ['dokkm', 1, 2], ['pme', 0, 3], ['sa', 0, 3], ['pustaka', 0, 3], ['#lainnya'], ['kop'], ['arsip', 0, 2], ['notif']],
    pembina: [['beranda'], ['#sd'], ['sd-sdm', 1], ['sd-alat', 1], ['sd-bhp', 1], ['#pembinaan'], ['pantau', 1], ['kasus', 1], ['tinjau', 1, 2], ['lpm', 1, 2], ['ub', 0, 3], ['up', 0, 3], ['kl', 0, 3], ['#pengesahan'], ['ttd', 0, 2], ['kop'], ['arsip', 0, 2], ['notif']],
    admin: [['beranda'], ['#akses'], ['pengguna', 1], ['#referensi'], ['ref-pkm', 1], ['ref-param', 1], ['ref-alat', 1], ['aturan', 1], ['pustaka-kelola', 1, 3], ['#sd'], ['sd-sdm', 1], ['sd-alat', 1], ['sd-bhp', 1], ['#operasional'], ['tampilan', 1], ['operasional', 1], ['arsip', 0, 2]]
  };
  function tampil() { return Object.assign({ appName: t('app.nama'), longName: t('app.kepanjangan'), tagline: t('app.slogan'), color: '#0d5c63' }, C.settings().tampilan || {}); }

  var SETUP_OPEN = ['sd', 'risiko', 'kop'];
  function locked(head) { return !C.setupDone() && SETUP_OPEN.indexOf(head) < 0; }
  function layout(body, cur) {
    var u = C.me(), st = tampil();
    var nav = MENU[u.role].map(function (m) {
      if (m[0].charAt(0) === '#') return '<div class="grp">' + esc(t('menu.grup.' + m[0].slice(1))) + '</div>';
      var on = cur === m[0] || cur.indexOf(m[0] + '-') === 0, lk = locked(m[0].split('-')[0]);
      return '<a href="#' + m[0] + '" class="' + (on ? 'on ' : '') + (m[1] ? 'sub ' : '') + (lk ? 'lock' : '') + '"' + (lk ? ' title="' + esc(t('sd.terkunci')) + '"' : '') + '>' + (lk ? '🔒 ' : '') + esc(t('menu.' + m[0].replace('-', '_'))) + (m[2] ? ' <span class="tahap">' + esc(t('umum.tahap_n', { n: m[2] })) + '</span>' : '') + '</a>';
    }).join('');
    var unread = (C.S.notifs || []).filter(function (n) { return !n.read; }).length;
    return '<div class="app"><aside class="side"><div class="brand"><b>' + esc(st.appName) + '</b><small>' + esc(st.tagline) + '</small></div><nav class="nav">' + nav + '</nav></aside>' +
      '<div class="main"><div class="top"><div><b>' + esc(st.longName) + '</b></div><div class="who"><b>' + esc(u.name) + '</b>' + esc(t('peran.' + u.role)) + '</div>' +
      (u.role !== 'admin' ? '<button class="bell" data-act="nav" data-to="notif">🔔 ' + esc(t('menu.notif')) + (unread ? '<i>' + unread + '</i>' : '') + '</button>' : '') +
      '<button class="btn o s" data-act="pwModal">' + esc(t('umum.ganti_sandi')) + '</button><a class="btn o s" href="logout.php?t=' + encodeURIComponent(C.csrf()) + '">' + esc(t('umum.keluar')) + '</a></div><div class="content">' + body + '</div></div></div>';
  }

  /* ---------- render ---------- */
  function render() {
    var r = route(), u = C.me();
    document.documentElement.style.setProperty('--pri', tampil().color);
    var body;
    try { body = view(r, u); } catch (e) { body = '<div class="note bad">' + esc(t('galat.tampilan')) + ': ' + esc(e.message) + '</div>'; if (window.console) console.error(e); }
    root.innerHTML = layout(body, r);
    window.scrollTo(0, ui.keepScroll || 0); ui.keepScroll = 0;
  }
  function stage(n) { return '<div class="card"><h2>' + esc(t('menu.' + route().split('-')[0])) + '</h2><p class="muted">' + esc(t('umum.tahap_berikut', { n: n })) + '</p></div>'; }
  function view(r, u) {
    var p = r.split('-'), head = p[0], arg = p.slice(1).join('-');
    var allowed = {
      binaan: { beranda: homeBinaan, qc: qcList, lembar: function () { return worksheet(arg); }, kasus: function () { return arg ? casePage(arg) : denied(); }, risiko: function () { return arg ? riskForm(arg) : riskHome(u.pkmId); }, kop: kopPage, notif: notifPage, dokkm: function () { return stage(2); }, arsip: function () { return stage(2); }, pme: function () { return stage(3); }, sa: function () { return stage(3); }, pustaka: function () { return stage(3); } },
      pembina: { beranda: homePembina, pantau: pantau, pkm: function () { return pkmDetail(arg); }, lembar: function () { return worksheet(arg); }, kasus: function () { return arg ? casePage(arg) : caseList(); }, risiko: function () { return riskForm(arg); }, kop: kopPage, notif: notifPage, tinjau: function () { return stage(2); }, lpm: function () { return stage(2); }, ttd: function () { return stage(2); }, arsip: function () { return stage(2); }, ub: function () { return stage(3); }, up: function () { return stage(3); }, kl: function () { return stage(3); } },
      admin: { beranda: homeAdmin, pengguna: pengguna, ref: function () { return arg === 'pkm' ? refPkm() : arg === 'param' ? refParam() : refAlat(); }, aturan: aturan, tampilan: tampilanPage, operasional: operasional, pustaka: function () { return stage(3); }, arsip: function () { return stage(2); } }
    }[u.role];
    allowed.sd = function () { return sdPage(arg); };
    if (!allowed[head]) return denied();
    if (locked(head)) return setupCard();
    return allowed[head]();
  }
  function denied() { return '<div class="note bad">' + esc(t('galat.halaman_tidak_tersedia')) + '</div>'; }
  function loadingCard() { return '<div class="card"><p class="muted">' + esc(t('umum.memuat')) + '</p></div>'; }

  /* ---------- beranda ---------- */
  function kpi(v, x) { return '<div class="kpi"><b>' + v + '</b><span>' + esc(x) + '</span></div>'; }
  function openCases(filter) { return (C.S.cases || []).filter(function (c) { return c.state !== 'Ditutup' && c.state !== 'Dibatalkan' && (!filter || filter(c)); }); }
  function homeBinaan() {
    var u = C.me(), shs = C.S.sheets.filter(function (s) { return s.status === 'aktif'; }), cases = openCases();
    var h = '<h2>' + esc(t('beranda.judul_binaan', { pkm: C.pkm(u.pkmId).name })) + '</h2><div class="grid g3" style="margin-bottom:16px">' + kpi(shs.length, t('beranda.lembar_aktif')) + kpi(cases.length, t('beranda.kasus_terbuka')) + kpi((C.S.notifs || []).filter(function (n) { return !n.read; }).length, t('beranda.notif_baru')) + '</div>';
    if (!C.S.kop) h += '<div class="note">' + esc(t('kop.belum_binaan')) + ' <a href="#kop">' + esc(t('menu.kop')) + '</a></div>';
    h += '<div class="card"><h3>' + esc(t('beranda.kasus_tindak')) + '</h3>' + caseTable(cases) + '</div>';
    h += '<div class="card"><h3>' + esc(t('beranda.status_lembar')) + '</h3>' + sheetTable(shs) + '</div>';
    return h;
  }
  function homePembina() {
    var cases = openCases(), kons = cases.filter(function (c) { return c.mode === 'konsultasi'; });
    var h = '<h2>' + esc(t('beranda.judul_pembina')) + '</h2><div class="grid g3" style="margin-bottom:16px">' + kpi(C.S.pkms.length, t('beranda.jumlah_pkm')) + kpi(cases.length, t('beranda.kasus_terbuka')) + kpi(kons.length, t('beranda.menunggu_konsultasi')) + '</div>';
    h += '<div class="card"><h3>' + esc(t('beranda.permintaan_konsultasi')) + '</h3>' + caseTable(kons) + '</div>';
    h += '<div class="card"><h3>' + esc(t('beranda.notif_terbaru')) + '</h3>' + notifList((C.S.notifs || []).slice(0, 8)) + '</div>';
    return h;
  }
  function homeAdmin() {
    var us = C.S.users || [];
    var cnt = function (r) { return us.filter(function (x) { return x.role === r; }).length; };
    return '<h2>' + esc(t('beranda.judul_admin')) + '</h2><div class="grid g4">' + kpi(us.length, t('beranda.akun')) + kpi(us.filter(function (x) { return x.active; }).length, t('beranda.akun_aktif')) + kpi(C.S.pkms.length, t('beranda.jumlah_pkm')) + kpi((C.S.alats || []).length, t('beranda.alat')) + '</div>' +
      '<div class="card" style="margin-top:16px"><h3>' + esc(t('beranda.komposisi')) + '</h3><p>' + esc(t('beranda.komposisi_isi', { a: cnt('admin'), p: cnt('pembina'), b: cnt('binaan') })) + '</p><p class="small muted">' + esc(t('beranda.akun_tak_terbatas')) + '</p></div>';
  }

  /* ---------- tabel umum ---------- */
  function caseTable(list) {
    if (!list.length) return '<p class="muted">' + esc(t('umum.tidak_ada')) + '</p>';
    return '<div class="tbl"><table><tr><th>' + esc(t('kasus.kol_kasus')) + '</th><th>' + esc(t('kasus.kol_tanggal')) + '</th><th>' + esc(t('kasus.kol_pkm')) + '</th><th>' + esc(t('kasus.kol_matriks')) + '</th><th>' + esc(t('kasus.kol_jenis')) + '</th><th>' + esc(t('kasus.kol_indikasi')) + '</th><th>' + esc(t('kasus.kol_investigasi')) + '</th><th>' + esc(t('kasus.kol_tahap')) + '</th></tr>' + list.map(function (c) {
      var sh = C.byId(C.S.sheets, c.sheetId);
      return '<tr class="clk" data-act="nav" data-to="kasus-' + c.id + '"><td>#' + c.id + '</td><td>' + c.runDate + '</td><td>' + esc(C.pkm(c.pkmId).name) + '</td><td>' + esc(sh ? C.matrixName(sh) : '') + '</td><td>' + typeChip(c.type) + '</td><td>' + esc(c.indication) + '</td><td>' + (c.mode ? esc(t('kasus.mode.' + c.mode)) : '<span class="muted">' + esc(t('kasus.mode_belum')) + '</span>') + '</td><td>' + caseChip(c.state) + '</td></tr>';
    }).join('') + '</table></div>';
  }
  function sheetTable(shs) {
    if (!shs.length) return '<p class="muted">' + esc(t('lembar.belum_ada')) + '</p>';
    return '<div class="tbl"><table><tr><th>' + esc(t('lembar.kol_param')) + '</th><th>' + esc(t('lembar.kol_alat')) + '</th><th>' + esc(t('lembar.kol_kategori')) + '</th><th>' + esc(t('lembar.kol_level')) + '</th><th>' + esc(t('lembar.kol_lot')) + '</th><th class="num">' + esc(t('lembar.kol_run')) + '</th><th>' + esc(t('lembar.kol_status_akhir')) + '</th><th>' + esc(t('lembar.kol_kasus')) + '</th><th>' + esc(t('lembar.kol_lembar')) + '</th></tr>' + shs.map(function (sh) {
      var act = sh.levels.filter(function (l) { return l.active !== false; }), s = sh.summary || {};
      var oc = openCases(function (c) { return String(c.sheetId) === String(sh.id); }).length;
      return '<tr class="clk" data-act="nav" data-to="lembar-' + sh.id + '"><td><b>' + esc(C.param(sh.paramId).name) + '</b></td><td>' + esc(C.alat(sh.alatId).name) + '</td><td>' + esc(sh.cat) + '</td><td>' + act.length + ' / ' + sh.maxLevels + (act.length < sh.maxLevels ? ' ' + chip(t('lembar.tidak_ideal'), 'warn') : '') + '</td><td>' + esc(act.map(function (l) { return l.lot || '–'; }).join(', ')) + '</td><td class="num">' + (s.runCount || 0) + '</td><td>' + (s.lastStatus ? statusChip(s.lastStatus) + ' <span class="small muted">' + s.lastDate + '</span>' : '–') + '</td><td>' + (oc ? chip(oc, 'bad') : '0') + '</td><td>' + (sh.status === 'aktif' ? chip(t('lembar.aktif'), 'ok') : chip(t('lembar.ditutup'), 'muted')) + '</td></tr>';
    }).join('') + '</table></div>';
  }
  function notifList(list) {
    if (!list.length) return '<p class="muted">' + esc(t('notif.belum_ada')) + '</p>';
    return list.map(function (n) { return '<div class="case-card' + (n.text.indexOf(t('kasus.jenis.Tertolak')) === 0 ? ' rej' : '') + '" style="cursor:pointer;' + (n.read ? 'opacity:.7' : '') + '" data-act="openNotif" data-id="' + n.id + '"><div class="row"><b class="sp">' + esc(n.text) + '</b><span class="small muted">' + esc(n.at) + '</span></div></div>'; }).join('');
  }
  function notifPage() { return '<div class="row"><h2 class="sp">' + esc(t('menu.notif')) + '</h2><button class="btn o s" data-act="readAll">' + esc(t('notif.tandai_semua')) + '</button></div>' + notifList(C.S.notifs || []); }

  /* ---------- QC: daftar lembar ---------- */
  function qcList() {
    var u = C.me(), all = C.S.sheets;
    var act = all.filter(function (s) { return s.status === 'aktif'; }), closed = all.filter(function (s) { return s.status !== 'aktif'; });
    var alAct = C.S.alats.filter(function (a) { return a.active && String(a.pkmId) === String(u.pkmId) && a.cat !== 'Lainnya'; });
    var alats = alAct.filter(function (a) { return C.latestRisk(u.pkmId, 'alat', a.id); }), noRisk = alAct.filter(function (a) { return !C.latestRisk(u.pkmId, 'alat', a.id); });
    var miss = [];
    ['Kimia', 'Hematologi'].forEach(function (cat) { ['kontrol', 'reagen'].forEach(function (j) { if (!(C.S.bhp || []).some(function (b) { return b.jenis === j && b.cat === cat && b.status === 'tersedia'; })) miss.push(t('sd.jenis_opsi.' + j) + ' ' + cat); }); });
    var h = '<h2>' + esc(t('qc.judul')) + '</h2><p class="muted">' + esc(t('qc.pengantar')) + '</p>';
    if (noRisk.length) h += '<div class="note">' + esc(t('qc.alat_belum_dinilai', { daftar: noRisk.map(function (a) { return a.name; }).join(', ') })) + ' <a href="#risiko">' + esc(t('menu.risiko')) + '</a></div>';
    if (miss.length) h += '<div class="note">' + esc(t('qc.bhp_kurang', { daftar: miss.join(', ') })) + ' <a href="#sd-bhp">' + esc(t('menu.sd_bhp')) + '</a></div>';
    h += '<div class="card"><h3>' + esc(t('qc.lembar_aktif')) + '</h3>' + sheetTable(act) + '</div>';
    h += '<div class="card"><h3>' + esc(t('qc.buat_baru')) + '</h3>' + (alats.length ? '<div class="form">' +
      '<label class="f">' + esc(t('qc.parameter')) + '<select id="nsParam">' + C.S.params.map(function (p) { return opt(p.id, p.name + ' (' + p.cat + ')'); }).join('') + '</select></label>' +
      '<label class="f">' + esc(t('qc.alat')) + '<select id="nsAlat">' + alats.map(function (a) { return opt(a.id, a.name + ' – ' + a.cat); }).join('') + '</select></label>' +
      '<label class="f">' + esc(t('qc.level_tersedia')) + '<select id="nsLv">' + opt('all', t('qc.lv_semua')) + opt('1', t('qc.lv_1')) + opt('2', t('qc.lv_2')) + '</select></label>' +
      '<div><button class="btn" data-act="newSheet">' + esc(t('qc.tombol_buat')) + '</button></div></div><p class="small muted">' + esc(t('qc.catatan_level')) + '</p>' : '<p class="muted">' + esc(t('qc.belum_ada_alat')) + '</p>') + '</div>';
    if (closed.length) h += '<div class="card"><h3>' + esc(t('qc.lembar_ditutup')) + '</h3>' + sheetTable(closed) + '</div>';
    return h;
  }

  /* ---------- Grafik Levey-Jennings ---------- */
  function ljChart(sh, ev, L) {
    var lev = sh.levels[L], lim = E.limits(lev);
    var pts = ev.filter(function (e) { return C.isNum(e.z[L]); });
    var W = 860, H = 250, pl = 64, pr = 16, pt = 16, pb = 34, n = Math.max(pts.length, 10);
    var x = function (i) { return pl + (W - pl - pr) * (n === 1 ? .5 : i / (n - 1)); };
    var y = function (z) { z = Math.max(-4.2, Math.min(4.2, z)); return pt + (H - pt - pb) * (4.2 - z) / 8.4; };
    var s = '<svg class="lj" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc(t('lembar.grafik_aria', { level: lev.name })) + '">';
    [[3, '#b42318', '+3SD'], [2, '#d97706', '+2SD'], [1, '#2563eb', '+1SD'], [0, '#16232b', 'Mean'], [-1, '#2563eb', '-1SD'], [-2, '#d97706', '-2SD'], [-3, '#b42318', '-3SD']].forEach(function (l) {
      var val = lim ? lim.mean + l[0] * lev.sd : null;
      s += '<line x1="' + pl + '" x2="' + (W - pr) + '" y1="' + y(l[0]) + '" y2="' + y(l[0]) + '" stroke="' + l[1] + '" stroke-width="' + (l[0] === 0 ? 1.6 : 1) + '"' + (l[0] === 0 ? '' : ' stroke-dasharray="5 4"') + '/>';
      s += '<text x="' + (pl - 6) + '" y="' + (y(l[0]) + 4) + '" font-size="10" text-anchor="end" fill="' + l[1] + '">' + l[2] + (val != null ? ' ' + fmt(val, 1) : '') + '</text>';
    });
    (sh.reagenEvents || []).forEach(function (re) {
      var idx = -1; pts.forEach(function (p, i) { if (idx < 0 && p.run.date >= re.date) idx = i; });
      if (idx >= 0) { var xx = x(idx) - 6; s += '<line x1="' + xx + '" x2="' + xx + '" y1="' + pt + '" y2="' + (H - pb) + '" stroke="#7c3aed" stroke-dasharray="2 3"/><text x="' + (xx + 3) + '" y="' + (pt + 9) + '" font-size="10" fill="#7c3aed">' + esc(t('lembar.penanda_reagen', { lot: re.lot })) + '</text>'; }
    });
    s += '<path d="' + pts.map(function (p, i) { return (i ? 'L' : 'M') + x(i).toFixed(1) + ',' + y(p.z[L]).toFixed(1); }).join('') + '" fill="none" stroke="#5d6b74" stroke-width="1.2"/>';
    pts.forEach(function (p, i) {
      var col = p.status === 'TERTOLAK' ? '#b42318' : p.status === 'PERINGATAN' ? '#d97706' : '#0d5c63';
      s += '<circle cx="' + x(i).toFixed(1) + '" cy="' + y(p.z[L]).toFixed(1) + '" r="4.2" fill="' + (p.run.excluded ? '#fff' : col) + '" stroke="' + col + '" stroke-width="1.6"><title>' + p.run.date + ' · ' + fmt(p.run.values[L], 2) + ' (z ' + fmt(p.z[L], 2) + ')</title></circle>';
      if (i % Math.ceil(n / 15) === 0) s += '<text x="' + x(i) + '" y="' + (H - 14) + '" font-size="9.5" text-anchor="middle" fill="#5d6b74">' + p.run.date.slice(5).replace('-', '/') + '</text>';
    });
    return s + '<text x="' + (W - pr) + '" y="' + (H - 2) + '" font-size="10" text-anchor="end" fill="#5d6b74">' + esc(t('lembar.legenda_grafik')) + '</text></svg>';
  }

  /* ---------- Lembar kerja ---------- */
  function worksheet(id) {
    var sh = C.cachedSheet(id);
    if (!sh) return '<div class="note bad">' + esc(t('galat.lembar_tidak_ada')) + '</div>';
    if (!sh.runs) { if (!ui.loading[id]) { ui.loading[id] = 1; C.loadSheet(id).then(function () { ui.loading[id] = 0; render(); }, function (e) { ui.loading[id] = 0; toast(e.message, true); }); } return loadingCard(); }
    var u = C.me(), ed = canEditSheet(sh), p = C.param(sh.paramId), ev = C.evalSheet(sh);
    var act = []; sh.levels.forEach(function (l, i) { if (l.active !== false) act.push(i); });
    var locked = sh.runs.length > 0;
    var h = '<div class="row"><div class="sp"><div class="small muted">' + esc(C.pkm(sh.pkmId).name) + ' · ' + esc(t('qc.judul')) + '</div><h2>' + esc(p.name) + ' <span class="muted" style="font-weight:400">· ' + esc(C.alat(sh.alatId).name) + '</span></h2></div>' +
      (sh.status === 'aktif' ? chip(t('lembar.lembar_aktif'), 'ok') : chip(t('lembar.ditutup_pada', { tgl: sh.closedAt, alasan: sh.closeReason }), 'muted')) + '</div>';
    if (sh.prevId) h += '<p class="small muted">' + esc(t('lembar.lanjutan')) + ' <a href="#lembar-' + sh.prevId + '">' + esc(t('lembar.lembar_sebelumnya')) + '</a></p>';
    var ni = C.notIdeal(sh);
    if (ni.length) h += '<div class="note"><b>' + esc(t('lembar.tidak_ideal_judul')) + '</b><ul style="margin:4px 0 0 18px;padding:0">' + ni.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul></div>';
    if (ed) h += '<div class="row" style="margin:10px 0"><button class="btn o s" data-act="reagenLot" data-id="' + sh.id + '">' + esc(t('lembar.tombol_lot_reagen')) + '</button><button class="btn o s" data-act="newLot" data-id="' + sh.id + '">' + esc(t('lembar.tombol_lot_kontrol')) + '</button></div>';

    var bhpOpts = function (jenis, curId) { return (C.S.bhp || []).filter(function (b) { return b.jenis === jenis && b.cat === sh.cat && String(b.pkmId) === String(sh.pkmId) && (b.status === 'tersedia' || String(b.id) === String(curId)); }); };
    var bhpLabel = function (b) { return b.name + (b.level ? ' · ' + b.level : '') + ' · LOT ' + b.lot + (b.ed ? ' · ED ' + b.ed : ''); };
    var kOpts = bhpOpts('kontrol');
    h += '<div class="card"><h3>' + esc(t('lembar.bahan_kontrol')) + '</h3><p class="small muted">' + esc(t('lembar.petunjuk_sel')) + ' ' + esc(t('lembar.petunjuk_bhp')) + (locked ? ' ' + esc(t('lembar.level_terkunci')) : '') + '</p><div class="tbl"><table><tr><th>' + esc(t('lembar.kol_aktif')) + '</th><th>' + esc(t('lembar.kol_level_nama')) + '</th><th>' + esc(t('lembar.kol_nama_kontrol')) + '</th><th>' + esc(t('lembar.kol_no_lot')) + '</th><th>' + esc(t('lembar.kol_ed_pabrik')) + '</th><th>' + esc(t('lembar.kol_ed_buka')) + '</th><th class="num">Mean</th><th class="num">SD</th><th class="num">-3SD</th><th class="num">-2SD</th><th class="num">-1SD</th><th class="num">+1SD</th><th class="num">+2SD</th><th class="num">+3SD</th></tr>';
    sh.levels.forEach(function (l, i) {
      var lim = E.limits(l), on = l.active !== false;
      var inp = function (k, type) { return '<td class="in"><input class="cell" ' + (ed && on ? '' : 'disabled ') + 'type="' + (type || 'text') + '" data-lv="' + i + '" data-k="' + k + '" value="' + esc(l[k] == null ? '' : l[k]) + '"></td>'; };
      var ctl = ed && on ? '<td class="in"><select class="cell" style="min-width:240px" data-lvbhp="' + i + '">' + opt('', t('lembar.pilih'), !l.bhpId) + bhpOpts('kontrol', l.bhpId).map(function (b) { return opt(b.id, bhpLabel(b), String(b.id) === String(l.bhpId)); }).join('') + '</select></td>' : '<td>' + esc(l.control || '') + '</td>';
      h += '<tr' + (on ? '' : ' style="opacity:.5"') + '><td><input type="checkbox" data-act="lvActive" data-i="' + i + '" ' + (on ? 'checked ' : '') + (ed && !locked ? '' : 'disabled') + '></td><td>' + esc(l.name) + '</td>' + ctl + '<td class="calc">' + esc(l.lot || '') + '</td><td class="calc">' + esc(l.edPabrik || '') + '</td>' + inp('edBuka', 'date') + inp('mean') + inp('sd') +
        ['m3', 'm2', 'm1', 'p1', 'p2', 'p3'].map(function (k) { return '<td class="calc num">' + (lim && on ? fmt(lim[k], 2) : '') + '</td>'; }).join('') + '</tr>';
    });
    h += '</table></div>';
    var rOpts = bhpOpts('reagen', sh.reagenBhpId), canPickR = ed && (!locked || !sh.reagenLot);
    if (ed && (!kOpts.length || !bhpOpts('reagen').length)) h += '<div class="note">' + (!kOpts.length ? esc(t('lembar.bhp_kosong_kontrol', { kat: sh.cat })) + ' ' : '') + (!bhpOpts('reagen').length ? esc(t('lembar.bhp_kosong_reagen', { kat: sh.cat })) + ' ' : '') + '<a href="#sd-bhp">' + esc(t('lembar.isi_di_sd')) + '</a></div>';
    var meta = function (k, key) { return '<label class="f">' + esc(t(key)) + '<input class="inp" data-meta="' + k + '" ' + (ed ? '' : 'disabled') + ' value="' + esc(sh[k] || '') + '"></label>'; };
    h += '<div class="form" style="margin-top:10px"><label class="f">' + esc(t('lembar.instrumen')) + '<input class="inp" disabled value="' + esc(C.alat(sh.alatId).name) + '"></label>' + meta('method', 'lembar.metode') +
      (canPickR ? '<label class="f">' + esc(t('lembar.reagen')) + '<select class="inp" data-reagenpick="1">' + opt('', t('lembar.pilih'), !sh.reagenBhpId) + rOpts.map(function (b) { return opt(b.id, bhpLabel(b), String(b.id) === String(sh.reagenBhpId)); }).join('') + '</select></label>'
        : '<label class="f">' + esc(t('lembar.reagen')) + '<input class="inp" disabled value="' + esc(sh.reagen || '') + '"></label>') +
      '<label class="f">' + esc(t('lembar.lot_reagen')) + '<input class="inp" disabled value="' + esc(sh.reagenLot || '') + '"></label>' + meta('unit', 'lembar.satuan') + '</div>';
    if ((sh.reagenEvents || []).length) h += '<p class="small muted">' + esc(t('lembar.riwayat_lot_reagen')) + ' ' + esc(sh.reagenEvents.map(function (r) { return r.date + ' → ' + r.lot; }).join('; ')) + '</p>';
    h += '</div>';

    var open = openCases(function (c) { return String(c.sheetId) === String(sh.id); });
    if (open.length) h += '<div class="card"><h3>' + esc(t('lembar.notif_pelanggaran')) + '</h3>' + open.map(function (c) { return caseCard(c, sh); }).join('') + '</div>';

    if (ed) h += '<div class="card"><h3>' + esc(t('lembar.input_harian')) + '</h3><div class="form"><label class="f">' + esc(t('lembar.tanggal')) + '<input class="inp" type="date" id="runDate" max="' + C.today() + '" value="' + C.today() + '"></label>' +
      act.map(function (i) { return '<label class="f">' + esc(sh.levels[i].name) + ' (' + esc(sh.unit || '') + ')<input class="inp" id="runV' + i + '" inputmode="decimal"></label>'; }).join('') +
      '<label class="f">' + esc(t('lembar.catatan')) + '<input class="inp" id="runNote"></label><div><button class="btn" data-act="addRun" data-id="' + sh.id + '">' + esc(t('lembar.tombol_simpan_run')) + '</button></div></div></div>';

    var rs = E.ruleSet(act.length), na = E.rulesNA(sh.maxLevels, act.length);
    h += '<div class="card"><div class="row"><h3 class="sp">' + esc(t('lembar.hasil_harian')) + '</h3><span class="small muted">' + esc(t('lembar.set_aturan', { n: act.length, daftar: rs.map(function (r) { return E.RULES[r].label; }).join(' / ') })) + (na.length ? ' · ' + esc(t('lembar.tidak_dapat_dinilai')) + ': ' + esc(rules(na)) : '') + '</span></div>';
    h += '<div class="tbl" style="max-height:520px"><table><tr><th>' + esc(t('lembar.tanggal')) + '</th>' + act.map(function (i) { return '<th class="num">' + esc(sh.levels[i].name) + '</th><th class="num">z</th>'; }).join('') +
      rs.map(function (r) { return '<th>' + E.RULES[r].label + '</th>'; }).join('') + na.map(function (r) { return '<th class="muted">' + E.RULES[r].label + '</th>'; }).join('') +
      '<th>' + esc(t('lembar.kol_status_run')) + '</th><th>' + esc(t('kasus.kol_indikasi')) + '</th><th>' + esc(t('lembar.kol_eksklusi')) + '</th><th>' + esc(t('lembar.kol_alasan')) + '</th><th>' + esc(t('kasus.kol_kasus')) + '</th></tr>';
    ev.slice().reverse().forEach(function (e) {
      var cs = (C.S.cases || []).filter(function (c) { return String(c.runId) === String(e.run.id) && c.state !== 'Dibatalkan'; })[0];
      h += '<tr><td>' + e.run.date + '</td>' + act.map(function (i) {
        return '<td class="wh num"><input class="cell num" style="text-align:right;width:80px" ' + (ed ? '' : 'disabled ') + 'data-run="' + e.run.id + '" data-i="' + i + '" value="' + (e.run.values[i] == null ? '' : e.run.values[i]) + '"></td><td class="calc num">' + fmt(e.z[i], 2) + '</td>';
      }).join('') + rs.map(function (r) {
        var on = e.flags[r], tr = e.trend && e.trend.indexOf(r) >= 0;
        return '<td>' + (on ? (tr ? chip(t('lembar.tren'), 'info') : chip('✕', r === '1_2s' && act.length > 1 ? 'warn' : 'bad')) : '') + '</td>';
      }).join('') + na.map(function () { return '<td class="small muted">' + esc(t('lembar.tidak_dapat_dinilai')) + '</td>'; }).join('') +
        '<td>' + statusChip(e.status) + (e.trend && e.trend.length ? ' <span class="small muted">' + esc(t('lembar.catatan_tren')) + '</span>' : '') + '</td><td>' + esc(e.indication) + (e.note ? '<div class="small muted">' + esc(e.note) + '</div>' : '') + '</td>' +
        '<td><input type="checkbox" data-act="excl" data-run="' + e.run.id + '" ' + (e.run.excluded ? 'checked ' : '') + (ed ? '' : 'disabled') + '></td><td><input class="cell" style="min-width:140px" data-reason="' + e.run.id + '" ' + (ed ? '' : 'disabled ') + 'value="' + esc(e.run.reason || '') + '" placeholder="' + (e.run.excluded ? esc(t('lembar.wajib_diisi')) : '') + '"></td>' +
        '<td>' + (cs ? '<a href="#kasus-' + cs.id + '">#' + cs.id + '</a> ' + caseChip(cs.state) : '') + '</td></tr>';
    });
    h += '</table></div><p class="small muted">' + esc(t('lembar.catatan_tabel')) + '</p></div>';

    var ms = {}; sh.runs.forEach(function (r) { ms[r.date.slice(0, 7)] = 1; }); ms = Object.keys(ms).sort();
    var sel = ui.month[sh.id] || 'all', filt = sel === 'all' ? null : function (e) { return e.run.date.slice(0, 7) === sel; };
    var spec = C.teaSpec(p), st = E.stats(ev, sh.levels, C.settings().k, spec.tea, filt);
    h += '<div class="card"><div class="row"><h3 class="sp">' + esc(t('lembar.statistik')) + '</h3><select class="inp" data-act="month" data-id="' + sh.id + '">' + opt('all', t('lembar.seluruh_lembar'), sel === 'all') + ms.map(function (m) { return opt(m, C.monthLabel(m), sel === m); }).join('') + '</select></div>';
    h += '<div class="tbl"><table><tr><th>' + esc(t('lembar.keterangan')) + '</th>' + act.map(function (i) { return '<th class="num">' + esc(sh.levels[i].name) + '</th>'; }).join('') + '</tr>';
    [['stat_total', 'total'], ['stat_n', 'n'], ['stat_mean', 'mean'], ['stat_av', 'av'], ['stat_sd', 'sd'], ['stat_cv', 'cv'], ['stat_d', 'd'], ['stat_te', 'te'], ['stat_tea', 'tea']].forEach(function (r) {
      h += '<tr><td>' + esc(t('lembar.' + r[0], { k: C.settings().k })) + '</td>' + act.map(function (i) { return '<td class="calc num">' + (r[1] === 'n' ? st[i].n : fmt(st[i][r[1]], 2)) + '</td>'; }).join('') + '</tr>';
    });
    h += '<tr><td>' + esc(t('lembar.stat_te_tea')) + '</td>' + act.map(function (i) { var o = st[i].teOK; return '<td class="num">' + (o == null ? '–' : o ? chip(t('lembar.memenuhi'), 'ok') : chip(t('lembar.melebihi'), 'bad')) + '</td>'; }).join('') + '</tr></table></div>';
    h += '<p class="small"><b>' + esc(t('spek.judul_lembar')) + '</b> ' + esc(spec.label) + (C.isNum(spec.tea) ? ' · TEa ' + fmt(spec.tea) + '%' : '') + (p.teaOK ? '' : ' · <span class="muted">' + esc(t('spek.belum_verifikasi')) + '</span>') + '</p><p class="small muted">' + esc(t('lembar.catatan_statistik')) + '</p></div>';

    h += '<div class="card"><h3>' + esc(t('lembar.grafik_lj')) + '</h3>' + act.map(function (i) { return '<h4 style="margin-top:10px">' + esc(sh.levels[i].name) + ' · LOT ' + esc(sh.levels[i].lot || '–') + '</h4>' + (E.refValid(sh.levels[i]) ? ljChart(sh, ev, i) : '<p class="muted">' + esc(t('lembar.isi_mean_sd')) + '</p>'); }).join('') + '</div>';

    h += '<div class="card"><h3>' + esc(t('lembar.komentar_judul')) + '</h3><div class="thread">' + (sh.comments.length ? sh.comments.map(msg).join('') : '<p class="muted">' + esc(t('lembar.belum_komentar')) + '</p>') + '</div>' +
      '<textarea class="inp" id="cmt" placeholder="' + esc(t(u.role === 'pembina' ? 'lembar.ph_komentar_pembina' : 'lembar.ph_komentar_binaan')) + '"></textarea><div class="row" style="margin-top:6px"><button class="btn s" data-act="sheetCmt" data-id="' + sh.id + '">' + esc(t('umum.kirim')) + '</button></div></div>';
    return h;
  }
  function msg(m) { return '<div class="msg' + (m.role === 'pembina' ? ' pb' : '') + '"><small>' + esc(m.by) + ' · ' + esc(m.at) + '</small>' + esc(m.text) + '</div>'; }
  function caseCard(c, sh) {
    return '<div class="case-card' + (c.type === 'Tertolak' ? ' rej' : '') + '"><div class="row"><b>' + typeChip(c.type) + ' ' + c.runDate + '</b><span>' + esc(t('kasus.label_indikasi')) + ' <b>' + esc(c.indication) + '</b>' + (c.note ? ' <span class="muted">(' + esc(c.note) + ')</span>' : '') + '</span><span class="muted">' + esc(t('kasus.label_aturan')) + ' ' + esc(rules(c.violations)) + '</span><span class="sp"></span>' + caseChip(c.state) + '<button class="btn s" data-act="nav" data-to="kasus-' + c.id + '">' + esc(t('kasus.buka')) + '</button></div>' +
      '<div class="small" style="margin-top:4px">' + esc(t('kasus.label_prioritas')) + ' ' + esc(c.priority.map(function (p, i) { return (i + 1) + '. ' + aspectName(p.aspect); }).join(' → ')) + (c.scope.length ? ' · ' + esc(c.scope.map(function (x) { return C.scopeText(x, sh); }).join('; ')) : '') + '</div></div>';
  }

  /* ---------- Kasus ---------- */
  function caseList() {
    var f = ui.filter.kasus || 'buka';
    var list = (C.S.cases || []).filter(function (c) { return f === 'semua' || (f === 'buka' ? c.state !== 'Ditutup' && c.state !== 'Dibatalkan' : f === 'kons' ? c.mode === 'konsultasi' && c.state !== 'Ditutup' && c.state !== 'Dibatalkan' : c.state === 'Ditutup'); }).slice().sort(function (a, b) { return a.runDate < b.runDate ? 1 : -1; });
    return '<h2>' + esc(t('kasus.judul_daftar')) + '</h2><div class="tabs">' + ['buka', 'kons', 'tutup', 'semua'].map(function (k) { return '<a data-act="caseFilter" data-v="' + k + '" class="' + (f === k ? 'on' : '') + '">' + esc(t('kasus.tab.' + k)) + '</a>'; }).join('') + '</div>' + caseTable(list);
  }
  function casePage(id) {
    var c = C.byId(C.S.cases, id); if (!c) return '<div class="note bad">' + esc(t('galat.kasus_tidak_ada')) + '</div>';
    var u = C.me(), sh = C.cachedSheet(c.sheetId), mine = u.role === 'binaan', open = c.state !== 'Ditutup' && c.state !== 'Dibatalkan';
    if (!sh || !sh.runs) { if (sh && !ui.loading[sh.id]) { ui.loading[sh.id] = 1; C.loadSheet(sh.id).then(function () { ui.loading[sh.id] = 0; render(); }); } return loadingCard(); }
    if (!ui.caseComments || ui.caseComments.id !== c.id) { ui.caseComments = { id: c.id, list: null }; C.api('case_comments', { id: c.id }).then(function (r) { ui.caseComments = { id: c.id, list: r }; render(); }); }
    var prio = E.priority(c.indication, C.riskScores(c.pkmId, sh.alatId));
    var h = '<div class="small muted">' + esc(C.pkm(c.pkmId).name) + ' · <a href="#lembar-' + sh.id + '">' + esc(C.matrixName(sh)) + '</a></div><div class="row"><h2 class="sp">' + esc(t('kasus.judul', { id: c.id, tgl: c.runDate })) + '</h2>' + caseChip(c.state) + '</div>';
    var steps = ['Terbuka', 'Investigasi', 'Koreksi', 'Verifikasi', 'Ditutup'], si = steps.indexOf(c.state);
    h += '<div class="steps">' + steps.map(function (s, i) { return '<span class="' + (i === si ? 'on' : i < si ? 'done' : '') + '">' + (i + 1) + '. ' + esc(t('kasus.tahap.' + s)) + '</span>'; }).join('') + '</div>';
    h += '<div class="grid g3"><div class="kpi"><span>' + esc(t('kasus.jenis_notif')) + '</span><b style="font-size:1.2rem">' + typeChip(c.type) + '</b></div><div class="kpi"><span>' + esc(t('kasus.indikasi_jenis')) + '</span><b style="font-size:1.2rem">' + esc(c.indication) + '</b>' + (c.note ? '<span>' + esc(c.note) + '</span>' : '') + '</div><div class="kpi"><span>' + esc(t('kasus.aturan_dilanggar')) + '</span><b style="font-size:1.2rem">' + esc(rules(c.violations)) + '</b></div></div>';
    h += '<div class="card" style="margin-top:14px"><p>' + esc(t('penyebab.' + c.indication.replace(/ \+ /g, '_').replace(/ /g, '_'))) + '</p>' + (c.scope.length ? '<p><b>' + esc(t('kasus.cakupan')) + '</b> ' + esc(c.scope.map(function (x) { return C.scopeText(x, sh); }).join('; ')) + '</p>' : '') + '<p class="small muted">' + esc(t('kasus.rujukan')) + '</p></div>';
    h += '<div class="card"><h3>' + esc(t('kasus.prioritas_judul')) + '</h3><p class="small muted">' + esc(t('kasus.prioritas_ket')) + '</p><ol class="prio">' + prio.map(function (p) {
      var lvl = rl(p.risk);
      var link = '#risiko-' + (p.aspect === 'alat' ? 'alat-' + sh.alatId : p.aspect) + (u.role === 'pembina' ? '-p' + c.pkmId : '');
      return '<li><b style="min-width:170px">' + esc(aspectName(p.aspect)) + '</b><span class="small">' + esc(t('kasus.poin', { dasar: p.base, risiko: p.risk, total: p.total })) + '</span><div class="bar"><i style="width:' + Math.min(100, p.total / 65 * 100) + '%"></i></div>' + chip(t('kasus.risiko_label', { t: lvl.t }), lvl.c) + '<span class="sp"></span><a class="small" href="' + link + '">' + esc(t('kasus.lihat_penilaian')) + '</a></li>';
    }).join('') + '</ol></div>';
    h += '<div class="card"><h3>' + esc(t('kasus.langkah_umum')) + '</h3><ol style="margin:0;padding-left:20px">' + t('langkah_paper').map(function (s) { return '<li>' + esc(s) + '</li>'; }).join('') + '</ol></div>';
    if (open && !c.mode) {
      h += '<div class="card"><h3>' + esc(t('kasus.pilih_cara')) + '</h3>' + (mine ? '<div class="row"><button class="btn" data-act="caseMode" data-id="' + c.id + '" data-v="mandiri">' + esc(t('kasus.mode.mandiri')) + '</button><button class="btn o" data-act="caseMode" data-id="' + c.id + '" data-v="konsultasi">' + esc(t('kasus.mode.konsultasi')) + '</button>' + (c.type === 'Peringatan' ? '<button class="btn o" data-act="closeWarn" data-id="' + c.id + '">' + esc(t('kasus.tombol_tutup_peringatan')) + '</button>' : '') + '</div>' : '<p class="muted">' + esc(t('kasus.menunggu_pilihan')) + '</p>') + '</div>';
    } else if (c.mode) h += '<p><b>' + esc(t('kasus.cara')) + '</b> ' + esc(t('kasus.mode.' + c.mode)) + '</p>';
    if (c.mode && c.state !== 'Dibatalkan') {
      var canInv = mine && open && (c.state === 'Investigasi' || c.state === 'Terbuka');
      h += '<div class="card"><h3>' + esc(t('kasus.investigasi_judul')) + '</h3><p class="small muted">' + esc(t('kasus.investigasi_ket')) + '</p>';
      prio.forEach(function (p) {
        h += '<h4 style="margin-top:10px">' + esc(aspectName(p.aspect)) + '</h4>' + t('temuan.' + p.aspect).map(function (f, i) {
          var key = p.aspect + ':' + i, on = c.findings.some(function (x) { return x.key === key; });
          return '<label class="row small" style="margin:3px 0"><input type="checkbox" data-find="' + key + '" ' + (on ? 'checked ' : '') + (canInv ? '' : 'disabled') + '> ' + esc(f[0]) + (on ? ' <span class="muted">→ ' + esc(t('kasus.saran')) + ' ' + esc(f[1]) + '</span>' : '') + '</label>';
        }).join('');
      });
      var other = c.findings.filter(function (x) { return x.key === 'lain'; })[0];
      h += '<label class="f" style="margin-top:8px">' + esc(t('kasus.temuan_lain')) + '<textarea id="findOther" ' + (canInv ? '' : 'disabled') + '>' + esc(other ? other.note : '') + '</textarea></label>';
      if (canInv) h += '<div class="row" style="margin-top:8px"><button class="btn" data-act="saveFind" data-id="' + c.id + '">' + esc(t('kasus.tombol_simpan_temuan')) + '</button></div>';
      h += '</div>';
    }
    if (['Koreksi', 'Verifikasi', 'Ditutup'].indexOf(c.state) >= 0) {
      var canK = mine && c.state === 'Koreksi';
      h += '<div class="card"><h3>' + esc(t('kasus.koreksi_judul')) + '</h3>' + c.corrections.map(function (k, i) {
        return '<div class="row small" style="margin:4px 0"><input type="checkbox" data-corr="' + i + '" ' + (k.done ? 'checked ' : '') + (canK ? '' : 'disabled') + '><span style="min-width:300px">' + esc(k.text) + '<br><span class="muted">' + esc(k.from) + '</span></span><input class="inp sp" data-corrnote="' + i + '" placeholder="' + esc(t('kasus.ph_keterangan')) + '" value="' + esc(k.note || '') + '" ' + (canK ? '' : 'disabled') + '></div>';
      }).join('') + (canK ? '<div class="row" style="margin-top:8px"><input class="inp sp" id="corrNew" placeholder="' + esc(t('kasus.ph_tindakan_lain')) + '"><button class="btn o s" data-act="saveCorr" data-id="' + c.id + '" data-finish="0">' + esc(t('umum.tambah')) + '</button><button class="btn" data-act="saveCorr" data-id="' + c.id + '" data-finish="1">' + esc(t('kasus.tombol_koreksi_selesai')) + '</button></div>' : '') + '</div>';
    }
    if (c.state === 'Verifikasi' || c.state === 'Ditutup') {
      var ev = C.evalSheet(sh).filter(function (e) { return e.run.date >= c.runDate && String(e.run.id) !== String(c.runId) && e.status === 'DITERIMA'; });
      h += '<div class="card"><h3>' + esc(t('kasus.verifikasi_judul')) + '</h3>' + (c.state === 'Ditutup' ? '<p>' + esc(t('kasus.diverifikasi', { tgl: c.verifyDate || '–' })) + '</p>' :
        (ev.length ? (mine ? '<div class="row"><select class="inp" id="verRun">' + ev.map(function (e) { return opt(e.run.id, t('kasus.opsi_run', { tgl: e.run.date })); }).join('') + '</select><button class="btn" data-act="closeCase" data-id="' + c.id + '">' + esc(t('kasus.tombol_tutup')) + '</button></div>' : '<p class="muted">' + esc(t('kasus.menunggu_tutup')) + '</p>') : '<p class="muted">' + esc(t('kasus.belum_run_verifikasi')) + '</p>')) + '</div>';
    }
    var cl = ui.caseComments && ui.caseComments.list;
    h += '<div class="card"><h3>' + esc(t('kasus.diskusi')) + '</h3><div class="thread">' + (cl == null ? '<p class="muted">' + esc(t('umum.memuat')) + '</p>' : cl.length ? cl.map(msg).join('') : '<p class="muted">' + esc(t('kasus.belum_pesan')) + '</p>') + '</div>' +
      '<textarea class="inp" id="caseCmt" placeholder="' + esc(t('kasus.ph_pesan')) + '"></textarea><div class="row" style="margin-top:6px"><button class="btn s" data-act="caseCmt" data-id="' + c.id + '">' + esc(t('umum.kirim')) + '</button></div></div>';
    h += '<div class="card"><h3>' + esc(t('kasus.riwayat')) + '</h3>' + c.log.map(function (l) { return '<div class="small"><span class="muted">' + esc(l.at) + ' · ' + esc(l.by) + '</span> — ' + esc(l.text) + '</div>'; }).join('') + '</div>';
    return h;
  }

  /* ---------- Manajemen Risiko ---------- */
  function riskHome(pkmId) {
    var alats = C.S.alats.filter(function (a) { return String(a.pkmId) === String(pkmId); });
    var sfx = C.me().role === 'pembina' ? '-p' + pkmId : '';
    var h = (sfx ? '' : (C.setupDone() ? '' : setupCard(true)) + '<h2>' + esc(t('menu.risiko')) + '</h2>') + '<p class="muted">' + esc(t('risiko.pengantar')) + '</p>';
    h += '<div class="card"><h3>' + esc(aspectName('alat')) + ' <span class="muted small">' + esc(t('risiko.satu_per_alat')) + '</span></h3>' + (alats.length ? '<div class="tbl"><table><tr><th>' + esc(t('risiko.kol_alat')) + '</th><th>' + esc(t('lembar.kol_kategori')) + '</th><th>' + esc(t('risiko.kol_status_pakai')) + '</th><th>' + esc(t('risiko.kol_terakhir')) + '</th><th class="num">' + esc(t('risiko.kol_skor')) + '</th><th>' + esc(t('risiko.kol_tingkat')) + '</th><th></th></tr>' + alats.map(function (a) {
      var r = C.latestRisk(pkmId, 'alat', a.id), m = C.riskMax(r), l = rl(m);
      return '<tr><td>' + esc(a.name) + '<div class="small muted">' + esc(a.merk) + '</div></td><td>' + esc(a.cat) + '</td><td>' + (a.active ? chip(t('risiko.dipakai'), 'ok') : chip(t('risiko.tidak_dipakai'), 'muted')) + '</td><td>' + (r ? r.date : '–') + '</td><td class="num">' + (m || '–') + '</td><td>' + chip(l.t, l.c) + '</td><td>' + (a.active ? '<button class="btn s" data-act="nav" data-to="risiko-alat-' + a.id + sfx + '">' + esc(t(r ? 'risiko.lihat_nilai_ulang' : 'risiko.nilai')) + '</button>' : '<span class="small muted">' + esc(t('risiko.tidak_dinilai')) + '</span>') + '</td></tr>';
    }).join('') + '</table></div>' : '<p class="muted">' + esc(t('risiko.belum_ada_alat')) + '</p>') + (sfx ? '' : '<p class="small"><a href="#sd-alat">' + esc(t('menu.sd_alat')) + '</a></p>') + '</div>';
    h += '<div class="grid g3">' + ['bahan', 'lingkungan', 'personel'].map(function (a) {
      var r = C.latestRisk(pkmId, a), m = C.riskMax(r), l = rl(m);
      return '<div class="card"><h3>' + esc(aspectName(a)) + '</h3><p>' + chip(l.t, l.c) + ' <span class="muted small">' + esc(t('risiko.skor_tertinggi', { s: m || '–', tgl: r ? r.date : t('risiko.belum_dinilai') })) + '</span></p><button class="btn s" data-act="nav" data-to="risiko-' + a + sfx + '">' + esc(t(r ? 'risiko.lihat_nilai_ulang' : 'risiko.nilai')) + '</button></div>';
    }).join('') + '</div>';
    return h;
  }
  function riskForm(arg) {
    var u = C.me(), parts = (arg || '').split('-'), aspect = parts[0], alatId = null, pkmId = u.pkmId;
    if (QCEngine.ASPECTS.indexOf(aspect) < 0) return denied();
    if (aspect === 'alat') { alatId = parts[1]; parts = parts.slice(2); } else parts = parts.slice(1);
    if (parts[0] && parts[0].charAt(0) === 'p') pkmId = parts[0].slice(1);
    if (alatId) { var al = C.byId(C.S.alats, alatId); if (!al) return denied(); pkmId = al.pkmId; }
    if (u.role === 'binaan' && String(pkmId) !== String(u.pkmId)) return denied();
    var last = C.latestRisk(pkmId, aspect, alatId), ed = u.role === 'binaan';
    var key = aspect + ':' + alatId + ':' + pkmId;
    if (!ui.riskDraft || ui.riskDraft.key !== key) ui.riskDraft = { key: key, aspect: aspect, alatId: alatId, items: last ? JSON.parse(JSON.stringify(last.items)) : t('risiko.butir.' + aspect).map(function (x) { return { text: x, L: 1, S: 1, control: '', plan: '' }; }) };
    var items = ui.riskDraft.items;
    var h = '<div class="small muted">' + esc(C.pkm(pkmId).name) + ' · <a href="#' + (u.role === 'pembina' ? 'pkm-' + pkmId : 'risiko') + '">' + esc(t('menu.risiko')) + '</a></div><h2>' + esc(aspectName(aspect)) + (alatId ? ' · ' + esc(C.alat(alatId).name) : '') + '</h2>';
    h += '<p class="muted small">' + esc(last ? t('risiko.terakhir_oleh', { tgl: last.date, oleh: last.by }) : t('risiko.belum_pernah')) + ' ' + esc(t('risiko.batas_tingkat')) + '</p>';
    h += '<div class="tbl"><table><tr><th>' + esc(t('risiko.kol_risiko')) + '</th><th>' + esc(t('risiko.kol_kemungkinan')) + '</th><th>' + esc(t('risiko.kol_dampak')) + '</th><th class="num">' + esc(t('risiko.kol_skor_item')) + '</th><th>' + esc(t('risiko.kol_tingkat')) + '</th><th>' + esc(t('risiko.kol_pengendalian')) + '</th><th>' + esc(t('risiko.kol_rencana')) + '</th></tr>' + items.map(function (it, i) {
      var s = it.L * it.S, l = rl(s);
      var sel = function (k) { var o = ''; for (var v = 1; v <= 5; v++) o += opt(v, v, it[k] === v); return '<select class="inp" data-risk="' + i + '" data-k="' + k + '" ' + (ed ? '' : 'disabled') + '>' + o + '</select>'; };
      return '<tr><td style="white-space:normal;min-width:220px">' + esc(it.text) + '</td><td>' + sel('L') + '</td><td>' + sel('S') + '</td><td class="num"><b>' + s + '</b></td><td>' + chip(l.t, l.c) + '</td><td><input class="cell" data-risk="' + i + '" data-k="control" value="' + esc(it.control) + '" ' + (ed ? '' : 'disabled') + '></td><td><input class="cell" data-risk="' + i + '" data-k="plan" value="' + esc(it.plan) + '" ' + (ed ? '' : 'disabled') + '></td></tr>';
    }).join('') + '</table></div>';
    if (ed) h += '<div class="row" style="margin-top:10px"><input class="inp sp" id="riskNew" placeholder="' + esc(t('risiko.ph_tambah')) + '"><button class="btn o s" data-act="riskAdd">' + esc(t('umum.tambah')) + '</button><button class="btn" data-act="riskSave">' + esc(t('risiko.tombol_simpan')) + '</button></div>';
    var hist = (C.S.risks || []).filter(function (r) { return String(r.pkmId) === String(pkmId) && r.aspect === aspect && (aspect !== 'alat' || String(r.alatId) === String(alatId)); });
    h += '<div class="card" style="margin-top:14px"><h3>' + esc(t('risiko.riwayat')) + '</h3>' + (hist.length ? hist.map(function (r) { var m = C.riskMax(r); return '<div class="small">' + r.date + ' · ' + esc(r.by) + ' · ' + esc(t('risiko.skor_tertinggi_n', { s: m })) + ' ' + chip(rl(m).t, rl(m).c) + '</div>'; }).join('') : '<p class="muted">–</p>') + '</div>';
    return h;
  }

  /* ---------- Pembina ---------- */
  function pantau() {
    var rows = C.S.pkms.map(function (p) {
      var shs = C.S.sheets.filter(function (s) { return String(s.pkmId) === String(p.id) && s.status === 'aktif'; });
      var oc = openCases(function (c) { return String(c.pkmId) === String(p.id); }), kons = oc.filter(function (c) { return c.mode === 'konsultasi'; }).length;
      var rej = shs.reduce(function (a, s) { return a + ((s.summary || {}).rejMonth || 0); }, 0), ni = shs.filter(function (s) { return C.notIdeal(s).length; }).length;
      return '<tr class="clk" data-act="nav" data-to="pkm-' + p.id + '"><td><b>' + esc(p.name) + '</b></td><td class="num">' + shs.length + '</td><td class="num">' + rej + '</td><td class="num">' + (oc.length ? chip(oc.length, 'bad') : 0) + '</td><td class="num">' + (kons ? chip(kons, 'info') : 0) + '</td><td class="num">' + ni + '</td></tr>';
    }).join('');
    return '<h2>' + esc(t('pantau.judul')) + '</h2><div class="tbl"><table><tr><th>' + esc(t('kasus.kol_pkm')) + '</th><th class="num">' + esc(t('pantau.lembar_aktif')) + '</th><th class="num">' + esc(t('pantau.tertolak_bulan')) + '</th><th class="num">' + esc(t('pantau.kasus_terbuka')) + '</th><th class="num">' + esc(t('pantau.konsultasi')) + '</th><th class="num">' + esc(t('pantau.tidak_ideal')) + '</th></tr>' + rows + '</table></div>';
  }
  function pkmDetail(id) {
    var p = C.pkm(id), shs = C.S.sheets.filter(function (s) { return String(s.pkmId) === String(id); });
    var h = '<div class="small"><a href="#pantau">← ' + esc(t('pantau.judul')) + '</a></div><div class="row"><h2 class="sp">' + esc(p.name) + '</h2><button class="btn o s" data-act="sdOpen" data-pkm="' + p.id + '">' + esc(t('menu.sd')) + '</button></div>';
    h += '<div class="card"><h3>' + esc(t('pantau.lembar_qc')) + '</h3>' + sheetTable(shs) + '</div>';
    h += '<div class="card"><h3>' + esc(t('pantau.kasus')) + '</h3>' + caseTable((C.S.cases || []).filter(function (c) { return String(c.pkmId) === String(id) && c.state !== 'Dibatalkan'; }).slice().reverse()) + '</div>';
    h += '<div class="card"><h3>' + esc(t('menu.risiko')) + '</h3>' + riskHome(id) + '</div>';
    return h;
  }

  /* ---------- Alur wajib Binaan ---------- */
  function setupCard(compact) {
    var st = C.S.setup || {}, step = function (ok, txt, link, extra) { return '<li class="row" style="margin:6px 0">' + chip(t(ok ? 'sd.selesai' : 'sd.belum'), ok ? 'ok' : 'warn') + '<span class="sp">' + esc(txt) + (extra ? ' <span class="small muted">(' + esc(extra) + ')</span>' : '') + '</span>' + (link && !ok ? '<a class="btn s" href="#' + link + '">' + esc(t('sd.buka')) + '</a>' : '') + '</li>'; };
    var miss = (st.riskMissing || []).map(aspectName).concat((st.alatRiskMissing || []).map(function (id) { return C.alat(id).name; }));
    return '<div class="card"' + (compact ? '' : ' style="max-width:860px"') + '><h3>' + esc(t('sd.setup_judul')) + '</h3><p class="small muted">' + esc(t('sd.setup_ket')) + '</p><ol style="list-style:none;padding:0;margin:0">' +
      step(true, t('sd.langkah_sandi')) +
      step(st.sdm, t('sd.langkah_sdm'), 'sd-sdm', st.sdmNoFoto ? t('sd.kurang_foto', { n: st.sdmNoFoto }) : '') +
      step(st.alat, t('sd.langkah_alat'), 'sd-alat') +
      step(st.risk, t('sd.langkah_risiko'), 'risiko', miss.length && st.alat ? t('sd.kurang_risiko', { daftar: miss.join(', ') }) : '') + '</ol></div>';
  }

  /* ---------- Sumber Daya ---------- */
  function sdOwner() { var u = C.me(); if (u.role === 'binaan') return String(u.pkmId); if (ui.sdOwner != null) return String(ui.sdOwner); return u.role === 'pembina' ? '0' : String((C.S.pkms[0] || { id: 0 }).id); }
  function sdEditable(owner) { var u = C.me(); return (u.role === 'binaan' && String(u.pkmId) === String(owner)) || (u.role === 'pembina' && String(owner) === '0'); }
  function sdHead(sub, owner) {
    var u = C.me(), h = (u.role === 'binaan' && !C.setupDone() ? setupCard(true) : '') + '<h2>' + esc(t('menu.sd_' + sub)) + '</h2><p class="muted">' + esc(t('sd.' + sub + '_sub')) + '</p>';
    if (u.role !== 'binaan') h += '<div class="row" style="margin-bottom:10px"><label class="f" style="max-width:360px">' + esc(t('sd.pemilik')) + '<select class="inp" data-sdowner="1">' + opt('0', t('sd.labkesmas'), owner === '0') + C.S.pkms.map(function (p) { return opt(p.id, p.name, String(p.id) === owner); }).join('') + '</select></label></div>';
    if (!sdEditable(owner)) h += '<div class="note">' + esc(t('sd.hanya_lihat')) + '</div>';
    return h;
  }
  function sdPage(arg) {
    var p = (arg || 'sdm').split('-'), sub = p[0];
    if (sub === 'sdm' && p[1]) return personelPage(p[1]);
    if (sub === 'alat') return sdAlat();
    if (sub === 'bhp') return sdBhp();
    return sdSdm();
  }
  function fotoImg(fid, w) { return fid ? '<img src="foto.php?f=' + fid + '" alt="" style="width:' + w + 'px;height:' + Math.round(w * 4 / 3) + 'px;object-fit:contain;border:1px solid var(--line);background:#fff;display:block">' : '<div style="width:' + w + 'px;height:' + Math.round(w * 4 / 3) + 'px;border:1px dashed var(--line)"></div>'; }
  function sdSdm() {
    var owner = sdOwner(), ed = sdEditable(owner), list = (C.S.personel || []).filter(function (x) { return String(x.pkmId) === owner; });
    var h = sdHead('sdm', owner) + '<p class="small muted">' + esc(t('sd.sdm_ket')) + '</p>';
    if (ed) h += '<div class="card"><h3>' + esc(t('sd.personel_baru')) + '</h3><div class="form"><label class="f">' + esc(t('sd.nama')) + '<input class="inp" id="nsdN"></label><label class="f">' + esc(t('sd.jabatan')) + '<input class="inp" id="nsdJ" placeholder="' + esc(t('sd.ph_jabatan')) + '"></label><div><button class="btn" data-act="sdmAdd">' + esc(t('sd.tambah')) + '</button></div></div></div>';
    if (!list.length) return h + '<p class="muted">' + esc(t('sd.belum_personel')) + '</p>';
    return h + '<div class="tbl"><table><tr><th>' + esc(t('sd.foto')) + '</th><th>' + esc(t('sd.nama')) + '</th><th>' + esc(t('sd.jabatan')) + '</th><th>' + esc(t('sd.nip')) + '</th><th>' + esc(t('sd.pendidikan')) + '</th><th>' + esc(t('sd.str_no')) + '</th><th>' + esc(t('sd.status')) + '</th></tr>' + list.map(function (x) {
      return '<tr><td>' + fotoImg(x.fotoId, 45) + '</td><td><a href="#sd-sdm-' + x.id + '"><b>' + esc(x.name) + '</b></a>' + (x.fotoId ? '' : ' ' + chip(t('sd.belum_foto'), x.active ? 'bad' : 'muted')) + '</td><td>' + esc(x.jabatan) + '</td><td>' + esc(x.nip) + '</td><td>' + esc(x.pendidikan) + '</td><td>' + esc(x.strNo) + (x.strBerlaku ? '<div class="small muted">' + esc(x.strBerlaku) + '</div>' : '') + '</td><td><label class="small"><input type="checkbox" data-act="sdmActive" data-id="' + x.id + '" ' + (x.active ? 'checked ' : '') + (ed ? '' : 'disabled') + '> ' + esc(t('sd.aktif')) + '</label></td></tr>';
    }).join('') + '</table></div>';
  }
  function personelPage(id) {
    var x = C.byId(C.S.personel, id); if (!x) return denied();
    var ed = sdEditable(String(x.pkmId)), cur = x.fotos[0];
    var fld = function (k, key, ph) { return '<label class="f">' + esc(t(key)) + '<input class="inp" data-sdm="' + x.id + '" data-k="' + k + '" value="' + esc(x[k]) + '"' + (ph ? ' placeholder="' + esc(t(ph)) + '"' : '') + (ed ? '' : ' disabled') + '></label>'; };
    var h = '<div class="small"><a href="#sd-sdm">← ' + esc(t('sd.kembali_sdm')) + '</a> · ' + esc(C.ownerName(x.pkmId)) + '</div><div class="row"><h2 class="sp">' + esc(x.name) + '</h2>' + (x.active ? chip(t('sd.aktif'), 'ok') : chip(t('sd.nonaktif'), 'muted')) + '</div>';
    h += '<div class="grid g2" style="grid-template-columns:220px 1fr;align-items:start"><div class="card">' + fotoImg(x.fotoId, 180) + (cur ? '<p class="small muted">' + esc(t('sd.foto_diunggah', { tgl: cur.at, oleh: cur.by })) + '</p>' : '<p>' + chip(t('sd.belum_foto'), 'bad') + '</p>') +
      (ed ? '<label class="f" style="margin-top:8px">' + esc(t(cur ? 'sd.ganti_foto' : 'sd.unggah_foto')) + '<input type="file" id="sdmFoto" data-id="' + x.id + '" accept="image/jpeg,image/png"></label><p class="small muted">' + esc(t('sd.foto_ket')) + '</p>' : '') + '</div>';
    h += '<div class="card"><div class="form">' + fld('name', 'sd.nama') + fld('nip', 'sd.nip') + fld('jabatan', 'sd.jabatan', 'sd.ph_jabatan') + fld('pendidikan', 'sd.pendidikan') + fld('strNo', 'sd.str_no') + fld('strBerlaku', 'sd.str_berlaku', 'sd.ph_str_berlaku') + fld('sipNo', 'sd.sip_no') + '</div></div></div>';
    h += '<div class="card"><h3>' + esc(t('sd.pelatihan')) + '</h3>' + (x.pelatihan.length ? '<div class="tbl"><table><tr><th>' + esc(t('sd.pel_nama')) + '</th><th>' + esc(t('sd.pel_oleh')) + '</th><th>' + esc(t('sd.pel_tahun')) + '</th><th></th></tr>' + x.pelatihan.map(function (p, i) { return '<tr><td>' + esc(p.name) + '</td><td>' + esc(p.by) + '</td><td>' + esc(p.tahun) + '</td><td>' + (ed ? '<button class="btn o s" data-act="trainDel" data-id="' + x.id + '" data-i="' + i + '">' + esc(t('sd.hapus')) + '</button>' : '') + '</td></tr>'; }).join('') + '</table></div>' : '<p class="muted">' + esc(t('sd.belum_pelatihan')) + '</p>') +
      (ed ? '<div class="form" style="margin-top:10px"><label class="f">' + esc(t('sd.pel_nama')) + '<input class="inp" id="trN"></label><label class="f">' + esc(t('sd.pel_oleh')) + '<input class="inp" id="trB"></label><label class="f">' + esc(t('sd.pel_tahun')) + '<input class="inp" id="trT" inputmode="numeric" maxlength="4"></label><div><button class="btn s" data-act="trainAdd" data-id="' + x.id + '">' + esc(t('sd.tambah')) + '</button></div></div>' : '') + '</div>';
    if (x.fotos.length > 1) h += '<div class="card"><h3>' + esc(t('sd.riwayat_foto')) + '</h3><div class="row">' + x.fotos.slice(1).map(function (f) { return '<div>' + fotoImg(f.id, 60) + '<div class="small muted">' + esc(f.at.slice(0, 10)) + '</div></div>'; }).join('') + '</div></div>';
    return h;
  }
  function alatTable(list, ed, showOwner) {
    if (!list.length) return '<p class="muted">' + esc(t('sd.belum_alat')) + '</p>';
    var cell = function (a, k, type, w) { return '<td' + (ed ? ' class="in"' : '') + '><input class="cell" style="min-width:' + (w || 110) + 'px" ' + (type ? 'type="' + type + '" ' : '') + 'data-alat="' + a.id + '" data-k="' + k + '" value="' + esc(a[k]) + '"' + (ed ? '' : ' disabled') + '></td>'; };
    var sel = function (a, k, vals, key) { return '<td' + (ed ? ' class="in"' : '') + '><select class="cell" data-alat="' + a.id + '" data-k="' + k + '"' + (ed ? '' : ' disabled') + '>' + (a[k] ? '' : opt('', t('lembar.pilih'), true)) + vals.map(function (v) { return opt(v, t(key + '.' + v), a[k] === v); }).join('') + '</select></td>'; };
    return '<div class="tbl"><table><tr>' + (showOwner ? '<th>' + esc(t('sd.pemilik')) + '</th>' : '') + '<th>' + esc(t('sd.nama_alat')) + '</th><th>' + esc(t('sd.merk')) + '</th><th>' + esc(t('sd.tipe')) + '</th><th>' + esc(t('sd.no_seri')) + '</th><th>' + esc(t('sd.kategori')) + '</th><th>' + esc(t('sd.prinsip')) + '</th><th>' + esc(t('sd.tahun')) + '</th><th>' + esc(t('sd.kondisi')) + '</th><th>' + esc(t('sd.kal_terakhir')) + '</th><th>' + esc(t('sd.kal_berikut')) + '</th><th>' + esc(t('sd.dipakai')) + '</th></tr>' + list.map(function (a) {
      var noRisk = a.active && String(a.pkmId) !== '0' && !C.latestRisk(a.pkmId, 'alat', a.id) && C.me().role === 'binaan';
      return '<tr>' + (showOwner ? '<td>' + esc(C.ownerName(a.pkmId)) + '</td>' : '') + cell(a, 'name', '', 180) + cell(a, 'merk') + cell(a, 'tipe') + cell(a, 'noSeri') + sel(a, 'cat', ['Kimia', 'Hematologi', 'Lainnya'], 'sd.kat') + cell(a, 'prinsip', '', 140) + cell(a, 'tahun', '', 60) + sel(a, 'kondisi', ['baik', 'perbaikan', 'rusak'], 'sd.kondisi_opsi') + cell(a, 'kalTerakhir', 'date') + cell(a, 'kalBerikut', 'date') +
        '<td><label class="small"><input type="checkbox" data-act="alatActive" data-id="' + a.id + '" ' + (a.active ? 'checked ' : '') + (ed ? '' : 'disabled') + '> ' + esc(t('sd.dipakai')) + '</label>' + (noRisk ? '<div>' + chip(t('sd.risiko_alat_belum'), 'warn') + '</div>' : '') + '</td></tr>';
    }).join('') + '</table></div>';
  }
  function sdAlat() {
    var owner = sdOwner(), ed = sdEditable(owner), list = C.S.alats.filter(function (a) { return String(a.pkmId) === owner; });
    var h = sdHead('alat', owner) + '<p class="small muted">' + esc(t('sd.alat_ket')) + '</p>';
    if (ed) h += '<div class="card"><h3>' + esc(t('sd.alat_baru')) + '</h3><div class="form"><label class="f">' + esc(t('sd.nama_alat')) + '<input class="inp" id="naN"></label><label class="f">' + esc(t('sd.merk')) + '<input class="inp" id="naM"></label><label class="f">' + esc(t('sd.tipe')) + '<input class="inp" id="naT"></label><label class="f">' + esc(t('sd.kategori')) + '<select class="inp" id="naC">' + ['Kimia', 'Hematologi', 'Lainnya'].map(function (v) { return opt(v, t('sd.kat.' + v)); }).join('') + '</select></label><div><button class="btn" data-act="alatAdd">' + esc(t('sd.tambah')) + '</button></div></div></div>';
    return h + alatTable(list, ed, false);
  }
  function sdBhp() {
    var owner = sdOwner(), ed = sdEditable(owner), today = C.today();
    var all = (C.S.bhp || []).filter(function (b) { return String(b.pkmId) === owner; });
    var h = sdHead('bhp', owner) + '<p class="small muted">' + esc(t('sd.bhp_ket')) + '</p>';
    if (ed) h += '<div class="card"><h3>' + esc(t('sd.bhp_baru')) + '</h3><div class="form"><label class="f">' + esc(t('sd.jenis')) + '<select class="inp" id="nbJ">' + opt('kontrol', t('sd.jenis_opsi.kontrol')) + opt('reagen', t('sd.jenis_opsi.reagen')) + '</select></label><label class="f">' + esc(t('sd.nama_produk')) + '<input class="inp" id="nbN"></label><label class="f">' + esc(t('sd.produsen')) + '<input class="inp" id="nbP"></label><label class="f">' + esc(t('sd.kategori')) + '<select class="inp" id="nbC">' + opt('Kimia', t('sd.kat.Kimia')) + opt('Hematologi', t('sd.kat.Hematologi')) + '</select></label><label class="f">' + esc(t('sd.level')) + '<input class="inp" id="nbL" placeholder="' + esc(t('sd.ph_level')) + '"></label><label class="f">' + esc(t('sd.kemasan')) + '<input class="inp" id="nbK"></label><label class="f">' + esc(t('sd.lot')) + '<input class="inp" id="nbLot"></label><label class="f">' + esc(t('sd.ed')) + '<input class="inp" type="date" id="nbE"></label><label class="f">' + esc(t('sd.suhu')) + '<input class="inp" id="nbS" placeholder="' + esc(t('sd.ph_suhu')) + '"></label><div><button class="btn" data-act="bhpAdd">' + esc(t('sd.tambah')) + '</button></div></div></div>';
    h += '<label class="small row" style="margin:6px 0"><input type="checkbox" data-act="bhpHabis" ' + (ui.showHabis ? 'checked' : '') + '> ' + esc(t('sd.tampilkan_habis')) + '</label>';
    ['kontrol', 'reagen'].forEach(function (j) {
      var list = all.filter(function (b) { return b.jenis === j && (ui.showHabis || b.status !== 'habis'); });
      h += '<div class="card"><h3>' + esc(t('sd.judul_jenis.' + j)) + '</h3>';
      if (!list.length) { h += '<p class="muted">' + esc(t('sd.belum_bhp')) + '</p></div>'; return; }
      h += '<div class="tbl"><table><tr><th>' + esc(t('sd.nama_produk')) + '</th><th>' + esc(t('sd.produsen')) + '</th><th>' + esc(t('sd.kategori')) + '</th>' + (j === 'kontrol' ? '<th>' + esc(t('sd.level')) + '</th>' : '') + '<th>' + esc(t('sd.kemasan')) + '</th><th>' + esc(t('sd.lot')) + '</th><th>' + esc(t('sd.ed')) + '</th><th>' + esc(t('sd.suhu')) + '</th><th>' + esc(t('sd.status')) + '</th><th></th></tr>' + list.map(function (b) {
        var lockId = !ed || b.used;
        var cell = function (k, lk, w) { return '<td' + (lk ? '' : ' class="in"') + '><input class="cell" style="min-width:' + (w || 100) + 'px" data-bhp="' + b.id + '" data-k="' + k + '" value="' + esc(b[k]) + '"' + (lk ? ' disabled' : '') + '></td>'; };
        return '<tr' + (b.status === 'habis' ? ' style="opacity:.6"' : '') + '>' + cell('name', lockId, 180) + cell('produsen', !ed, 130) + '<td>' + esc(t('sd.kat.' + b.cat)) + '</td>' + (j === 'kontrol' ? cell('level', lockId) : '') + cell('kemasan', !ed) + cell('lot', lockId) +
          '<td' + (lockId ? '' : ' class="in"') + '><input class="cell" type="date" data-bhp="' + b.id + '" data-k="ed" value="' + esc(b.ed) + '"' + (lockId ? ' disabled' : '') + '>' + (b.ed && b.ed < today && b.status !== 'habis' ? chip(t('sd.ed_lewat'), 'bad') : '') + '</td>' + cell('suhu', !ed, 120) +
          '<td' + (ed ? ' class="in"' : '') + '><select class="cell" data-bhp="' + b.id + '" data-k="status"' + (ed ? '' : ' disabled') + '>' + opt('tersedia', t('sd.status_opsi.tersedia'), b.status === 'tersedia') + opt('habis', t('sd.status_opsi.habis'), b.status === 'habis') + '</select>' + (b.used ? '<div>' + chip(t('sd.dipakai_qc'), 'info') + '</div>' : '') + '</td>' +
          '<td style="white-space:nowrap">' + (ed ? '<button class="btn o s" data-act="bhpNewLot" data-id="' + b.id + '">' + esc(t('sd.lot_baru')) + '</button>' + (b.used ? '' : ' <button class="btn o s" data-act="bhpDel" data-id="' + b.id + '">' + esc(t('sd.hapus')) + '</button>') : '') + '</td></tr>';
      }).join('') + '</table></div></div>';
    });
    return h;
  }

  /* ---------- Kop ---------- */
  function kopPage() {
    var u = C.me(), k = C.S.kop, info = k && k.info;
    var h = '<h2>' + esc(t('menu.kop')) + '</h2><p class="muted">' + esc(u.role === 'pembina' ? t('kop.ket_pembina') : t('kop.ket_binaan', { pkm: C.pkm(u.pkmId).name })) + '</p>';
    h += '<div class="card"><h3>' + esc(t('kop.ukuran_ideal')) + '</h3><p><b>' + esc(t('kop.ukuran')) + '</b></p><p class="small muted">' + esc(t('kop.ket_ukuran')) + '</p></div>';
    h += '<div class="card"><h3>' + esc(t('kop.saat_ini')) + '</h3>' + (k ? '<div style="border:1px dashed var(--line);max-width:760px"><img src="kop.php?k=' + encodeURIComponent(k.key) + '&v=' + k.v + '" alt="' + esc(t('kop.alt')) + '" style="width:100%;display:block"></div>' +
      '<p class="small muted">' + esc(t('kop.info', { tgl: k.updated, oleh: info.by, w: info.ow, h: info.oh })) + (info.fitted ? ' ' + esc(t('kop.disesuaikan')) : '') + (info.scale > 2 ? ' <b>' + esc(t('kop.resolusi_rendah')) + '</b>' : '') + '</p>' : '<p class="muted">' + esc(t('kop.belum_ada')) + '</p>') +
      '<label class="f" style="margin-top:12px">' + esc(t(k ? 'kop.ganti' : 'kop.unggah')) + '<input type="file" id="kopFile" accept="image/png"></label></div>';
    return h;
  }

  /* ---------- Admin ---------- */
  function pengguna() {
    var us = C.S.users || [];
    var pkOpts = function (sel) { return C.S.pkms.map(function (p) { return opt(p.id, p.name, String(p.id) === String(sel)); }).join(''); };
    var h = '<h2>' + esc(t('menu.pengguna')) + '</h2><p class="muted">' + esc(t('admin.pengguna_ket')) + '</p>';
    h += '<div class="card"><h3>' + esc(t('admin.tambah_akun')) + '</h3><div class="form"><label class="f">' + esc(t('admin.peran')) + '<select id="nuRole">' + opt('binaan', t('peran.binaan')) + opt('pembina', t('peran.pembina')) + '</select></label><label class="f">' + esc(t('admin.nama')) + '<input class="inp" id="nuName"></label><label class="f">' + esc(t('admin.username')) + '<input class="inp" id="nuUser" placeholder="' + esc(t('admin.ph_username')) + '"></label><label class="f">' + esc(t('admin.pkm_binaan')) + '<select id="nuPkm">' + pkOpts() + '</select></label><label class="f">' + esc(t('admin.nip')) + '<input class="inp" id="nuNip"></label><label class="f">' + esc(t('admin.jabatan')) + '<input class="inp" id="nuJab"></label><div><button class="btn" data-act="userAdd">' + esc(t('admin.tombol_tambah_akun')) + '</button></div></div><p class="small muted">' + esc(t('admin.ket_tambah_akun')) + '</p></div>';
    h += '<div class="tbl"><table><tr><th>' + esc(t('admin.peran')) + '</th><th>' + esc(t('admin.nama')) + '</th><th>' + esc(t('admin.username')) + '</th><th>' + esc(t('admin.pkm_binaan')) + '</th><th>' + esc(t('admin.nip')) + '</th><th>' + esc(t('admin.jabatan')) + '</th><th>' + esc(t('admin.status')) + '</th><th>' + esc(t('admin.masuk_terakhir')) + '</th><th></th></tr>' + us.map(function (x) {
      var adm = x.role === 'admin';
      return '<tr><td>' + esc(t('peran.' + x.role)) + '</td><td><input class="cell" data-user="' + x.id + '" data-k="nama" value="' + esc(x.name) + '"></td><td><input class="cell" data-user="' + x.id + '" data-k="username" value="' + esc(x.username) + '"' + (adm ? ' disabled' : '') + '></td><td>' + (x.role === 'binaan' ? '<select class="inp" data-user="' + x.id + '" data-k="pkmId">' + pkOpts(x.pkmId) + '</select>' : '–') + '</td><td><input class="cell" data-user="' + x.id + '" data-k="nip" value="' + esc(x.nip) + '"></td><td><input class="cell" data-user="' + x.id + '" data-k="jabatan" value="' + esc(x.jabatan) + '"></td><td>' + (adm ? chip(t('admin.aktif'), 'ok') : '<label class="small"><input type="checkbox" data-act="userActive" data-id="' + x.id + '" ' + (x.active ? 'checked' : '') + '> ' + esc(t('admin.aktif')) + '</label>') + (x.mustChange ? ' ' + chip(t('admin.belum_ganti_sandi'), 'warn') : '') + '</td><td class="small">' + esc(x.lastLogin || '–') + '</td><td>' + (adm ? '' : '<button class="btn o s" data-act="resetPw" data-id="' + x.id + '">' + esc(t('admin.reset_sandi')) + '</button>') + '</td></tr>';
    }).join('') + '</table></div>';
    return h;
  }
  function refPkm() {
    return '<h2>' + esc(t('menu.ref_pkm')) + '</h2><p class="muted">' + esc(t('admin.pkm_ket')) + '</p><div class="card"><div class="form"><label class="f">' + esc(t('admin.nama_pkm_baru')) + '<input class="inp" id="npkm"></label><div><button class="btn" data-act="pkmAdd">' + esc(t('umum.tambah')) + '</button></div></div></div><div class="tbl"><table><tr><th>' + esc(t('admin.kode')) + '</th><th>' + esc(t('admin.nama_pkm')) + '</th><th class="num">' + esc(t('admin.jumlah_akun')) + '</th></tr>' + C.S.pkms.map(function (p) {
      var n = (C.S.users || []).filter(function (x) { return String(x.pkmId) === String(p.id); }).length;
      return '<tr><td>' + esc(p.kode) + '</td><td><input class="cell" data-pkm="' + p.id + '" value="' + esc(p.name) + '"></td><td class="num">' + n + '</td></tr>';
    }).join('') + '</table></div>';
  }
  function refParam() {
    var lv = Object.keys(C.SPEC_LEVELS);
    return '<h2>' + esc(t('menu.ref_param')) + '</h2><p class="muted">' + esc(t('spek.pengantar_admin')) + '</p><div class="tbl"><table><tr><th>' + esc(t('spek.kol_param')) + '</th><th>' + esc(t('lembar.kol_kategori')) + '</th><th>' + esc(t('lembar.satuan')) + '</th><th>' + esc(t('spek.kol_sumber')) + '</th><th>CVI (%)</th><th>CVG (%)</th><th>' + esc(t('spek.kol_sumber_vb')) + '</th><th>' + esc(t('spek.kol_tingkat')) + '</th><th>' + esc(t('spek.kol_tea_manual')) + '</th><th>' + esc(t('spek.kol_nama_sumber')) + '</th><th class="num">' + esc(t('spek.kol_tea_berlaku')) + '</th><th>' + esc(t('spek.kol_verifikasi')) + '</th></tr>' + C.S.params.map(function (p) {
      var bv = p.specSrc === 'bv', sp = C.teaSpec(p);
      var inp = function (k, on) { return '<input class="cell" style="min-width:70px" data-par="' + p.id + '" data-k="' + k + '" value="' + esc(p[k] == null ? '' : p[k]) + '"' + (on ? '' : ' disabled') + '>'; };
      return '<tr><td>' + inp('name', true) + '</td><td>' + esc(p.cat) + '</td><td>' + inp('unit', true) + '</td><td><select class="inp" data-par="' + p.id + '" data-k="specSrc">' + opt('bv', t('spek.vb'), bv) + opt('lain', t('spek.lain'), !bv) + '</select></td><td>' + inp('cvi', bv) + '</td><td>' + inp('cvg', bv) + '</td><td>' + inp('bvSrc', bv) + '</td><td><select class="inp" data-par="' + p.id + '" data-k="level"' + (bv ? '' : ' disabled') + '>' + lv.map(function (l) { return opt(l, l, (p.level || 'Desirable') === l); }).join('') + '</select></td><td>' + inp('tea', !bv) + '</td><td>' + inp('teaSrc', !bv) + '</td><td class="calc num">' + fmt(sp.tea) + (bv && C.isNum(sp.tea) ? '<div class="small muted">CVa ' + fmt(sp.cva) + ' · Bias ' + fmt(sp.bias) + '</div>' : '') + '</td><td><input type="checkbox" data-act="teaOK" data-id="' + p.id + '" ' + (p.teaOK ? 'checked' : '') + '></td></tr>';
    }).join('') + '</table></div><div class="card" style="margin-top:12px"><div class="form"><label class="f">' + esc(t('spek.param_baru')) + '<input class="inp" id="npN"></label><label class="f">' + esc(t('lembar.kol_kategori')) + '<select class="inp" id="npC">' + opt('Kimia', 'Kimia') + opt('Hematologi', 'Hematologi') + '</select></label><label class="f">' + esc(t('lembar.satuan')) + '<input class="inp" id="npU"></label><div><button class="btn" data-act="paramAdd">' + esc(t('umum.tambah')) + '</button></div></div></div>';
  }
  function refAlat() {
    return '<h2>' + esc(t('menu.ref_alat')) + '</h2><p class="muted">' + esc(t('admin.alat_ket')) + '</p>' + alatTable(C.S.alats, false, true);
  }
  function aturan() {
    var r = C.settings().rules || {};
    return '<h2>' + esc(t('menu.aturan')) + '</h2><p class="muted">' + esc(t('admin.aturan_ket')) + '</p><div class="card">' + Object.keys(E.RULES).map(function (k) { return '<label class="row" style="margin:4px 0"><input type="checkbox" data-act="rule" data-k="' + k + '" ' + (r[k] ? 'checked' : '') + '> ' + E.RULES[k].label + '</label>'; }).join('') +
      '<label class="f" style="max-width:200px;margin-top:10px">' + esc(t('admin.k_te')) + '<input class="inp" id="kTE" value="' + C.settings().k + '"></label><button class="btn s" style="margin-top:8px" data-act="saveK">' + esc(t('umum.simpan')) + '</button></div>';
  }
  function tampilanPage() {
    var s = tampil();
    return '<h2>' + esc(t('menu.tampilan')) + '</h2><div class="card"><div class="form"><label class="f">' + esc(t('admin.nama_aplikasi')) + '<input class="inp" id="tA" value="' + esc(s.appName) + '"></label><label class="f">' + esc(t('admin.kepanjangan')) + '<input class="inp" id="tL" value="' + esc(s.longName) + '"></label><label class="f">' + esc(t('admin.slogan')) + '<input class="inp" id="tT" value="' + esc(s.tagline) + '"></label><label class="f">' + esc(t('admin.warna')) + '<input type="color" class="inp" id="tC" value="' + esc(s.color) + '"></label></div><div class="row" style="margin-top:8px"><button class="btn" data-act="saveTampilan">' + esc(t('umum.simpan')) + '</button></div></div>';
  }
  function operasional() {
    return '<h2>' + esc(t('menu.operasional')) + '</h2><div class="card"><h3>' + esc(t('admin.versi')) + '</h3><p>' + esc(t('admin.versi_isi', { v: (window.SIPANDU || {}).version })) + '</p></div><div class="card"><h3>' + esc(t('admin.cadangan')) + '</h3><p class="muted">' + esc(t('admin.cadangan_isi')) + '</p></div>';
  }

  /* ---------- modal kata sandi ---------- */
  function showPasswordResult(r) {
    modal('<h3>' + esc(t('admin.sandi_sementara')) + '</h3><p>' + esc(t('admin.sandi_sementara_ket')) + '</p><div class="tbl"><table><tr><th>' + esc(t('admin.username')) + '</th><th>' + esc(t('admin.sandi')) + '</th></tr><tr><td><code>' + esc(r.username) + '</code></td><td><code>' + esc(r.password) + '</code></td></tr></table></div><div class="row" style="margin-top:12px"><span class="sp"></span><button class="btn" data-close>' + esc(t('umum.tutup')) + '</button></div>');
  }

  /* ---------- aksi ---------- */
  function sheetIdFromRoute() { return route().slice(7); }
  var A = {
    nav: function (el) { go(el.dataset.to); },
    openNotif: function (el) { var n = C.byId(C.S.notifs, el.dataset.id); C.api('notif_read', { id: n.id }).then(function () { go(n.link || 'notif'); render(); }); },
    readAll: function () { call('notif_read_all'); },
    pwModal: function () {
      var m = modal('<h3>' + esc(t('umum.ganti_sandi')) + '</h3><label class="f">' + esc(t('masuk.sandi_lama')) + '<input class="inp" type="password" id="pwO" autocomplete="current-password"></label><label class="f">' + esc(t('masuk.sandi_baru')) + '<input class="inp" type="password" id="pwN" autocomplete="new-password"></label><p class="small muted">' + esc(t('masuk.syarat_sandi')) + '</p><div class="row" style="margin-top:10px"><span class="sp"></span><button class="btn o" data-close>' + esc(t('umum.batal')) + '</button><button class="btn" id="pwOK">' + esc(t('umum.simpan')) + '</button></div>');
      $('#pwOK', m).onclick = function () { C.api('password_change', { old: $('#pwO', m).value, new: $('#pwN', m).value }).then(function () { m.remove(); toast(t('umum.sandi_diganti')); }, function (e) { toast(e.message, true); }); };
    },
    newSheet: function () { call('sheet_create', { paramId: +$('#nsParam').value, alatId: +$('#nsAlat').value, lv: $('#nsLv').value }).then(function (r) { go('lembar-' + r.id); }); },
    lvActive: function (el) { var id = sheetIdFromRoute(); keepScroll(); C.invalidate(id); call('level_active', { sheetId: +id, i: +el.dataset.i, v: el.checked }); },
    addRun: function (el) {
      var sh = C.cachedSheet(el.dataset.id), vals = [];
      sh.levels.forEach(function (l, i) { var inp = $('#runV' + i); vals.push(inp ? inp.value : null); });
      C.invalidate(sh.id);
      call('run_add', { sheetId: sh.id, date: $('#runDate').value, values: vals, note: $('#runNote').value }).then(function (r) { toast(t('lembar.run_tersimpan', { status: t('status.' + r.status.replace('/', '_').replace(/ /g, '_')) })); });
    },
    excl: function (el) { var id = sheetIdFromRoute(); keepScroll(); C.invalidate(id); call('run_excl', { sheetId: +id, runId: +el.dataset.run, v: el.checked }, el.checked ? t('lembar.tulis_alasan') : ''); },
    reagenLot: function (el) {
      var id = el.dataset.id;
      var sh = C.cachedSheet(id);
      var rs = (C.S.bhp || []).filter(function (b) { return b.jenis === 'reagen' && b.cat === sh.cat && String(b.pkmId) === String(sh.pkmId) && b.status === 'tersedia'; });
      var m = modal('<h3>' + esc(t('lembar.tombol_lot_reagen')) + '</h3><p class="small muted">' + esc(t('lembar.ket_lot_reagen')) + '</p><div class="form"><label class="f">' + esc(t('lembar.tanggal')) + '<input class="inp" type="date" id="mrD" max="' + C.today() + '" value="' + C.today() + '"></label><label class="f">' + esc(t('lembar.lot_reagen_baru')) + '<select class="inp" id="mrL">' + opt('', t('lembar.pilih')) + rs.map(function (b) { return opt(b.id, b.name + ' · LOT ' + b.lot + (b.ed ? ' · ED ' + b.ed : '')); }).join('') + '</select></label></div>' + (rs.length ? '' : '<p class="small"><a href="#sd-bhp" data-close>' + esc(t('lembar.isi_di_sd')) + '</a></p>') + '<div class="row" style="margin-top:12px"><span class="sp"></span><button class="btn o" data-close>' + esc(t('umum.batal')) + '</button><button class="btn" id="mrOK">' + esc(t('umum.simpan')) + '</button></div>');
      $('#mrOK', m).onclick = function () { C.invalidate(id); call('reagen_lot', { sheetId: +id, date: $('#mrD', m).value, bhpId: +$('#mrL', m).value }, t('lembar.lot_reagen_dicatat')).then(function () { m.remove(); }); };
    },
    newLot: function (el) {
      var id = el.dataset.id;
      var m = modal('<h3>' + esc(t('lembar.tombol_lot_kontrol')) + '</h3><p>' + esc(t('lembar.ket_lot_kontrol')) + '</p><div class="row"><span class="sp"></span><button class="btn o" data-close>' + esc(t('umum.batal')) + '</button><button class="btn" id="mlOK">' + esc(t('lembar.tombol_tutup_buka')) + '</button></div>');
      $('#mlOK', m).onclick = function () { C.invalidate(id); call('new_lot', { sheetId: +id }, t('lembar.lembar_baru_dibuka')).then(function (r) { m.remove(); go('lembar-' + r.id); }); };
    },
    sheetCmt: function (el) { var x = $('#cmt').value.trim(); if (!x) return; C.invalidate(el.dataset.id); call('sheet_comment', { sheetId: +el.dataset.id, text: x }, t('umum.terkirim')); },
    caseFilter: function (el) { ui.filter.kasus = el.dataset.v; render(); },
    caseMode: function (el) { call('case_mode', { id: +el.dataset.id, v: el.dataset.v }); },
    closeWarn: function (el) { call('case_close_warn', { id: +el.dataset.id }); },
    saveFind: function (el) { var keys = []; document.querySelectorAll('[data-find]').forEach(function (cb) { if (cb.checked) keys.push(cb.dataset.find); }); call('case_findings', { id: +el.dataset.id, keys: keys, other: $('#findOther').value }); },
    saveCorr: function (el) {
      var items = []; document.querySelectorAll('[data-corr]').forEach(function (cb) { items[+cb.dataset.corr] = { done: cb.checked, note: $('[data-corrnote="' + cb.dataset.corr + '"]').value }; });
      call('case_corrections', { id: +el.dataset.id, items: items, add: $('#corrNew').value, finish: el.dataset.finish === '1' });
    },
    closeCase: function (el) { call('case_close', { id: +el.dataset.id, runId: +$('#verRun').value }); },
    caseCmt: function (el) { var x = $('#caseCmt').value.trim(); if (!x) return; ui.caseComments = null; call('case_comment', { id: +el.dataset.id, text: x }, t('umum.terkirim')); },
    riskAdd: function () { var x = $('#riskNew').value.trim(); if (!x) return; ui.riskDraft.items.push({ text: x, L: 1, S: 1, control: '', plan: '' }); keepScroll(); render(); },
    riskSave: function () { var d = ui.riskDraft; call('risk_save', { aspect: d.aspect, alatId: d.alatId ? +d.alatId : null, items: d.items }, t('risiko.tersimpan')).then(function () { ui.riskDraft = null; }); },
    userAdd: function () { call('user_add', { role: $('#nuRole').value, name: $('#nuName').value, username: $('#nuUser').value, pkmId: +$('#nuPkm').value, nip: $('#nuNip').value, jabatan: $('#nuJab').value }).then(showPasswordResult); },
    userActive: function (el) { call('user_active', { id: +el.dataset.id, v: el.checked }); },
    resetPw: function (el) { var m = modal('<h3>' + esc(t('admin.reset_sandi')) + '</h3><p>' + esc(t('admin.reset_konfirmasi')) + '</p><div class="row"><span class="sp"></span><button class="btn o" data-close>' + esc(t('umum.batal')) + '</button><button class="btn" id="rpOK">' + esc(t('admin.reset_sandi')) + '</button></div>'); $('#rpOK', m).onclick = function () { m.remove(); call('user_reset_pw', { id: +el.dataset.id }).then(showPasswordResult); }; },
    pkmAdd: function () { call('pkm_add', { name: $('#npkm').value }, t('umum.ditambahkan')); },
    paramAdd: function () { call('param_add', { name: $('#npN').value, cat: $('#npC').value, unit: $('#npU').value }, t('umum.ditambahkan')); },
    teaOK: function (el) { call('param_teaok', { id: +el.dataset.id, v: el.checked }); },
    alatAdd: function () { call('alat_add', { name: $('#naN').value, merk: $('#naM').value, tipe: $('#naT').value, cat: $('#naC').value }, t('umum.ditambahkan')); },
    alatActive: function (el) { keepScroll(); call('alat_active', { id: +el.dataset.id, v: el.checked }); },
    sdmAdd: function () { call('sdm_add', { name: $('#nsdN').value, jabatan: $('#nsdJ').value }, t('umum.ditambahkan')).then(function (r) { go('sd-sdm-' + r.id); }); },
    sdmActive: function (el) { keepScroll(); call('sdm_active', { id: +el.dataset.id, v: el.checked }); },
    trainAdd: function (el) { call('sdm_train_add', { id: +el.dataset.id, name: $('#trN').value, by: $('#trB').value, tahun: $('#trT').value }, t('umum.ditambahkan')); },
    trainDel: function (el) { keepScroll(); call('sdm_train_del', { id: +el.dataset.id, i: +el.dataset.i }); },
    bhpAdd: function () { call('bhp_add', { jenis: $('#nbJ').value, name: $('#nbN').value, produsen: $('#nbP').value, cat: $('#nbC').value, level: $('#nbL').value, kemasan: $('#nbK').value, lot: $('#nbLot').value, ed: $('#nbE').value, suhu: $('#nbS').value }, t('umum.ditambahkan')); },
    bhpHabis: function (el) { ui.showHabis = el.checked; keepScroll(); render(); },
    bhpNewLot: function (el) {
      var b = C.byId(C.S.bhp, el.dataset.id);
      var m = modal('<h3>' + esc(t('sd.lot_baru_judul', { nama: b.name })) + '</h3><p class="small muted">' + esc(t('sd.lot_baru_ket', { lot: b.lot })) + '</p><div class="form"><label class="f">' + esc(t('sd.lot')) + '<input class="inp" id="mbL"></label><label class="f">' + esc(t('sd.ed')) + '<input class="inp" type="date" id="mbE"></label></div><div class="row" style="margin-top:12px"><span class="sp"></span><button class="btn o" data-close>' + esc(t('umum.batal')) + '</button><button class="btn" id="mbOK">' + esc(t('umum.simpan')) + '</button></div>');
      $('#mbOK', m).onclick = function () { keepScroll(); call('bhp_newlot', { id: b.id, lot: $('#mbL', m).value, ed: $('#mbE', m).value }, t('umum.ditambahkan')).then(function () { m.remove(); }); };
    },
    bhpDel: function (el) {
      var b = C.byId(C.S.bhp, el.dataset.id);
      var m = modal('<h3>' + esc(t('sd.hapus')) + '</h3><p>' + esc(t('sd.hapus_konfirmasi', { nama: b.name, lot: b.lot })) + '</p><div class="row"><span class="sp"></span><button class="btn o" data-close>' + esc(t('umum.batal')) + '</button><button class="btn" id="mdOK">' + esc(t('sd.hapus')) + '</button></div>');
      $('#mdOK', m).onclick = function () { m.remove(); keepScroll(); call('bhp_del', { id: b.id }, t('sd.terhapus')); };
    },
    sdOpen: function (el) { ui.sdOwner = el.dataset.pkm; go('sd-sdm'); },
    rule: function (el) { call('rule_toggle', { k: el.dataset.k, v: el.checked }); },
    saveK: function () { call('settings_k', { v: $('#kTE').value }, t('umum.tersimpan')); },
    saveTampilan: function () { call('settings_tampilan', { appName: $('#tA').value, longName: $('#tL').value, tagline: $('#tT').value, color: $('#tC').value }, t('umum.tersimpan')); }
  };

  function onChange(e) {
    var el = e.target, d = el.dataset, r = route();
    if (d.act === 'month') { ui.month[d.id] = el.value; keepScroll(); render(); return; }
    if (d.act && el.type === 'checkbox') return;
    if (r.indexOf('lembar-') === 0) {
      var id = sheetIdFromRoute(); keepScroll();
      if (d.lv != null) { C.invalidate(id); return call('sheet_level', { sheetId: +id, i: +d.lv, k: d.k, v: el.value }); }
      if (d.lvbhp != null) { if (!el.value) return; C.invalidate(id); return call('level_bhp', { sheetId: +id, i: +d.lvbhp, bhpId: +el.value }); }
      if (d.reagenpick) { if (!el.value) return; C.invalidate(id); return call('reagen_pick', { sheetId: +id, bhpId: +el.value }); }
      if (d.meta) return call('sheet_meta', { sheetId: +id, k: d.meta, v: el.value });
      if (d.run) { C.invalidate(id); return call('run_value', { sheetId: +id, runId: +d.run, i: +d.i, v: el.value }, t('lembar.run_diperbarui')); }
      if (d.reason) { C.invalidate(id); return call('run_reason', { sheetId: +id, runId: +d.reason, v: el.value }); }
    }
    if (d.risk != null) { var it = ui.riskDraft.items[+d.risk]; it[d.k] = (d.k === 'L' || d.k === 'S') ? +el.value : el.value; if (d.k === 'L' || d.k === 'S') { keepScroll(); render(); } return; }
    if (d.user) { keepScroll(); return call('user_update', { id: +d.user, k: d.k, v: el.value }, t('umum.tersimpan')); }
    if (d.pkm) { keepScroll(); return call('pkm_update', { id: +d.pkm, name: el.value }, t('umum.tersimpan')); }
    if (d.par) { keepScroll(); return call('param_update', { id: +d.par, k: d.k, v: el.value }, ['specSrc', 'level', 'cvi', 'cvg', 'bvSrc', 'tea', 'teaSrc'].indexOf(d.k) >= 0 ? t('spek.diperbarui') : t('umum.tersimpan')); }
    if (d.alat) { keepScroll(); return call('alat_update', { id: +d.alat, k: d.k, v: el.value }, t('umum.tersimpan')); }
    if (d.sdm) { keepScroll(); return call('sdm_update', { id: +d.sdm, k: d.k, v: el.value }, t('umum.tersimpan')); }
    if (d.bhp) { keepScroll(); return call('bhp_update', { id: +d.bhp, k: d.k, v: el.value }, t('umum.tersimpan')); }
    if (d.sdowner != null) { ui.sdOwner = el.value; keepScroll(); render(); return; }
    if (el.id === 'sdmFoto' && el.files && el.files[0]) {
      var ff = new FormData(); ff.append('file', el.files[0]); ff.append('id', d.id);
      return call('sdm_foto', null, t('sd.foto_tersimpan'), { form: ff });
    }
    if (el.id === 'kopFile' && el.files && el.files[0]) {
      var fd = new FormData(); fd.append('file', el.files[0]);
      return call('kop_upload', null, t('kop.tersimpan'), { form: fd });
    }
  }

  function init(el) {
    root = el;
    root.addEventListener('click', function (e) {
      var x = e.target.closest('[data-act]'); if (!x || !root.contains(x)) return;
      if (x.tagName === 'SELECT') return;
      var f = A[x.dataset.act]; if (f) { if (x.tagName === 'A') e.preventDefault(); f(x, e); }
    });
    root.addEventListener('change', onChange);
    window.addEventListener('hashchange', render);
    render();
  }
  return { init: init, render: render };
})();
App.init(document.getElementById('app'));
