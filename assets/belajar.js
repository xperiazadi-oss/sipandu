/* Subdomain pembelajaran (Si PANDU – Peningkatan Kapasitas Kompetensi SDM). Semua teks tampilan dari app/teks.php (kunci bl.* dan blapp.*). */
(function () {
  'use strict';
  var C = Core, H = App.h, esc = C.esc, t = C.t, $ = H.$, ui = H.ui;
  var cache = {}, loading = {}, poll = null;
  ui.blTab = ui.blTab || {};

  /* ---------- util ---------- */
  function me() { return C.me(); }
  function isStaff() { return ['admin', 'pembina', 'fasil'].indexOf(me().role) >= 0; }
  function kegId() { var a = H.route().split('-'); return a[0] === 'bl' && /^\d+$/.test(a[1] || '') ? a[1] : null; }
  function load(id) {
    loading[id] = 1;
    return C.api('bl_get', { id: +id }).then(function (d) { cache[id] = d; loading[id] = 0; return d; }, function (e) { loading[id] = 0; throw e; });
  }
  function act(action, data, msg, opts) {
    var id = kegId();
    return C.api(action, data, opts).then(function (r) {
      return (id ? load(id) : Promise.resolve()).then(function () { if (msg) H.toast(msg); App.render(); return r; });
    }, function (e) { H.toast(e.message, true); App.render(); return new Promise(function () { }); });
  }
  function chipStatus(kind, v) {
    var c = { keg: { draf: 'muted', terbit: 'ok', arsip: 'info' }, seg: { belum: 'muted', buka: 'ok', tutup: 'info' }, pes: { menunggu: 'warn', diterima: 'ok', lengkapi: 'warn', ditolak: 'bad' } }[kind][v] || 'muted';
    return H.chip(t('bl.st_' + kind + '.' + v), c);
  }
  function dt(s) { return s ? esc(s) : '–'; }
  function sched(o) { var x = [o.date, (o.from || o.to) ? (o.from || '') + '–' + (o.to || '') : ''].filter(Boolean).join(' · '); return x || t('bl.jadwal_belum'); }
  function segName(s) { return s.title || t('bl.seg_judul.' + s.jenis, { n: '' }); }
  function num(v) { return v == null ? '–' : (Math.round(v * 10) / 10).toString().replace('.', ','); }
  function rank(r, n) { return r == null ? '–' : t('bl.peringkat_dari', { r: r, n: n }); }
  function userName(d, id) { var x = (d.staffUsers || []).filter(function (u) { return String(u.id) === String(id); })[0]; return x ? x.name : '?'; }
  function fotoNarsum(p, w) { return p.foto ? '<img src="berkas.php?j=profil&id=' + p.id + '&v=' + p.v + '" alt="" style="width:' + w + 'px;height:' + Math.round(w * 4 / 3) + 'px;object-fit:contain;border:1px solid var(--line);background:#fff">' : '<div style="width:' + w + 'px;height:' + Math.round(w * 4 / 3) + 'px;border:1px dashed var(--line)"></div>'; }
  function narsumCard(p) {
    return '<div class="row" style="align-items:flex-start;gap:12px">' + fotoNarsum(p, 60) + '<div class="sp"><b>' + esc(p.name) + '</b>' + (p.jabatan || p.instansi ? '<div class="small">' + esc([p.jabatan, p.instansi].filter(Boolean).join(' · ')) + '</div>' : '') + (p.ringkasan ? '<div class="small muted" style="white-space:pre-line">' + esc(p.ringkasan) + '</div>' : '') + '</div></div>';
  }
  function kb(n) { return n >= 1048576 ? (Math.round(n / 104857.6) / 10).toString().replace('.', ',') + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }

  /* ---------- halaman ---------- */
  App.addView('bl', function (arg) {
    if (poll) { clearInterval(poll); poll = null; }
    if (!arg) return listPage();
    if (arg === 'profil') return profilPage();
    if (arg === 'pengguna') return penggunaPage();
    if (arg === 'riwayat') return riwayatPage();
    var p = arg.split('-'), id = p[0];
    if (!/^\d+$/.test(id)) return H.denied();
    var d = cache[id];
    if (!d) { if (!loading[id]) load(id).then(App.render, function (e) { H.toast(e.message, true); H.go('bl'); }); return H.loadingCard(); }
    var r = me().role;
    if (r === 'peserta') {
      if (!d.me) return regPage(d);
      if (!(p[1] && p[1].charAt(0) === 's')) poll = setInterval(function () { if (H.route() === 'bl-' + id && !document.querySelector('.modal-bg')) load(id).then(function () { H.keepScroll(); App.render(); }); }, 30000);
      return p[1] && p[1].charAt(0) === 's' ? pesertaSeg(d, p[1].slice(1)) : pesertaHome(d);
    }
    if (r === 'pengawas') {
      poll = setInterval(function () { if (H.route() === 'bl-' + id && !document.querySelector('.modal-bg')) load(id).then(function () { H.keepScroll(); App.render(); }); }, 30000);
      return pengawasPage(d);
    }
    return staffPage(d);
  });

  function head(k) {
    return '<div class="small"><a href="#bl">← ' + esc(t('blapp.menu_kegiatan')) + '</a></div><div class="row"><h2 class="sp">' + esc(k.title) + '</h2>' + chipStatus('keg', k.status) + '</div>' +
      '<p class="muted">' + esc([k.org, k.place, [k.start, k.end].filter(Boolean).join(' s.d. ')].filter(Boolean).join(' · ')) + '</p>' + (k.desc ? '<p style="white-space:pre-line">' + esc(k.desc) + '</p>' : '');
  }

  /* daftar kegiatan */
  function listPage() {
    var r = me().role, list = C.S.bl || [];
    var h = '<h2>' + esc(t('blapp.menu_kegiatan')) + '</h2><p class="muted">' + esc(t('bl.pengantar')) + '</p>';
    if (r === 'admin') h += '<div class="card"><h3>' + esc(t('bl.keg_baru')) + '</h3><div class="form"><label class="f">' + esc(t('bl.judul')) + '<input class="inp" id="bkT"></label><label class="f">' + esc(t('bl.penyelenggara')) + '<input class="inp" id="bkO" placeholder="' + esc(t('bl.ph_penyelenggara')) + '"></label><label class="f">' + esc(t('bl.kuota')) + '<input class="inp" type="number" min="1" id="bkK"></label><div><button class="btn" data-act="blKegAdd">' + esc(t('bl.buat')) + '</button></div></div><p class="small muted">' + esc(t('bl.kuota_ket')) + '</p></div>';
    if (r === 'pembina' || r === 'fasil') h += '<p><a class="btn o s" href="#bl-profil">' + esc(t('menu.bl_profil')) + '</a></p>';
    if (!list.length) return h + '<p class="muted">' + esc(t(r === 'peserta' ? 'bl.belum_ada_peserta' : 'bl.belum_ada')) + '</p>';
    if (r === 'peserta' || r === 'pengawas') return h + '<div class="grid g2">' + list.map(function (k) {
      var st = '';
      if (r === 'peserta') st = k.myStatus ? t('bl.st_pes.' + k.myStatus) : formState(k);
      if (r === 'pengawas') st = t('bl.n_peserta_diterima', { n: k.nPeserta });
      return '<div class="card"><div class="row"><h3 class="sp">' + esc(k.title) + '</h3>' + (k.status === 'arsip' ? chipStatus('keg', 'arsip') : '') + '</div><p class="small muted">' + esc([k.org, [k.start, k.end].filter(Boolean).join(' s.d. ')].filter(Boolean).join(' · ')) + '</p>' + (st ? '<p class="small">' + esc(st) + '</p>' : '') + '<a class="btn s" href="#bl-' + k.id + '">' + esc(t('bl.buka')) + '</a></div>';
    }).join('') + '</div>';
    return h + '<div class="tbl"><table><tr><th>' + esc(t('bl.judul')) + '</th><th>' + esc(t('bl.penyelenggara')) + '</th><th>' + esc(t('bl.tanggal')) + '</th><th>' + esc(t('bl.status')) + '</th><th>' + esc(t('bl.pendaftaran')) + '</th><th class="num">' + esc(t('bl.kuota_terisi')) + '</th><th class="num">' + esc(t('bl.peserta')) + '</th></tr>' + list.map(function (k) {
      return '<tr><td><a href="#bl-' + k.id + '"><b>' + esc(k.title) + '</b></a>' + (k.nWaiting ? ' ' + H.chip(t('bl.n_menunggu', { n: k.nWaiting }), 'warn') : '') + '</td><td>' + esc(k.org) + '</td><td>' + esc([k.start, k.end].filter(Boolean).join(' s.d. ')) + '</td><td>' + chipStatus('keg', k.status) + '</td><td>' + esc(t(k.open ? 'bl.dibuka' : 'bl.ditutup')) + '</td><td class="num">' + k.terisi + ' / ' + k.kuota + '</td><td class="num">' + k.nPeserta + '</td></tr>';
    }).join('') + '</table></div>';
  }

  /* keadaan formulir pendaftaran untuk peserta */
  function formState(k) {
    if (k.formOpen) return t('bl.pendaftaran_dibuka_sisa', { n: k.kuota - k.terisi });
    if (k.status === 'terbit' && k.open && k.terisi >= k.kuota) return t('bl.kuota_penuh');
    return t('bl.pendaftaran_ditutup');
  }

  /* profil narasumber (Pembina/Fasilitator) */
  function profilPage() {
    var r = me().role; if (r !== 'pembina' && r !== 'fasil') return H.denied();
    var p = ui.blProfil;
    if (!p) { C.api('bl_get_profil', {}).then(function (x) { ui.blProfil = x; App.render(); }, function (e) { H.toast(e.message, true); }); return H.loadingCard(); }
    return '<div class="small"><a href="#bl">← ' + esc(t('blapp.menu_kegiatan')) + '</a></div><h2>' + esc(t('menu.bl_profil')) + '</h2><p class="muted">' + esc(t('bl.profil_ket')) + '</p><div class="grid g2" style="grid-template-columns:220px 1fr;align-items:start"><div class="card">' + fotoNarsum(p, 180) +
      '<label class="f" style="margin-top:8px">' + esc(t('bl.unggah_foto')) + '<input type="file" id="blFoto" accept="image/jpeg,image/png"></label><p class="small muted">' + esc(t('sd.foto_ket')) + '</p></div><div class="card"><div class="form"><label class="f">' + esc(t('bl.nama')) + '<input class="inp" disabled value="' + esc(p.name) + '"></label><label class="f">' + esc(t('bl.jabatan_narsum')) + '<input class="inp" id="bpJ" value="' + esc(p.jabatan) + '"></label><label class="f">' + esc(t('bl.instansi')) + '<input class="inp" id="bpI" value="' + esc(p.instansi) + '"></label><label class="f" style="grid-column:1/-1">' + esc(t('bl.ringkasan')) + '<textarea class="inp" id="bpR" rows="5">' + esc(p.ringkasan) + '</textarea></label><div><button class="btn" data-act="blProfilSave">' + esc(t('umum.simpan')) + '</button></div></div></div></div>';
  }

  /* ---------- staf: Admin, Pembina, Fasilitator ---------- */
  function staffPage(d) {
    var r = me().role, k = d.keg, id = k.id;
    var tabs = r === 'admin' ? ['atur', 'peserta', 'soal', 'pantau', 'rekap'] : ['segmen', 'soal', 'materi', 'nilai', 'pantau', 'rekap'];
    var tab = ui.blTab[id] && tabs.indexOf(ui.blTab[id]) >= 0 ? ui.blTab[id] : tabs[0];
    var waiting = (d.peserta || []).filter(function (p) { return p.status === 'menunggu'; }).length;
    var h = head(k) + '<div class="tabs">' + tabs.map(function (x) { return '<a data-act="blTab" data-v="' + x + '" class="' + (x === tab ? 'on' : '') + '">' + esc(t('bl.tab.' + x)) + (x === 'peserta' && waiting ? ' <i class="nbadge">' + waiting + '</i>' : '') + '</a>'; }).join('') + '</div>';
    if (tab === 'pantau') poll = setInterval(function () { if (H.route() === 'bl-' + id && (ui.blTab[id] || tabs[0]) === 'pantau' && !document.querySelector('.modal-bg')) load(id).then(function () { H.keepScroll(); App.render(); }); }, 20000);
    return h + { atur: tabAtur, peserta: tabPeserta, soal: tabSoal, materi: tabMateri, nilai: tabNilai, pantau: tabPantau, rekap: tabRekap, segmen: tabSegmen }[tab](d);
  }
  function segBtns(d, s) {
    if (!d.canOpen || d.keg.status !== 'terbit') return '';
    if (s.status === 'belum') return '<button class="btn s" data-act="blSegState" data-id="' + s.id + '" data-v="buka">' + esc(t('bl.mulai_segmen_staf')) + '</button>';
    if (s.status === 'buka') return '<button class="btn d s" data-act="blSegState" data-id="' + s.id + '" data-v="tutup">' + esc(t('bl.tutup_segmen')) + '</button>';
    return '';
  }
  function tabAtur(d) {
    var k = d.keg, ed = k.status !== 'arsip';
    var fld = function (key, label, type) { return '<label class="f">' + esc(t(label)) + '<input class="inp" ' + (type ? 'type="' + type + '" ' : '') + 'data-blk="' + key + '" value="' + esc(k[key]) + '"' + (ed ? '' : ' disabled') + '></label>'; };
    var h = '<div class="card"><h3>' + esc(t('bl.detail')) + '</h3><div class="form">' + fld('title', 'bl.judul') + fld('org', 'bl.penyelenggara') + fld('place', 'bl.lokasi') + fld('start', 'bl.tgl_mulai', 'date') + fld('end', 'bl.tgl_selesai', 'date') + fld('kuota', 'bl.kuota', 'number') +
      '<label class="f" style="grid-column:1/-1">' + esc(t('bl.deskripsi')) + '<textarea class="inp" rows="3" data-blk="desc"' + (ed ? '' : ' disabled') + '>' + esc(k.desc) + '</textarea></label></div><p class="small muted">' + esc(t('bl.kuota_info', { terisi: k.terisi, kuota: k.kuota })) + '</p></div>';
    h += '<div class="card"><h3>' + esc(t('bl.status')) + '</h3><div class="row">' + chipStatus('keg', k.status) + '<span class="sp"></span>' +
      (k.status === 'draf' ? '<button class="btn" data-act="blKegStatus" data-v="terbit">' + esc(t('bl.terbitkan')) + '</button>' : '') +
      (k.status === 'terbit' ? '<label class="small"><input type="checkbox" data-act="blDaftar" ' + (k.open ? 'checked' : '') + '> ' + esc(t('bl.pendaftaran_dibuka_cek')) + '</label>' + (!(d.peserta || []).length ? '<button class="btn o s" data-act="blKegStatus" data-v="draf">' + esc(t('bl.kembali_draf')) + '</button>' : '') + '<button class="btn o s" data-act="blArsip">' + esc(t('bl.arsipkan')) + '</button>' : '') +
      '</div><p class="small muted">' + esc(t('bl.status_ket')) + '</p></div>';
    var users = d.staffUsers || [];
    var checks = function (key, roles) {
      var cur = k[key];
      var us = users.filter(function (x) { return roles.indexOf(x.role) >= 0; });
      if (!us.length) return '<p class="small muted">' + esc(t({ fasil: 'bl.belum_ada_fasil', pembuka: 'bl.belum_ada_pembuka', pengawas: 'bl.belum_ada_pengawas' }[key])) + '</p>';
      return '<div class="row">' + us.map(function (x) { return '<label class="small" style="min-width:220px"><input type="checkbox" data-blpeople="' + key + '" value="' + x.id + '" ' + (cur.indexOf(x.id) >= 0 ? 'checked ' : '') + (ed ? '' : 'disabled') + '> ' + esc(x.name) + ' <span class="muted">(' + esc(t('peran.' + x.role)) + (x.active ? '' : ', ' + t('bl.nonaktif')) + ')</span></label>'; }).join('') + '</div>';
    };
    h += '<div class="grid g2"><div class="card"><h3>' + esc(t('bl.fasilitator')) + '</h3><p class="small muted">' + esc(t('bl.fasilitator_ket')) + '</p>' + checks('fasil', ['fasil']) + '</div><div class="card"><h3>' + esc(t('bl.pembuka')) + '</h3><p class="small muted">' + esc(t('bl.pembuka_ket')) + '</p>' + checks('pembuka', ['pembina', 'fasil']) + '</div></div>';
    h += '<div class="card"><h3>' + esc(t('bl.pengawas')) + '</h3><p class="small muted">' + esc(t('bl.pengawas_ket')) + '</p>' + checks('pengawas', ['pengawas']) + '</div>';
    var picOpts = function (s) { return H.opt('', t('bl.pic_lain')) + users.filter(function (x) { return x.role !== 'pengawas'; }).map(function (x) { return H.opt(x.id, x.name + ' (' + t('peran.' + x.role) + ')', String(x.id) === String(s.picId)); }).join(''); };
    h += '<div class="card"><h3>' + esc(t('bl.segmen')) + '</h3><p class="small muted">' + esc(t('bl.segmen_ket')) + '</p><div class="tbl"><table><tr><th>#</th><th>' + esc(t('bl.jenis')) + '</th><th>' + esc(t('bl.judul_segmen')) + '</th><th>' + esc(t('bl.pic')) + '</th><th>' + esc(t('bl.tanggal')) + '</th><th>' + esc(t('bl.jam_mulai')) + '</th><th>' + esc(t('bl.jam_selesai')) + '</th><th>' + esc(t('bl.status')) + '</th><th></th></tr>' + d.segs.map(function (s, i) {
      var mv = ['materi', 'praktik'].indexOf(s.jenis) >= 0 && ed;
      var inp = function (key, type, w) { return '<td class="in"><input class="cell" style="min-width:' + (w || 90) + 'px" ' + (type ? 'type="' + type + '" ' : '') + 'data-blseg="' + s.id + '" data-k="' + key + '" value="' + esc(s[key]) + '"' + (ed ? '' : ' disabled') + '></td>'; };
      return '<tr><td>' + (i + 1) + '</td><td>' + esc(t('bl.jenis_opsi.' + s.jenis)) + (s.jenis === 'praktik' || s.jenis === 'materi' ? '' : ' <span class="small muted">' + esc(t('bl.wajib')) + '</span>') + '</td>' + inp('title', '', 160) +
        '<td class="in"><select class="cell" style="min-width:190px" data-blseg="' + s.id + '" data-k="picId"' + (ed ? '' : ' disabled') + '>' + picOpts(s) + '</select>' + (s.picId ? '' : '<input class="cell" style="margin-top:3px;min-width:190px" placeholder="' + esc(t('bl.ph_pic')) + '" data-blseg="' + s.id + '" data-k="picText" value="' + esc(s.picText) + '"' + (ed ? '' : ' disabled') + '>') + '</td>' +
        inp('date', 'date', 130) + inp('from', 'time') + inp('to', 'time') + '<td>' + chipStatus('seg', s.status) + '</td><td style="white-space:nowrap">' +
        (s.jenis === 'materi' && ed ? '<button class="btn o s" data-act="blSesi" data-id="' + s.id + '">' + esc(t('bl.sesi_n', { n: (s.sesi || []).length })) + '</button> ' : '') +
        (mv ? '<button class="btn o s" data-act="blSegMove" data-id="' + s.id + '" data-v="-1" title="' + esc(t('bl.naik')) + '">↑</button> <button class="btn o s" data-act="blSegMove" data-id="' + s.id + '" data-v="1" title="' + esc(t('bl.turun')) + '">↓</button> ' : '') +
        (mv && s.status === 'belum' ? '<button class="btn o s" data-act="blSegDel" data-id="' + s.id + '">' + esc(t('sd.hapus')) + '</button> ' : '') + segBtns(d, s) + '</td></tr>';
    }).join('') + '</table></div>' + (ed ? '<div class="row" style="margin-top:10px"><button class="btn o s" data-act="blSegAdd" data-v="materi">+ ' + esc(t('bl.jenis_opsi.materi')) + '</button><button class="btn o s" data-act="blSegAdd" data-v="praktik">+ ' + esc(t('bl.jenis_opsi.praktik')) + '</button><span class="small muted">' + esc(t('bl.tambah_segmen_ket')) + '</span></div>' : '') + '</div>';
    return h;
  }
  function tabSegmen(d) {
    var h = d.canOpen ? '' : '<div class="note info">' + esc(t('bl.bukan_pembuka')) + '</div>';
    return h + '<div class="tbl"><table><tr><th>#</th><th>' + esc(t('bl.judul_segmen')) + '</th><th>' + esc(t('bl.pic')) + '</th><th>' + esc(t('bl.jadwal')) + '</th><th>' + esc(t('bl.status')) + '</th><th></th></tr>' + d.segs.map(function (s, i) {
      return '<tr><td>' + (i + 1) + '</td><td><b>' + esc(segName(s)) + '</b><div class="small muted">' + esc(t('bl.jenis_opsi.' + s.jenis)) + '</div>' + ((s.sesi || []).length ? '<ul class="small" style="margin:4px 0 0 16px;padding:0">' + s.sesi.map(function (x) { return '<li>' + esc(x.title) + ' – ' + esc(sched(x)) + (x.pic ? ' · ' + esc(x.pic) : '') + '</li>'; }).join('') + '</ul>' : '') + '</td><td>' + esc(s.picName || '–') + '</td><td>' + esc(sched(s)) + '</td><td>' + chipStatus('seg', s.status) + '</td><td>' + segBtns(d, s) + '</td></tr>';
    }).join('') + '</table></div>';
  }
  function tabPeserta(d) {
    var ps = d.peserta || [];
    if (!ps.length) return '<p class="muted">' + esc(t('bl.belum_ada_pendaftar')) + '</p>';
    var order = { menunggu: 0, lengkapi: 1, diterima: 2, ditolak: 3 };
    ps = ps.slice().sort(function (a, b) { return order[a.status] - order[b.status]; });
    return '<p class="small muted">' + esc(t('bl.verif_ket')) + '</p><p class="small"><b>' + esc(t('bl.kuota_info', { terisi: d.keg.terisi, kuota: d.keg.kuota })) + '</b></p><div class="tbl"><table><tr><th>' + esc(t('bl.instansi')) + '</th><th>' + esc(t('bl.nama_gelar')) + '</th><th>' + esc(t('bl.jabatan')) + '</th><th>' + esc(t('bl.nip')) + '</th><th>' + esc(t('bl.kontak')) + '</th><th>' + esc(t('bl.surat_tugas')) + '</th><th>' + esc(t('bl.dikirim')) + '</th><th>' + esc(t('bl.status')) + '</th><th></th></tr>' + ps.map(function (p) {
      return '<tr><td>' + esc(p.instansi) + '</td><td><b>' + esc(p.name) + '</b>' + (p.username ? '<div class="small"><code>' + esc(p.username) + '</code></div>' : '') + '</td><td>' + esc(p.jabatan) + '</td><td>' + esc(p.nip) + '</td><td class="small">' + esc(p.email) + '<br>' + esc(p.hp) + '</td><td>' + (p.hasSurat ? '<a href="berkas.php?j=surat&id=' + p.id + '" target="_blank" rel="noopener">' + esc(t('bl.lihat_pdf')) + '</a>' : '–') + '</td><td class="small">' + dt(p.sentAt) + '</td><td>' + chipStatus('pes', p.status) + (p.msg ? '<div class="small muted" style="white-space:pre-line">' + esc(p.msg) + '</div>' : '') + '</td><td style="white-space:nowrap">' +
        (p.status === 'menunggu' && d.keg.status !== 'arsip' ? '<button class="btn s" data-act="blVerif" data-id="' + p.id + '" data-v="diterima">' + esc(t('bl.terima')) + '</button> <button class="btn o s" data-act="blVerif" data-id="' + p.id + '" data-v="lengkapi">' + esc(t('bl.minta_lengkapi')) + '</button> <button class="btn d s" data-act="blVerif" data-id="' + p.id + '" data-v="ditolak">' + esc(t('bl.tolak')) + '</button>' : '') + '</td></tr>';
    }).join('') + '</table></div>';
  }
  function soalLocked(d) { return d.segs.some(function (s) { return (s.jenis === 'pretest' || s.jenis === 'posttest') && s.status !== 'belum'; }); }
  function tabSoal(d) {
    var ed = d.canContent && !soalLocked(d), soal = d.soal || [];
    var h = '<p class="muted">' + esc(t('bl.soal_ket', { n: soal.length })) + '</p>';
    if (soalLocked(d)) h += '<div class="note info">' + esc(t('bl.soal_terkunci')) + '</div>';
    if (ed && soal.length < 25) h += soalForm(null);
    h += soal.map(function (q, i) {
      if (ui.blEditSoal === q.id && ed) return soalForm(q, i);
      return '<div class="card"><div class="row" style="align-items:flex-start"><b>' + (i + 1) + '.</b><div class="sp" style="white-space:pre-line">' + esc(q.text) + '</div>' + (ed ? '<button class="btn o s" data-act="blSoalEdit" data-id="' + q.id + '">' + esc(t('bl.ubah')) + '</button><button class="btn o s" data-act="blSoalDel" data-id="' + q.id + '">' + esc(t('sd.hapus')) + '</button>' : '') + '</div><ol type="A" style="margin:6px 0 0 30px">' + ['a', 'b', 'c', 'd'].map(function (x) { return '<li' + (x === q.key ? ' style="font-weight:700;color:var(--ok)"' : '') + '>' + esc(q[x]) + (x === q.key ? ' ✓' : '') + '</li>'; }).join('') + '</ol></div>';
    }).join('');
    return h;
  }
  function soalForm(q, i) {
    q = q || { id: 0, text: '', a: '', b: '', c: '', d: '', key: '' };
    return '<div class="card"><h3>' + esc(q.id ? t('bl.ubah_soal', { n: i + 1 }) : t('bl.soal_baru')) + '</h3><div class="form"><label class="f" style="grid-column:1/-1">' + esc(t('bl.pertanyaan')) + '<textarea class="inp" rows="2" id="sqT">' + esc(q.text) + '</textarea></label>' +
      ['a', 'b', 'c', 'd'].map(function (x) { return '<label class="f">' + esc(t('bl.pilihan', { x: x.toUpperCase() })) + '<input class="inp" id="sq' + x + '" value="' + esc(q[x]) + '"></label>'; }).join('') +
      '<label class="f">' + esc(t('bl.kunci')) + '<select class="inp" id="sqK">' + H.opt('', t('lembar.pilih'), !q.key) + ['a', 'b', 'c', 'd'].map(function (x) { return H.opt(x, x.toUpperCase(), q.key === x); }).join('') + '</select></label><div class="row"><button class="btn" data-act="blSoalSave" data-id="' + q.id + '">' + esc(t('umum.simpan')) + '</button>' + (q.id ? '<button class="btn o" data-act="blSoalEdit" data-id="0">' + esc(t('umum.batal')) + '</button>' : '') + '</div></div></div>';
  }
  function tabMateri(d) {
    var segs = d.segs.filter(function (s) { return s.jenis === 'materi' || s.jenis === 'praktik'; });
    var h = '<p class="muted">' + esc(t('bl.materi_ket')) + '</p>';
    if (d.canContent) h += '<div class="card"><h3>' + esc(t('bl.unggah_materi')) + '</h3><div class="form"><label class="f">' + esc(t('bl.judul_materi')) + '<input class="inp" id="bmT"></label><label class="f">' + esc(t('bl.untuk_segmen')) + '<select class="inp" id="bmS">' + H.opt('', t('bl.semua_segmen')) + segs.map(function (s) { return H.opt(s.id, segName(s)); }).join('') + '</select></label><label class="f">' + esc(t('bl.berkas_pdf')) + '<input type="file" id="bmF" accept="application/pdf"></label><div><button class="btn" data-act="blMateriUp">' + esc(t('bl.unggah')) + '</button></div></div></div>';
    return h + materiList(d, d.canContent);
  }
  function materiList(d, canDel, segId) {
    var ms = (d.materi || []).filter(function (m) { return segId == null || !m.segId || String(m.segId) === String(segId); });
    if (!ms.length) return '<p class="muted">' + esc(t('bl.belum_ada_materi')) + '</p>';
    return '<div class="tbl"><table><tr><th>' + esc(t('bl.judul_materi')) + '</th><th>' + esc(t('bl.untuk_segmen')) + '</th><th>' + esc(t('bl.ukuran')) + '</th><th></th></tr>' + ms.map(function (m) {
      var s = d.segs.filter(function (x) { return String(x.id) === String(m.segId); })[0];
      return '<tr><td><b>' + esc(m.title) + '</b></td><td>' + esc(s ? segName(s) : t('bl.semua_segmen')) + '</td><td>' + kb(m.size) + '</td><td style="white-space:nowrap"><a class="btn o s" href="berkas.php?j=materi&id=' + m.id + '&dl=1">' + esc(t('bl.unduh')) + '</a>' + (canDel ? ' <button class="btn o s" data-act="blMateriDel" data-id="' + m.id + '">' + esc(t('sd.hapus')) + '</button>' : '') + '</td></tr>';
    }).join('') + '</table></div>';
  }
  function tabNilai(d) {
    var ed = d.canContent, asp = d.aspek || [], ps = (d.peserta || []).filter(function (p) { return p.status === 'diterima'; });
    var praktik = d.segs.filter(function (s) { return s.jenis === 'praktik'; });
    var h = '<p class="muted">' + esc(t('bl.nilai_ket')) + '</p><div class="card"><h3>' + esc(t('bl.aspek')) + '</h3>';
    var groups = [{ jenis: 'sikap', seg: null, label: t('bl.aspek_sikap') }].concat(praktik.map(function (s) { return { jenis: 'praktik', seg: s, label: t('bl.aspek_praktik', { seg: segName(s) }) }; }));
    h += groups.map(function (g) {
      var list = asp.filter(function (a) { return a.jenis === g.jenis && (g.seg ? String(a.segId) === String(g.seg.id) : true); });
      return '<h4 style="margin:10px 0 4px">' + esc(g.label) + '</h4>' + (list.length ? '<div class="tbl"><table><tr><th>' + esc(t('bl.nama_aspek')) + '</th><th>' + esc(t('bl.nilai_bawaan')) + '</th><th></th></tr>' + list.map(function (a) {
        return '<tr><td><input class="cell" data-blasp="' + a.id + '" data-k="name" value="' + esc(a.name) + '"' + (ed ? '' : ' disabled') + '></td><td><input class="cell" type="number" min="0" max="100" style="width:80px" data-blasp="' + a.id + '" data-k="def" value="' + a.def + '"' + (ed ? '' : ' disabled') + '></td><td>' + (ed ? '<button class="btn o s" data-act="blAspDel" data-id="' + a.id + '">' + esc(t('sd.hapus')) + '</button>' : '') + '</td></tr>';
      }).join('') + '</table></div>' : '<p class="small muted">' + esc(t('bl.belum_ada_aspek')) + '</p>') +
        (ed ? '<div class="row" style="margin-top:6px"><input class="inp" style="max-width:280px" placeholder="' + esc(t('bl.nama_aspek')) + '" id="ba-' + g.jenis + '-' + (g.seg ? g.seg.id : 0) + '"><input class="inp" type="number" min="0" max="100" style="width:110px" placeholder="' + esc(t('bl.nilai_bawaan')) + '" value="70" id="bd-' + g.jenis + '-' + (g.seg ? g.seg.id : 0) + '"><button class="btn o s" data-act="blAspAdd" data-j="' + g.jenis + '" data-seg="' + (g.seg ? g.seg.id : 0) + '">' + esc(t('sd.tambah')) + '</button></div>' : '');
    }).join('') + '</div>';
    if (!asp.length) return h;
    if (!ps.length) return h + '<p class="muted">' + esc(t('bl.belum_ada_peserta_diterima')) + '</p>';
    var nv = {}; (d.nilai || []).forEach(function (n) { nv[n.pesertaId + ':' + n.aspekId] = n.v; });
    h += '<div class="card"><h3>' + esc(t('bl.penilaian_peserta')) + '</h3><p class="small muted">' + esc(t('bl.penilaian_ket')) + '</p><div class="tbl"><table><tr><th>' + esc(t('bl.peserta')) + '</th>' + asp.map(function (a) { return '<th>' + esc(a.name) + '<div class="small muted">' + esc(a.jenis === 'sikap' ? t('bl.sikap') : t('bl.praktik')) + '</div></th>'; }).join('') + '</tr>' + ps.map(function (p) {
      return '<tr><td><b>' + esc(p.name) + '</b><div class="small muted">' + esc(p.instansi) + '</div></td>' + asp.map(function (a) { var v = nv[p.id + ':' + a.id]; return '<td class="in"><input class="cell" type="number" min="0" max="100" style="width:70px" placeholder="' + a.def + '" data-blnilai="' + p.id + '" data-asp="' + a.id + '" value="' + (v == null ? '' : v) + '"' + (ed ? '' : ' disabled') + '></td>'; }).join('') + '</tr>';
    }).join('') + '</table></div></div>';
    return h;
  }
  function tabPantau(d) {
    var sc = d.scores || { rows: [] }, segs = d.segs;
    if (!sc.rows.length) return '<p class="muted">' + esc(t('bl.belum_ada_peserta_diterima')) + '</p>';
    return '<div class="row"><p class="small muted sp">' + esc(t('bl.pantau_ket')) + '</p><button class="btn o s" data-act="blReload">' + esc(t('bl.muat_ulang')) + '</button></div><div class="tbl"><table><tr><th>' + esc(t('bl.peserta')) + '</th>' + segs.map(function (s) { return '<th>' + esc(segName(s)) + '<div class="small muted">' + esc(t('bl.st_seg.' + s.status)) + '</div></th>'; }).join('') + '<th class="num">' + esc(t('bl.penyelesaian')) + '</th></tr>' + sc.rows.map(function (r) {
      return '<tr><td><b>' + esc(r.name) + '</b><div class="small muted">' + esc(r.instansi) + '</div></td>' + segs.map(function (s) {
        var done = r.doneSegs.indexOf(s.id) >= 0, test = s.jenis === 'pretest' || s.jenis === 'posttest', n = (r.answered || {})[s.id] || 0;
        return '<td>' + (done ? H.chip(t('bl.selesai'), 'ok') : (test && n ? H.chip(t('bl.terjawab', { n: n, m: sc.nSoal }), 'warn') : '<span class="muted">–</span>')) + (done && test ? '<div class="small">' + num(s.jenis === 'pretest' ? r.pre : r.post) + '</div>' : '') + '</td>';
      }).join('') + '<td class="num">' + num(r.complete) + '%</td></tr>';
    }).join('') + '</table></div>';
  }
  function tabRekap(d) {
    var sc = d.scores || { rows: [] };
    if (!sc.rows.length) return '<p class="muted">' + esc(t('bl.belum_ada_peserta_diterima')) + '</p>';
    var n = sc.rows.length, b = sc.bobot || {};
    var h = '<p class="small muted">' + esc(t('bl.bobot_ket', { post: num(b.post), praktik: num(b.praktik), sikap: num(b.sikap), progres: num(b.progres) })) + '</p>';
    if (sc.final && sc.predikat) h += '<div class="grid g3">' + ['post', 'progres', 'terbaik'].map(function (key) {
      var names = sc.predikat[key].map(function (id) { var r = sc.rows.filter(function (x) { return x.pesertaId === id; })[0]; return r ? r.name + (r.instansi ? ' (' + r.instansi + ')' : '') : ''; });
      return '<div class="card"><div class="small muted">' + esc(t('bl.predikat.' + key)) + '</div><b>' + esc(names.join(', ') || '–') + '</b></div>';
    }).join('') + '</div>';
    else h += '<div class="note info">' + esc(t('bl.predikat_belum')) + '</div>';
    var rows = sc.rows.slice().sort(function (a, b) { return (a.rank.terbaik || 999) - (b.rank.terbaik || 999); });
    h += '<div class="tbl"><table><tr><th>' + esc(t('bl.peserta')) + '</th><th class="num">' + esc(t('bl.pretest')) + '</th><th class="num">' + esc(t('bl.posttest')) + '</th><th class="num">' + esc(t('bl.progres')) + '</th><th class="num">' + esc(t('bl.praktik')) + '</th><th class="num">' + esc(t('bl.sikap')) + '</th><th class="num">' + esc(t('bl.penyelesaian')) + '</th><th class="num">' + esc(t('bl.skor_terbaik')) + '</th><th>' + esc(t('bl.peringkat')) + '</th></tr>' + rows.map(function (r) {
      return '<tr><td><b>' + esc(r.name) + '</b><div class="small muted">' + esc(r.instansi) + '</div></td><td class="num">' + num(r.pre) + '</td><td class="num">' + num(r.post) + '</td><td class="num">' + num(r.progres) + '</td><td class="num">' + num(r.praktik) + '</td><td class="num">' + num(r.sikap) + '</td><td class="num">' + num(r.complete) + '%</td><td class="num"><b>' + num(r.terbaik) + '</b></td><td class="small">' +
        esc(t('bl.rk_post')) + ': ' + rank(r.rank.post, n) + '<br>' + esc(t('bl.rk_progres')) + ': ' + rank(r.rank.progres, n) + '<br>' + esc(t('bl.rk_complete')) + ': ' + rank(r.rank.complete, n) + '<br>' + esc(t('bl.rk_terbaik')) + ': ' + rank(r.rank.terbaik, n) + '</td></tr>';
    }).join('') + '</table></div>';
    if (d.eval) h += '<div class="card"><h3>' + esc(t('bl.eval_judul')) + '</h3>' + (d.eval.length ? '<div class="tbl"><table><tr><th>' + esc(t('bl.aspek')) + '</th>' + d.eval.map(function (e) { var p = d.narsum.filter(function (x) { return x.id === e.narsumId; })[0]; return '<th class="num">' + esc(p ? p.name : '?') + '<div class="small muted">' + esc(t('bl.n_penilai', { n: e.n })) + '</div></th>'; }).join('') + '</tr>' +
      t('bl.eval_aspek').map(function (asp, i) { return '<tr><td>' + (i + 1) + '. ' + esc(asp) + '</td>' + d.eval.map(function (e) { return '<td class="num">' + num(e.per[i]) + '</td>'; }).join('') + '</tr>'; }).join('') +
      '<tr><td><b>' + esc(t('bl.rata_rata')) + '</b></td>' + d.eval.map(function (e) { return '<td class="num"><b>' + num(e.avg) + '</b></td>'; }).join('') + '</tr></table></div>' : '<p class="muted">' + esc(t('bl.belum_ada_narsum')) + '</p>') + '</div>';
    return h;
  }

  /* ---------- Pengawas: progres & hasil akhir sebagai kartu timeline sesuai rundown ---------- */
  function pengawasPage(d) {
    var k = d.keg, sc = d.scores || { rows: [] }, tl = {};
    (d.timeline || []).forEach(function (x) { tl[x.segId] = x; });
    var big = function (label, v) { return '<div class="stat"><div class="small muted">' + esc(label) + '</div><div style="font-size:1.6rem;font-weight:800">' + v + '</div></div>'; };
    var h = head(k) + '<p class="small muted">' + esc(t('bl.pengawas_halaman_ket')) + '</p><div class="grid g3" style="margin-bottom:14px">' +
      big(t('bl.peserta_diterima'), sc.rows.length) + big(t('bl.kuota_terisi'), k.terisi + ' / ' + k.kuota) + big(t('bl.segmen_ditutup_n'), d.segs.filter(function (s) { return s.status === 'tutup'; }).length + ' / ' + d.segs.length) + '</div>';
    h += '<div class="timeline">' + d.segs.map(function (s, i) {
      var x = tl[s.id] || { done: 0, n: 0 }, pct = x.n ? Math.round(x.done / x.n * 100) : 0;
      return '<div class="tl-item ' + s.status + '"><div class="tl-dot"></div><div class="card"><div class="row"><b class="sp">' + (i + 1) + '. ' + esc(segName(s)) + '</b>' + chipStatus('seg', s.status) + '</div>' +
        '<div class="small muted">' + esc(t('bl.jenis_opsi.' + s.jenis)) + ' · ' + esc(t('bl.pic')) + ': ' + esc(s.picName || '–') + ' · ' + esc(sched(s)) + '</div>' +
        ((s.sesi || []).length ? '<ul class="small" style="margin:6px 0 0 16px;padding:0">' + s.sesi.map(function (y) { return '<li>' + esc(y.title) + ' – ' + esc(sched(y)) + (y.pic ? ' · ' + esc(y.pic) : '') + '</li>'; }).join('') + '</ul>' : '') +
        '<div class="small" style="margin-top:8px">' + esc(t('bl.tl_selesai', { n: x.done, m: x.n })) + (x.avg != null ? ' · ' + esc(t('bl.tl_rata', { v: num(x.avg) })) : '') + '</div>' +
        '<div class="bar"><i style="width:' + pct + '%"></i></div></div></div>';
    }).join('');
    h += '<div class="tl-item ' + (sc.final ? 'tutup' : 'belum') + '"><div class="tl-dot"></div><div class="card"><h3>' + esc(t('bl.hasil_akhir')) + '</h3>';
    if (!sc.final) h += '<div class="note info">' + esc(t('bl.predikat_belum')) + '</div>';
    else {
      var pr = d.predikat || {};
      h += '<div class="grid g3">' + ['post', 'progres', 'terbaik'].map(function (key) {
        return '<div class="card"><div class="small muted">' + esc(t('bl.predikat.' + key)) + '</div>' + ((pr[key] || []).length ? pr[key].map(function (y) { return '<div><b>' + esc(y.name) + '</b><div class="small muted">' + esc(y.instansi) + '</div></div>'; }).join('') : '–') + '</div>';
      }).join('') + '</div>';
      var n = sc.rows.length, rows = sc.rows.slice().sort(function (a, b) { return (a.rank.terbaik || 999) - (b.rank.terbaik || 999); });
      h += '<div class="tbl" style="margin-top:10px"><table><tr><th>' + esc(t('bl.peserta')) + '</th><th class="num">' + esc(t('bl.pretest')) + '</th><th class="num">' + esc(t('bl.posttest')) + '</th><th class="num">' + esc(t('bl.progres')) + '</th><th class="num">' + esc(t('bl.penyelesaian')) + '</th><th class="num">' + esc(t('bl.skor_terbaik')) + '</th><th>' + esc(t('bl.peringkat')) + '</th></tr>' + rows.map(function (r) {
        return '<tr><td><b>' + esc(r.name) + '</b><div class="small muted">' + esc(r.instansi) + '</div></td><td class="num">' + num(r.pre) + '</td><td class="num">' + num(r.post) + '</td><td class="num">' + num(r.progres) + '</td><td class="num">' + num(r.complete) + '%</td><td class="num"><b>' + num(r.terbaik) + '</b></td><td class="small">' + esc(t('bl.rk_terbaik')) + ': ' + rank(r.rank.terbaik, n) + '</td></tr>';
      }).join('') + '</table></div>';
    }
    return h + '</div></div></div>';
  }

  /* ---------- Peserta: riwayat pembelajaran ---------- */
  function riwayatPage() {
    if (me().role !== 'peserta') return H.denied();
    var list = ui.blRiwayat;
    if (!list) { C.api('bl_riwayat', {}).then(function (x) { ui.blRiwayat = x; App.render(); }, function (e) { H.toast(e.message, true); }); return H.loadingCard(); }
    var h = '<h2>' + esc(t('blapp.menu_riwayat')) + '</h2><p class="muted">' + esc(t('bl.riwayat_ket')) + '</p>';
    if (!list.length) return h + '<p class="muted">' + esc(t('bl.riwayat_kosong')) + '</p>';
    return h + '<div class="tbl"><table><tr><th>' + esc(t('bl.judul')) + '</th><th>' + esc(t('bl.tanggal')) + '</th><th>' + esc(t('bl.status')) + '</th><th class="num">' + esc(t('bl.pretest')) + '</th><th class="num">' + esc(t('bl.posttest')) + '</th><th class="num">' + esc(t('bl.progres')) + '</th><th class="num">' + esc(t('bl.penyelesaian')) + '</th><th>' + esc(t('bl.predikat_kol')) + '</th></tr>' + list.map(function (x) {
      var r = x.result;
      return '<tr><td><a href="#bl-' + x.kegId + '"><b>' + esc(x.title) + '</b></a><div class="small muted">' + esc(x.org) + '</div></td><td class="small">' + esc([x.start, x.end].filter(Boolean).join(' s.d. ') || '–') + '</td><td>' + chipStatus('pes', x.status) + (x.kegStatus === 'arsip' ? ' ' + chipStatus('keg', 'arsip') : '') + '</td>' +
        (r ? '<td class="num">' + num(r.pre) + '</td><td class="num">' + num(r.post) + '</td><td class="num">' + num(r.progres) + '</td><td class="num">' + num(r.complete) + '%</td><td class="small">' + (r.predikat.length ? esc(r.predikat.map(function (y) { return t('bl.predikat.' + y); }).join(', ')) : (r.final ? '–' : esc(t('bl.predikat_belum_singkat')))) + '</td>'
          : '<td class="num">–</td><td class="num">–</td><td class="num">–</td><td class="num">–</td><td>–</td>') + '</tr>';
    }).join('') + '</table></div>';
  }

  /* ---------- Admin: akun subdomain ---------- */
  function penggunaPage() {
    if (me().role !== 'admin') return H.denied();
    var us = C.S.users || [], order = { admin: 0, pembina: 1, fasil: 2, pengawas: 3, peserta: 4 };
    us = us.slice().sort(function (a, b) { return (order[a.role] - order[b.role]) || a.name.localeCompare(b.name); });
    var h = '<h2>' + esc(t('blapp.menu_pengguna')) + '</h2><div class="card"><h3>' + esc(t('admin.tambah_akun')) + '</h3><div class="form"><label class="f">' + esc(t('admin.peran')) + '<select class="inp" id="buR">' + ['pembina', 'fasil', 'pengawas'].map(function (r) { return H.opt(r, t('peran.' + r)); }).join('') + '</select></label><label class="f">' + esc(t('admin.nama')) + '<input class="inp" id="buN"></label><label class="f">' + esc(t('admin.username')) + '<input class="inp" id="buU"></label><label class="f">' + esc(t('blapp.instansi')) + '<input class="inp" id="buI"></label><div><button class="btn" data-act="blUserAdd">' + esc(t('admin.tombol_tambah_akun')) + '</button></div></div><p class="small muted">' + esc(t('blapp.pengguna_ket')) + '</p></div>';
    return h + '<div class="tbl"><table><tr><th>' + esc(t('admin.peran')) + '</th><th>' + esc(t('admin.nama')) + '</th><th>' + esc(t('admin.username')) + '</th><th>' + esc(t('blapp.instansi')) + '</th><th>' + esc(t('blapp.email')) + '</th><th>' + esc(t('blapp.hp')) + '</th><th>' + esc(t('blapp.masuk_terakhir')) + '</th><th>' + esc(t('blapp.aktif')) + '</th><th></th></tr>' + us.map(function (x) {
      var adm = x.role === 'admin', inp = function (k, v) { return '<td class="in"><input class="cell" data-bluser="' + x.id + '" data-k="' + k + '" value="' + esc(v) + '"></td>'; };
      return '<tr><td>' + esc(t('peran.' + x.role)) + '</td>' + inp('nama', x.name) + inp('username', x.username) + inp('instansi', x.instansi) + inp('email', x.email) + inp('hp', x.hp) + '<td class="small">' + esc(x.lastLogin || '–') + '</td><td>' + (adm ? '' : '<input type="checkbox" data-act="blUserActive" data-id="' + x.id + '"' + (x.active ? ' checked' : '') + '>') + '</td><td>' + (adm ? '' : '<button class="btn o s" data-act="blUserReset" data-id="' + x.id + '">' + esc(t('admin.reset_sandi')) + '</button>') + '</td></tr>';
    }).join('') + '</table></div>';
  }
  function credModal(r) {
    H.modal('<h3>' + esc(t('admin.sandi_sementara')) + '</h3><p>' + esc(t('admin.sandi_sementara_ket')) + '</p><div class="tbl"><table><tr><th>' + esc(t('admin.username')) + '</th><th>' + esc(t('admin.sandi')) + '</th></tr><tr><td><code>' + esc(r.username) + '</code></td><td><code>' + esc(r.password) + '</code></td></tr></table></div><div class="row" style="margin-top:12px"><span class="sp"></span><button class="btn" data-close>' + esc(t('umum.tutup')) + '</button></div>');
  }

  /* ---------- Peserta: mendaftarkan diri ---------- */
  function regPage(d) {
    var k = d.keg, g = d.reg, open = k.formOpen;
    var back = '<div class="small"><a href="#bl">← ' + esc(t('blapp.menu_kegiatan')) + '</a></div>';
    if (g && g.status === 'menunggu') {
      poll = setInterval(function () { if (H.route() === 'bl-' + k.id) load(k.id).then(App.render); }, 30000);
      return back + '<div class="card plain-wait"><p>' + esc(t('bl.menunggu_verifikasi')) + '</p></div>';
    }
    var h = back + head(k).replace(/^<div class="small">.*?<\/div>/, '');
    if (g && g.status === 'ditolak') return h + '<div class="card"><div class="row"><h3 class="sp">' + esc(t('bl.konfirmasi_kehadiran')) + '</h3>' + chipStatus('pes', 'ditolak') + '</div><div class="note bad"><b>' + esc(t('bl.pesan_admin')) + '</b><div style="white-space:pre-line">' + esc(g.msg) + '</div></div></div>';
    if (g && g.status === 'lengkapi') return h + '<div class="card"><div class="row"><h3 class="sp">' + esc(t('bl.konfirmasi_kehadiran')) + '</h3>' + chipStatus('pes', 'lengkapi') + '</div><div class="note"><b>' + esc(t('bl.pesan_admin')) + '</b><div style="white-space:pre-line">' + esc(g.msg) + '</div></div>' + regForm(g, true) + '</div>';
    if (!open) return h + '<p class="muted">' + esc(formState(k)) + '</p>';
    return h + '<div class="card"><h3>' + esc(t('bl.konfirmasi_kehadiran')) + '</h3><p class="small muted">' + esc(t('bl.daftar_ket')) + '</p>' + regForm(d.self, false) + '</div>';
  }
  function regForm(v, ulang) {
    var f = function (id, key, ph, type) { return '<label class="f">' + esc(t(key)) + '<input class="inp" ' + (type ? 'type="' + type + '" ' : '') + 'id="' + id + '" placeholder="' + esc(ph ? t(ph) : '') + '" value="' + esc(v[{ brN: 'name', brJ: 'jabatan', brP: 'nip', brI: 'instansi', brE: 'email', brH: 'hp' }[id]] || '') + '"></label>'; };
    return '<div class="form" style="margin-top:8px">' + f('brN', 'bl.nama_gelar', 'bl.ph_nama_gelar') + f('brJ', 'bl.jabatan', 'bl.ph_jabatan') + f('brP', 'bl.nip', 'bl.ph_nip') + f('brI', 'bl.instansi', 'blapp.ph_instansi') + f('brE', 'blapp.email', '', 'email') + f('brH', 'blapp.hp', 'blapp.ph_hp', 'tel') +
      '<label class="f">' + esc(t(ulang ? 'bl.surat_tugas_baru' : 'bl.surat_tugas_pdf')) + '<input type="file" id="brS" accept="application/pdf"></label></div>' +
      '<div class="row" style="margin-top:12px"><button class="btn" data-act="blKirim" data-ulang="' + (ulang ? 1 : 0) + '">' + esc(t(ulang ? 'bl.kirim_ulang' : 'bl.kirim')) + '</button><button class="btn o" data-act="blKosongkan">' + esc(t('bl.kosongkan')) + '</button><a class="btn o" href="#bl">' + esc(t('umum.batal')) + '</a></div>';
  }

  /* ---------- Peserta (sub-akun) ---------- */
  function pesertaHome(d) {
    var k = d.keg, done = d.done || {};
    var h = '<div class="small"><a href="#bl">← ' + esc(t('blapp.menu_kegiatan')) + '</a></div><div class="card welcome"><h2>' + esc(t('bl.selamat_datang', { nama: d.me.name })) + '</h2><p>' + esc(t('bl.selamat_datang_ket', { keg: k.title })) + '</p><p class="small muted">' + esc([k.org, k.place, [k.start, k.end].filter(Boolean).join(' s.d. ')].filter(Boolean).join(' · ')) + '</p></div>';
    if (d.result) h += resultCard(d);
    h += '<h3>' + esc(t('bl.segmen')) + '</h3>' + d.segs.map(function (s, i) {
      var isDone = !!done[s.id];
      var btn = isDone ? H.chip(t('bl.selesai'), 'ok') : s.status === 'buka' ? '<a class="btn s" href="#bl-' + k.id + '-s' + s.id + '">' + esc(t('bl.mulai_segmen')) + '</a>' : chipStatus('seg', s.status);
      return '<div class="card seg-card' + (s.status === 'buka' && !isDone ? ' live' : '') + '"><div class="row"><b class="sp">' + (i + 1) + '. ' + esc(segName(s)) + '</b>' + btn + '</div><div class="small muted">' + esc(t('bl.jenis_opsi.' + s.jenis)) + ' · ' + esc(t('bl.pic')) + ': ' + esc(s.picName || '–') + ' · ' + esc(sched(s)) + '</div>' +
        ((s.sesi || []).length ? '<ul class="small" style="margin:6px 0 0 16px;padding:0">' + s.sesi.map(function (x) { return '<li>' + esc(x.title) + ' – ' + esc(sched(x)) + (x.pic ? ' · ' + esc(x.pic) : '') + '</li>'; }).join('') + '</ul>' : '') + '</div>';
    }).join('');
    return h;
  }
  function resultCard(d) {
    var r = d.result.mine, n = d.result.n;
    var h = '<div class="card"><h3>' + esc(t('bl.hasil_saya')) + '</h3><div class="grid g3">' +
      [['post', r.post, r.rank.post], ['progres', r.progres, r.rank.progres], ['complete', r.complete, r.rank.complete]].map(function (x) {
        return '<div class="stat"><div class="small muted">' + esc(t('bl.kategori.' + x[0])) + '</div><div style="font-size:1.6rem;font-weight:800">' + num(x[1]) + (x[0] === 'complete' ? '%' : '') + '</div><div class="small">' + rank(x[2], n) + '</div></div>';
      }).join('') + '</div><p class="small muted">' + esc(t('bl.nilai_pre_post', { pre: num(r.pre), post: num(r.post) })) + '</p>';
    var pr = d.result.predikat;
    if (pr) {
      var mineP = ['post', 'progres', 'terbaik'].filter(function (key) { return pr[key].some(function (x) { return x.me; }); });
      if (mineP.length) h += '<div class="note ok congrats"><b>' + esc(t('bl.selamat_predikat', { predikat: mineP.map(function (x) { return t('bl.predikat.' + x); }).join(', ') })) + '</b></div>';
      h += '<h4>' + esc(t('bl.peraih_predikat')) + '</h4><div class="grid g3">' + ['post', 'progres', 'terbaik'].map(function (key) {
        return '<div class="card"><div class="small muted">' + esc(t('bl.predikat.' + key)) + '</div>' + (pr[key].length ? pr[key].map(function (x) { return '<div><b>' + esc(x.name) + '</b><div class="small muted">' + esc(x.instansi) + '</div></div>'; }).join('') : '–') + '<div class="small" style="margin-top:6px">' + esc(t('bl.ucapan_predikat')) + '</div></div>';
      }).join('') + '</div>';
    } else h += '<div class="note info">' + esc(t('bl.predikat_belum')) + '</div>';
    return h + '</div>';
  }
  function pesertaSeg(d, segId) {
    var s = d.segs.filter(function (x) { return String(x.id) === String(segId); })[0];
    if (!s) return H.denied();
    var k = d.keg, done = !!(d.done || {})[s.id];
    var h = '<div class="small"><a href="#bl-' + k.id + '">← ' + esc(k.title) + '</a></div><div class="row"><h2 class="sp">' + esc(segName(s)) + '</h2>' + (done ? H.chip(t('bl.selesai'), 'ok') : chipStatus('seg', s.status)) + '</div><p class="muted">' + esc(t('bl.pic')) + ': ' + esc(s.picName || '–') + ' · ' + esc(sched(s)) + '</p>';
    if (done) return h + '<div class="note ok">' + esc(t('bl.segmen_sudah_selesai')) + '</div>';
    if (s.status !== 'buka') return h + '<div class="note info">' + esc(t(s.status === 'tutup' ? 'bl.segmen_ditutup' : 'bl.segmen_belum_dibuka')) + '</div>';
    if (s.jenis === 'pretest' || s.jenis === 'posttest') {
      var tst = (d.tests || {})[s.id] || { soal: [], answers: {} };
      var nAns = Object.keys(tst.answers).length;
      h += '<div class="note info">' + esc(t(s.jenis === 'pretest' ? 'bl.ket_pretest' : 'bl.ket_posttest')) + '</div><p class="sticky-count"><b id="blCount">' + esc(t('bl.terjawab', { n: nAns, m: tst.soal.length })) + '</b></p>';
      h += tst.soal.map(function (q, i) {
        return '<div class="card"><div style="white-space:pre-line"><b>' + (i + 1) + '.</b> ' + esc(q.text) + '</div><div style="margin-top:6px">' + ['a', 'b', 'c', 'd'].map(function (x) {
          return '<label class="opt-row"><input type="radio" name="q' + q.id + '" data-bljawab="' + q.id + '" data-seg="' + s.id + '" value="' + x + '"' + (tst.answers[q.id] === x ? ' checked' : '') + '> <b>' + x.toUpperCase() + '.</b> ' + esc(q[x]) + '</label>';
        }).join('') + '</div></div>';
      }).join('');
    } else if (s.jenis === 'materi' || s.jenis === 'praktik') {
      if ((s.sesi || []).length) h += '<div class="card"><h3>' + esc(t('bl.sesi')) + '</h3><ul style="margin:0 0 0 18px;padding:0">' + s.sesi.map(function (x) { return '<li>' + esc(x.title) + ' – ' + esc(sched(x)) + (x.pic ? ' · ' + esc(x.pic) : '') + '</li>'; }).join('') + '</ul></div>';
      var np = d.narsum.filter(function (p) { return p.id === s.picId; })[0];
      if (np) h += '<div class="card"><h3>' + esc(t('bl.narasumber')) + '</h3>' + narsumCard(np) + '</div>';
      h += '<div class="card"><h3>' + esc(t('bl.materi')) + '</h3>' + (d.materiLocked ? '<p class="muted">' + esc(t('bl.materi_terkunci')) + '</p>' : materiList(d, false, s.id)) + '</div>';
    } else if (s.jenis === 'penutup') {
      var ev = d.myEval || {}, asp = t('bl.eval_aspek');
      h += '<p class="muted">' + esc(t('bl.eval_ket')) + '</p>' + (d.narsum.length ? d.narsum.map(function (p) {
        var mine = ev[p.id] || {};
        return '<div class="card">' + narsumCard(p) + '<div class="tbl" style="margin-top:8px"><table><tr><th>' + esc(t('bl.aspek')) + '</th><th>' + esc(t('bl.nilai_0_100')) + '</th></tr>' + asp.map(function (a, i) {
          return '<tr><td>' + (i + 1) + '. ' + esc(a) + '</td><td class="in"><input class="cell" type="number" min="0" max="100" style="width:90px" data-bleval="' + p.id + '" data-asp="' + i + '" data-seg="' + s.id + '" value="' + (mine[i] == null ? '' : mine[i]) + '"></td></tr>';
        }).join('') + '</table></div></div>';
      }).join('') : '<p class="muted">' + esc(t('bl.belum_ada_narsum')) + '</p>');
    }
    return h + '<div class="row" style="margin:16px 0"><span class="sp"></span><button class="btn" data-act="blSelesai" data-id="' + s.id + '">' + esc(t('bl.selesaikan_segmen')) + '</button></div>';
  }

  /* ---------- aksi ---------- */
  function cur() { return cache[kegId()]; }
  function confirmBox(title, text, okLabel, fn, danger) {
    var m = H.modal('<h3>' + esc(title) + '</h3><p style="white-space:pre-line">' + esc(text) + '</p><div class="row"><span class="sp"></span><button class="btn o" data-close>' + esc(t('umum.batal')) + '</button><button class="btn' + (danger ? ' d' : '') + '" id="cbOK">' + esc(okLabel) + '</button></div>');
    $('#cbOK', m).onclick = function () { m.remove(); fn(); };
  }

  // data kegiatan dimuat ulang setiap pindah halaman agar status segmen selalu terbaru
  window.addEventListener('hashchange', function () { cache = {}; ui.blRiwayat = null; ui.blProfil = null; });
  App.addActions({
    blKegAdd: function () { act('bl_keg_add', { title: $('#bkT').value, org: $('#bkO').value, kuota: $('#bkK').value }, t('bl.dibuat')).then(function (r) { H.go('bl-' + r.id); }); },
    blTab: function (el) { ui.blTab[kegId()] = el.dataset.v; ui.blEditSoal = null; App.render(); },
    blReload: function () { load(kegId()).then(function () { H.keepScroll(); App.render(); }); },
    blKegStatus: function (el) { var v = el.dataset.v; confirmBox(t('bl.status'), t('bl.konfirm_' + v), t(v === 'terbit' ? 'bl.terbitkan' : 'bl.kembali_draf'), function () { act('bl_keg_status', { id: +kegId(), v: v }, t('umum.tersimpan')); }); },
    blArsip: function () { confirmBox(t('bl.arsipkan'), t('bl.konfirm_arsip'), t('bl.arsipkan'), function () { act('bl_keg_status', { id: +kegId(), v: 'arsip' }, t('umum.tersimpan')); }, true); },
    blDaftar: function (el) { H.keepScroll(); act('bl_keg_daftar', { id: +kegId(), v: el.checked }, t('umum.tersimpan')); },
    blSegAdd: function (el) { H.keepScroll(); act('bl_seg_add', { kegId: +kegId(), jenis: el.dataset.v }, t('umum.ditambahkan')); },
    blSegMove: function (el) { H.keepScroll(); act('bl_seg_move', { id: +el.dataset.id, dir: +el.dataset.v }); },
    blSegDel: function (el) { confirmBox(t('sd.hapus'), t('bl.konfirm_hapus_segmen'), t('sd.hapus'), function () { H.keepScroll(); act('bl_seg_del', { id: +el.dataset.id }, t('sd.terhapus')); }, true); },
    blSegState: function (el) {
      var v = el.dataset.v, s = cur().segs.filter(function (x) { return String(x.id) === el.dataset.id; })[0];
      confirmBox(segName(s), t(v === 'buka' ? 'bl.konfirm_buka' : 'bl.konfirm_tutup'), t(v === 'buka' ? 'bl.mulai_segmen_staf' : 'bl.tutup_segmen'), function () { H.keepScroll(); act('bl_seg_state', { id: s.id, v: v }, t(v === 'buka' ? 'bl.segmen_dibuka' : 'bl.segmen_ditutup_ok')); }, v === 'tutup');
    },
    blSesi: function (el) {
      var s = cur().segs.filter(function (x) { return String(x.id) === el.dataset.id; })[0], list = (s.sesi || []).slice();
      var row = function (x, i) { return '<tr><td><input class="cell" data-i="' + i + '" data-k="title" value="' + esc(x.title || '') + '" style="min-width:160px"></td><td><input class="cell" type="date" data-i="' + i + '" data-k="date" value="' + esc(x.date || '') + '"></td><td><input class="cell" type="time" data-i="' + i + '" data-k="from" value="' + esc(x.from || '') + '"></td><td><input class="cell" type="time" data-i="' + i + '" data-k="to" value="' + esc(x.to || '') + '"></td><td><input class="cell" data-i="' + i + '" data-k="pic" value="' + esc(x.pic || '') + '"></td></tr>'; };
      if (!list.length) list.push({});
      var draw = function () { return '<h3>' + esc(t('bl.sesi_judul', { seg: segName(s) })) + '</h3><p class="small muted">' + esc(t('bl.sesi_ket')) + '</p><div class="tbl"><table id="sesiT"><tr><th>' + esc(t('bl.judul_sesi')) + '</th><th>' + esc(t('bl.tanggal')) + '</th><th>' + esc(t('bl.jam_mulai')) + '</th><th>' + esc(t('bl.jam_selesai')) + '</th><th>' + esc(t('bl.pic')) + '</th></tr>' + list.map(row).join('') + '</table></div><div class="row" style="margin-top:10px"><button class="btn o s" id="sesiAdd">+ ' + esc(t('bl.sesi')) + '</button><span class="sp"></span><button class="btn o" data-close>' + esc(t('umum.batal')) + '</button><button class="btn" id="sesiOK">' + esc(t('umum.simpan')) + '</button></div>'; };
      var m = H.modal(draw());
      var read = function () { m.querySelectorAll('#sesiT input').forEach(function (inp) { list[+inp.dataset.i][inp.dataset.k] = inp.value; }); };
      var wire = function () {
        $('#sesiAdd', m).onclick = function () { read(); list.push({}); m.querySelector('.modal').innerHTML = draw(); wire(); };
        $('#sesiOK', m).onclick = function () { read(); m.remove(); H.keepScroll(); act('bl_seg_sesi', { id: s.id, sesi: list }, t('umum.tersimpan')); };
      };
      wire();
    },
    blVerif: function (el) {
      var v = el.dataset.v, p = cur().peserta.filter(function (x) { return String(x.id) === el.dataset.id; })[0];
      var m = H.modal('<h3>' + esc(t('bl.verif_judul.' + v)) + '</h3><p><b>' + esc(p.name) + '</b>' + (p.instansi ? ' – ' + esc(p.instansi) : '') + '</p>' + (v === 'diterima' ? '<p>' + esc(t('bl.verif_terima_ket')) + '</p>' : '<label class="f">' + esc(t('bl.pesan_untuk_peserta')) + '<textarea class="inp" rows="4" id="vMsg"></textarea></label>') + '<div class="row" style="margin-top:10px"><span class="sp"></span><button class="btn o" data-close>' + esc(t('umum.batal')) + '</button><button class="btn' + (v === 'ditolak' ? ' d' : '') + '" id="vOK">' + esc(t('bl.verif_tombol.' + v)) + '</button></div>');
      $('#vOK', m).onclick = function () {
        var msg = $('#vMsg', m) ? $('#vMsg', m).value : '';
        m.remove(); H.keepScroll();
        act('bl_verif', { id: p.id, v: v, msg: msg }, t('bl.verif_tersimpan'));
      };
    },
    blSoalEdit: function (el) { ui.blEditSoal = +el.dataset.id || null; App.render(); },
    blSoalSave: function (el) {
      var data = { kegId: +kegId(), id: +el.dataset.id, text: $('#sqT').value, oa: $('#sqa').value, ob: $('#sqb').value, oc: $('#sqc').value, od: $('#sqd').value, key: $('#sqK').value };
      H.keepScroll(); act('bl_soal_save', data, t('umum.tersimpan')).then(function () { ui.blEditSoal = null; App.render(); });
    },
    blSoalDel: function (el) { confirmBox(t('sd.hapus'), t('bl.konfirm_hapus_soal'), t('sd.hapus'), function () { H.keepScroll(); act('bl_soal_del', { id: +el.dataset.id }, t('sd.terhapus')); }, true); },
    blMateriUp: function () {
      var f = $('#bmF').files[0]; if (!f) return H.toast(t('bl.pilih_berkas'), true);
      var fd = new FormData(); fd.append('kegId', kegId()); fd.append('title', $('#bmT').value); fd.append('segId', $('#bmS').value); fd.append('file', f);
      act('bl_materi_upload', null, t('bl.materi_terunggah'), { form: fd });
    },
    blMateriDel: function (el) { confirmBox(t('sd.hapus'), t('bl.konfirm_hapus_materi'), t('sd.hapus'), function () { H.keepScroll(); act('bl_materi_del', { id: +el.dataset.id }, t('sd.terhapus')); }, true); },
    blAspAdd: function (el) { var key = el.dataset.j + '-' + el.dataset.seg; H.keepScroll(); act('bl_aspek_add', { kegId: +kegId(), jenis: el.dataset.j, segId: +el.dataset.seg, name: $('#ba-' + key).value, def: +$('#bd-' + key).value }, t('umum.ditambahkan')); },
    blAspDel: function (el) { confirmBox(t('sd.hapus'), t('bl.konfirm_hapus_aspek'), t('sd.hapus'), function () { H.keepScroll(); act('bl_aspek_del', { id: +el.dataset.id }, t('sd.terhapus')); }, true); },
    blProfilSave: function () { C.api('bl_profil_save', { instansi: $('#bpI').value, jabatan: $('#bpJ').value, ringkasan: $('#bpR').value }).then(function (r) { ui.blProfil = null; H.toast(t('umum.tersimpan')); App.render(); }, function (e) { H.toast(e.message, true); }); },
    blUserAdd: function () { act('bl_user_add', { role: $('#buR').value, name: $('#buN').value, username: $('#buU').value, instansi: $('#buI').value }, t('umum.ditambahkan')).then(credModal); },
    blUserActive: function (el) { H.keepScroll(); act('bl_user_active', { id: +el.dataset.id, v: el.checked }, t('umum.tersimpan')); },
    blUserReset: function (el) { confirmBox(t('admin.reset_sandi'), t('blapp.konfirm_reset'), t('admin.reset_sandi'), function () { act('bl_user_reset', { id: +el.dataset.id }).then(credModal); }); },
    blKosongkan: function () { ['#brN', '#brJ', '#brP', '#brI', '#brE', '#brH', '#brS'].forEach(function (x) { var e = $(x); if (e) e.value = ''; }); },
    blKirim: function (el) {
      var ulang = el.dataset.ulang === '1', f = $('#brS').files[0];
      if (!ulang && !f) return H.toast(t('bl.pilih_surat'), true);
      var fd = new FormData(); fd.append('kegId', kegId()); fd.append('name', $('#brN').value); fd.append('jabatan', $('#brJ').value); fd.append('nip', $('#brP').value); fd.append('instansi', $('#brI').value); fd.append('email', $('#brE').value); fd.append('hp', $('#brH').value);
      if (f) fd.append('surat', f);
      act(ulang ? 'bl_kirim_ulang' : 'bl_daftar', null, t('bl.terkirim'), { form: fd });
    },
    blSelesai: function (el) {
      var d = cur(), s = d.segs.filter(function (x) { return String(x.id) === el.dataset.id; })[0], msg = t('bl.konfirm_selesai');
      if (s.jenis === 'pretest' || s.jenis === 'posttest') {
        var tst = d.tests[s.id], left = tst.soal.length - Object.keys(tst.answers).length;
        if (left > 0) msg = t('bl.konfirm_selesai_kosong', { n: left });
      }
      confirmBox(t('bl.selesaikan_segmen'), msg, t('bl.selesaikan_segmen'), function () { act('bl_selesai', { segId: s.id }, t('bl.segmen_selesai_ok')).then(function () { H.go('bl-' + d.keg.id); }); });
    }
  });

  /* ---------- perubahan isian ---------- */
  App.addChange(function (el, d) {
    var id = kegId();
    if (d.bluser) { H.keepScroll(); act('bl_user_update', { id: +d.bluser, k: d.k, v: el.value }, t('umum.tersimpan')); return true; }
    if (d.blk) { H.keepScroll(); act('bl_keg_update', { id: +id, k: d.blk, v: el.value }, t('umum.tersimpan')); return true; }
    if (d.blseg) { H.keepScroll(); act('bl_seg_update', { id: +d.blseg, k: d.k, v: el.value }, t('umum.tersimpan')); return true; }
    if (d.blpeople) {
      var ids = Array.prototype.map.call(document.querySelectorAll('[data-blpeople="' + d.blpeople + '"]:checked'), function (x) { return +x.value; });
      H.keepScroll(); act('bl_keg_people', { id: +id, k: d.blpeople, ids: ids }, t('umum.tersimpan')); return true;
    }
    if (d.blasp) { H.keepScroll(); act('bl_aspek_update', { id: +d.blasp, k: d.k, v: el.value }, t('umum.tersimpan')); return true; }
    if (d.blnilai) { H.keepScroll(); act('bl_nilai', { pesertaId: +d.blnilai, aspekId: +d.asp, v: el.value }, t('umum.tersimpan')); return true; }
    if (d.bljawab) {
      var tst = cur().tests[d.seg];
      C.api('bl_jawab', { segId: +d.seg, soalId: +d.bljawab, v: el.value }).then(function () {
        tst.answers[d.bljawab] = el.value;
        var c = $('#blCount'); if (c) c.textContent = t('bl.terjawab', { n: Object.keys(tst.answers).length, m: tst.soal.length });
      }, function (e) { H.toast(e.message, true); load(id).then(App.render); });
      return true;
    }
    if (d.bleval) {
      C.api('bl_eval', { segId: +d.seg, narsumId: +d.bleval, aspek: +d.asp, v: el.value }).then(function () {
        var ev = cur().myEval; ev[d.bleval] = ev[d.bleval] || {}; if (el.value === '') delete ev[d.bleval][d.asp]; else ev[d.bleval][d.asp] = +el.value;
      }, function (e) { H.toast(e.message, true); el.value = ''; });
      return true;
    }
    if (el.id === 'blFoto' && el.files && el.files[0]) {
      var fd = new FormData(); fd.append('file', el.files[0]);
      C.api('bl_profil_foto', null, { form: fd }).then(function () { ui.blProfil = null; H.toast(t('sd.foto_tersimpan')); App.render(); }, function (e) { H.toast(e.message, true); });
      return true;
    }
    return false;
  });
})();
