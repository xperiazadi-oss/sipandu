/* Subdomain pembelajaran – kerangka aplikasi (menu, tata letak, modal, ganti sandi). Halaman diisi oleh belajar.js. Semua teks dari app/teks.php. */
var App = (function () {
  'use strict';
  var C = Core, esc = C.esc, t = C.t;
  var root, ui = {}, EXT = {}, EXT_CHANGE = [];

  function $(s, el) { return (el || document).querySelector(s); }
  function toast(msg, bad) { var d = document.createElement('div'); d.className = 'toast' + (bad ? ' bad' : ''); d.textContent = msg; document.body.appendChild(d); setTimeout(function () { d.remove(); }, bad ? 4500 : 2600); }
  function go(r) { if (location.hash.slice(1) === r) render(); else location.hash = r; }
  function route() { return decodeURIComponent(location.hash.slice(1)) || 'bl'; }
  function chip(x, c) { return '<span class="chip c-' + c + '">' + esc(x) + '</span>'; }
  function opt(v, x, sel) { return '<option value="' + esc(v) + '"' + (sel ? ' selected' : '') + '>' + esc(x) + '</option>'; }
  function keepScroll() { ui.keepScroll = window.scrollY; }
  function modal(html) { var bg = document.createElement('div'); bg.className = 'modal-bg'; bg.innerHTML = '<div class="modal">' + html + '</div>'; bg.addEventListener('click', function (e) { if (e.target === bg || e.target.dataset.close != null) bg.remove(); }); document.body.appendChild(bg); return bg; }
  function denied() { return '<div class="note bad">' + esc(t('galat.halaman_tidak_tersedia')) + '</div>'; }
  function loadingCard() { return '<div class="card"><p class="muted">' + esc(t('umum.memuat')) + '</p></div>'; }
  function pwField(id, ac) { var l = esc(t('masuk.tahan_lihat')); return '<span class="pw"><input class="inp" type="password" id="' + id + '" autocomplete="' + ac + '"><button type="button" class="pw-eye" title="' + l + '" aria-label="' + l + '"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" fill="currentColor"/></svg></button></span>'; }
  function tampil() { return Object.assign({ color: '#0d5c63' }, C.settings().tampilan || {}); }

  /* menu per peran: [rute, kunci teks] */
  var MENU = {
    admin: [['bl', 'blapp.menu_kegiatan'], ['bl-pengguna', 'blapp.menu_pengguna']],
    pembina: [['bl', 'blapp.menu_kegiatan'], ['bl-profil', 'menu.bl_profil']],
    fasil: [['bl', 'blapp.menu_kegiatan'], ['bl-profil', 'menu.bl_profil'], ['bl-laporan', 'blapp.menu_laporan']],
    pengawas: [['bl', 'blapp.menu_kegiatan']],
    peserta: [['bl', 'blapp.menu_kegiatan'], ['bl-riwayat', 'blapp.menu_riwayat']]
  };
  function layout(body, cur) {
    var u = C.me();
    var sub = ['bl-pengguna', 'bl-profil', 'bl-riwayat', 'bl-laporan'];
    var inSub = function (r) { return sub.some(function (x) { return r === x || r.indexOf(x + '-') === 0; }); };
    var nav = (MENU[u.role] || []).map(function (m) {
      var on = m[0] === 'bl' ? (cur === 'bl' || (cur.indexOf('bl-') === 0 && !inSub(cur))) : (cur === m[0] || cur.indexOf(m[0] + '-') === 0);
      var badge = m[0] === 'bl' && u.role === 'admin' ? (C.S.bl || []).reduce(function (a, k) { return a + (k.nWaiting || 0); }, 0) : 0;
      return '<a href="#' + m[0] + '" class="' + (on ? 'on' : '') + '">' + esc(t(m[1])) + (badge ? ' <i class="nbadge">' + badge + '</i>' : '') + '</a>';
    }).join('');
    return '<div class="app"><aside class="side"><div class="brand"><b>' + esc(t('app.nama')) + '</b><small>' + esc(t('blapp.subjudul')) + '</small></div><nav class="nav">' + nav + '</nav></aside>' +
      '<div class="main"><div class="top"><div><b>' + esc(t('blapp.judul')) + '</b></div><div class="who"><b>' + esc(u.name) + '</b>' + esc(t('peran.' + u.role)) + '</div>' +
      '<button class="btn o s" data-act="pwModal">' + esc(t('umum.ganti_sandi')) + '</button><a class="btn o s" href="logout.php?t=' + encodeURIComponent(C.csrf()) + '">' + esc(t('umum.keluar')) + '</a></div><div class="content">' + body + '</div></div></div>';
  }

  function render() {
    var r = route(), u = C.me();
    document.documentElement.style.setProperty('--pri', tampil().color);
    var p = r.split('-'), head = p[0], arg = p.slice(1).join('-'), body;
    try { body = EXT[head] ? EXT[head](arg, u) : denied(); } catch (e) { body = '<div class="note bad">' + esc(t('galat.tampilan')) + ': ' + esc(e.message) + '</div>'; if (window.console) console.error(e); }
    root.innerHTML = layout(body, r);
    window.scrollTo(0, ui.keepScroll || 0); ui.keepScroll = 0;
  }

  var A = {
    nav: function (el) { go(el.dataset.to); },
    pwModal: function () {
      var m = modal('<h3>' + esc(t('umum.ganti_sandi')) + '</h3><label class="f">' + esc(t('masuk.sandi_lama')) + pwField('pwO', 'current-password') + '</label><label class="f">' + esc(t('masuk.sandi_baru')) + pwField('pwN', 'new-password') + '</label><p class="small muted">' + esc(t('masuk.syarat_sandi')) + '</p><div class="row" style="margin-top:10px"><span class="sp"></span><button class="btn o" data-close>' + esc(t('umum.batal')) + '</button><button class="btn" id="pwOK">' + esc(t('umum.simpan')) + '</button></div>');
      $('#pwOK', m).onclick = function () { C.api('password_change', { old: $('#pwO', m).value, new: $('#pwN', m).value }).then(function () { m.remove(); toast(t('umum.sandi_diganti')); }, function (e) { toast(e.message, true); }); };
    }
  };
  function onChange(e) {
    var el = e.target, d = el.dataset, r = route();
    for (var i = 0; i < EXT_CHANGE.length; i++) if (EXT_CHANGE[i](el, d, r) === true) return;
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
  return {
    init: init, render: render,
    addView: function (head, fn) { EXT[head] = fn; },
    addActions: function (o) { Object.keys(o).forEach(function (k) { A[k] = o[k]; }); },
    addChange: function (fn) { EXT_CHANGE.push(fn); },
    h: { $: $, toast: toast, go: go, route: route, chip: chip, opt: opt, modal: modal, keepScroll: keepScroll, ui: ui, denied: denied, loadingCard: loadingCard }
  };
})();
// aplikasi dijalankan setelah semua skrip siap (belajar.js mendaftarkan halamannya lebih dulu)
document.addEventListener('DOMContentLoaded', function () { App.init(document.getElementById('app')); });
