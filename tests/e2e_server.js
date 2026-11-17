// Uji integrasi Si PANDU Tahap 1: server PHP + basis data + peramban (3 peran, hak akses, keamanan)
const { chromium } = require('/opt/npm-tools/node_modules/playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8099';
const TOKEN = process.env.TOKEN || 'TOKEN-UJI-123';
(async () => {
  const browser = await chromium.launch({ executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox'] });
  let fail = 0, n = 0; const ok = (c, m) => { n++; if (!c) { fail++; console.log('GAGAL', m); } else console.log('ok  ', m); };
  const errors = [];
  const newPage = async () => { const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 } }); const p = await ctx.newPage(); p.on('pageerror', e => errors.push(e.message)); p.on('console', m => { if (m.type() === 'error' && !/fonts\.g|ERR_|Failed to load resource/.test(m.text())) errors.push(m.text()); }); return p; };
  const txt = p => p.evaluate(() => document.body.innerText);
  const go = async (p, h) => { await p.evaluate(h => location.hash = h, h); await p.waitForTimeout(250); };
  const api = (p, a, d) => p.evaluate(([a, d]) => Core.api(a, d).then(r => ({ ok: true, r }), e => ({ ok: false, e: e.message })), [a, d]);

  // ---- Pemasangan
  const P0 = await newPage();
  await P0.goto(BASE + '/install.php?token=salah'); ok(/tidak valid/.test(await txt(P0)), 'Pemasangan tanpa token benar ditolak');
  await P0.goto(BASE + '/install.php?token=' + TOKEN);
  await P0.fill('[name=p1]', 'pendek1'); await P0.fill('[name=p2]', 'pendek1'); await P0.click('button[type=submit]');
  ok(/Minimal 10 karakter/.test(await txt(P0)), 'Sandi admin lemah ditolak');
  await P0.fill('[name=p1]', 'AdminKuat2026'); await P0.fill('[name=p2]', 'AdminKuat2026'); await P0.click('button[type=submit]');
  ok(/PEMASANGAN SELESAI/.test(await txt(P0)), 'Pemasangan selesai');
  const acc = await P0.evaluate(() => Object.fromEntries([...document.querySelectorAll('#acc tr')].slice(1).map(r => [r.children[2].innerText, r.children[3].innerText])));
  ok(Object.keys(acc).length === 29 && acc.pembina1 && acc.pkm27, 'Akun awal: 2 Pembina + 27 Binaan dengan sandi sementara (' + Object.keys(acc).length + ')');
  await P0.goto(BASE + '/install.php?token=' + TOKEN); ok(/sudah terpasang/.test(await txt(P0)), 'Pemasangan terkunci setelah selesai');

  const login = async (p, u, pw) => { await p.goto(BASE + '/'); await p.fill('[name=username]', u); await p.fill('[name=password]', pw); await p.click('button[type=submit]'); await p.waitForLoadState(); await p.waitForTimeout(200); };
  const firstLogin = async (p, u, pw, npw) => { await login(p, u, pw); ok(/ganti kata sandi sementara/.test(await txt(p)), u + ': wajib ganti sandi saat masuk pertama'); await p.fill('[name=old]', pw); await p.fill('[name=new]', npw); await p.fill('[name=new2]', npw); await p.click('button[type=submit]'); await p.waitForLoadState(); await p.waitForTimeout(400); };

  const apiSetup = (p, tag) => p.evaluate(async tag => {
    const A = (a, d) => Core.api(a, d);
    const blob = await new Promise(r => { const c = document.createElement('canvas'); c.width = 300; c.height = 400; c.getContext('2d').fillRect(0, 0, 300, 400); c.toBlob(r, 'image/jpeg'); });
    const pid = (await A('sdm_add', { name: 'Petugas ' + tag, jabatan: 'ATLM' })).id;
    const fd = new FormData(); fd.append('file', blob, 'f.jpg'); fd.append('id', pid); await Core.api('sdm_foto', null, { form: fd });
    const al = (await A('alat_add', { name: 'Fotometer ' + tag, cat: 'Kimia' })).id;
    const items = [{ text: 'Uji', L: 1, S: 1, control: '', plan: '' }];
    for (const a of ['bahan', 'lingkungan', 'personel']) await A('risk_save', { aspect: a, items });
    await A('risk_save', { aspect: 'alat', alatId: al, items });
    return Core.S.setup;
  }, tag);

  // ---- Lockout
  const PL = await newPage();
  for (let i = 0; i < 5; i++) await login(PL, 'pkm27', 'salah' + i);
  ok(/salah/.test(await txt(PL)), 'Sandi salah ditolak');
  await login(PL, 'pkm27', acc.pkm27);
  ok(/Terlalu banyak percobaan/.test(await txt(PL)), 'Akun terkunci setelah 5 kali gagal (walau sandi benar)');

  // ---- Administrator
  const A = await newPage();
  await login(A, 'admin', 'AdminKuat2026');
  ok(/Beranda Administrator/.test(await txt(A)), 'Admin masuk');
  ok(/1 Administrator · 2 Pembina · 27 Binaan/.test(await txt(A)), 'Komposisi akun awal sesuai');
  await go(A, 'ref-pkm'); await A.fill('#npkm', 'Puskesmas Uji 28'); await A.click('[data-act=pkmAdd]'); await A.waitForTimeout(300);
  ok(/Puskesmas Uji 28/.test(await A.content()), 'Admin menambah Puskesmas baru');
  const pkmId = await A.evaluate(() => Core.S.pkms.find(p => p.name === 'Puskesmas Uji 28').id);
  await go(A, 'pengguna');
  await A.selectOption('#nuRole', 'binaan'); await A.fill('#nuName', 'Lab Puskesmas Uji 28'); await A.fill('#nuUser', 'pkm28'); await A.selectOption('#nuPkm', String(pkmId)); await A.click('[data-act=userAdd]'); await A.waitForTimeout(400);
  const pw28 = await A.evaluate(() => document.querySelector('.modal code:last-of-type') && [...document.querySelectorAll('.modal code')][1].innerText);
  ok(!!pw28 && pw28.length === 10, 'Admin menambah akun Binaan baru → sandi sementara tampil sekali');
  await A.click('.modal [data-close]');
  await A.selectOption('#nuRole', 'pembina'); await A.fill('#nuName', 'Pembina 3'); await A.fill('#nuUser', 'pembina3'); await A.click('[data-act=userAdd]'); await A.waitForTimeout(400);
  ok(await A.evaluate(() => !!document.querySelector('.modal')), 'Admin menambah akun Pembina baru');
  await A.click('.modal [data-close]');
  ok(/1 Administrator · 3 Pembina · 28 Binaan/.test(await (async () => { await go(A, 'beranda'); return txt(A); })()), 'Jumlah akun tidak dibatasi 30 (kini 32)');
  await go(A, 'pengguna'); await A.fill('#nuUser', 'pkm28'); await A.fill('#nuName', 'x'); await A.click('[data-act=userAdd]'); await A.waitForTimeout(300);
  ok(/sudah dipakai/.test(await txt(A)), 'Nama pengguna ganda ditolak');
  // alat: Admin hanya melihat
  const aAl = await api(A, 'alat_add', { name: 'X', cat: 'Kimia', pkmId: 1 }); ok(!aAl.ok, 'Admin tidak bisa menambah alat (' + aAl.e + ')');
  await go(A, 'ref-alat'); ok(/hanya untuk melihat/.test(await txt(A)) && !(await A.locator('#naN').count()), 'Referensi Alat untuk Admin: tampilan saja');
  // spesifikasi TEa glukosa (id 1) → VB CVI 5 CVG 8 Desirable
  await go(A, 'ref-param');
  await A.selectOption('[data-par="1"][data-k=specSrc]', 'bv'); await A.waitForTimeout(300);
  await A.fill('[data-par="1"][data-k=cvi]', '5'); await A.press('[data-par="1"][data-k=cvi]', 'Tab'); await A.waitForTimeout(300);
  await A.fill('[data-par="1"][data-k=cvg]', '8'); await A.press('[data-par="1"][data-k=cvg]', 'Tab'); await A.waitForTimeout(300);
  const tea = await A.evaluate(() => Core.S.params.find(p => p.id === 1).spec.tea);
  ok(Math.abs(tea - (1.65 * 2.5 + 0.25 * Math.sqrt(89))) < 1e-9, 'Spesifikasi TEa VB dihitung di server: ' + tea.toFixed(4));
  // reset sandi pkm02
  await go(A, 'pengguna'); const uid2 = await A.evaluate(() => Core.S.users.find(u => u.username === 'pkm02').id);
  await A.click(`[data-act=resetPw][data-id="${uid2}"]`); await A.click('#rpOK'); await A.waitForTimeout(400);
  const pw2 = await A.evaluate(() => [...document.querySelectorAll('.modal code')][1].innerText); await A.click('.modal [data-close]');
  ok(pw2 && pw2 !== acc.pkm02, 'Admin reset sandi → sandi sementara baru');
  // nonaktifkan pkm26
  const uid26 = await A.evaluate(() => Core.S.users.find(u => u.username === 'pkm26').id);
  await A.click(`[data-act=userActive][data-id="${uid26}"]`); await A.waitForTimeout(300);
  const PD = await newPage(); await login(PD, 'pkm26', acc.pkm26); ok(/salah/.test(await txt(PD)), 'Akun nonaktif tidak bisa masuk');

  // ---- Binaan pkm01
  const B = await newPage();
  await firstLogin(B, 'pkm01', acc.pkm01, 'Puskesmas01Baru');
  ok(/Lengkapi data awal/.test(await txt(B)) && !/Beranda · Puskesmas Binaan 01/.test(await txt(B)), 'Binaan setelah ganti sandi: wajib isi data awal (beranda terkunci)');
  await go(B, 'qc'); ok(/Lengkapi data awal/.test(await txt(B)) && !(await B.locator('#nsParam').count()), 'Menu QC terkunci sebelum data awal lengkap');
  ok(await B.locator('.nav a.lock').count() > 5 && !(await B.locator('.nav a.lock[href="#sd-sdm"]').count()) && !(await B.locator('.nav a.lock[href="#risiko"]').count()) && !(await B.locator('.nav a.lock[href="#kop"]').count()), 'Menu terbuka: Sumber Daya, Manajemen Risiko, Kop; lainnya terkunci');
  const g1 = await api(B, 'sheet_create', { paramId: 1, alatId: 1, lv: 'all' }); ok(!g1.ok && /Lengkapi dulu/.test(g1.e), 'Server menolak aksi QC sebelum data awal lengkap');
  // SDM + foto
  await go(B, 'sd-sdm'); await B.fill('#nsdN', 'Ani ATLM'); await B.fill('#nsdJ', 'Penanggung jawab lab'); await B.click('[data-act=sdmAdd]'); await B.waitForTimeout(500);
  const perId = await B.evaluate(() => location.hash.slice(8)); ok(/Ani ATLM/.test(await txt(B)) && /belum berfoto/.test(await txt(B)), 'Personel ditambahkan oleh Binaan (belum berfoto)');
  ok(!(await B.evaluate(() => Core.S.setup.sdm)), 'Langkah SDM belum selesai tanpa foto');
  await B.fill('[data-k=strNo]', 'STR-123'); await B.press('[data-k=strNo]', 'Tab'); await B.waitForTimeout(300);
  await B.setInputFiles('#sdmFoto', '/tmp/sp_foto.png'); await B.waitForTimeout(900);
  const fdim = await B.evaluate(() => new Promise(r => { const i = document.querySelector('img[src^="foto.php"]'); if (!i) return r(null); const f = () => r([i.naturalWidth, i.naturalHeight]); i.complete ? f() : i.onload = f; }));
  ok(fdim && fdim[0] === 600 && fdim[1] === 800, 'Foto 1200×900 disesuaikan server ke 600×800 (tanpa potong)');
  ok(await B.evaluate(() => Core.S.setup.sdm && Core.byId(Core.S.personel, location.hash.slice(8)).strNo === 'STR-123'), 'Data personel tersimpan; langkah SDM selesai');
  await B.fill('#trN', 'Pelatihan PMI'); await B.fill('#trB', 'Labkesmas'); await B.fill('#trT', '2025'); await B.click('[data-act=trainAdd]'); await B.waitForTimeout(400);
  ok(/Pelatihan PMI/.test(await txt(B)), 'Riwayat pelatihan ditambahkan');
  // Peralatan
  await go(B, 'sd-alat');
  for (const [nm, cat] of [['Fotometer A', 'Kimia'], ['Hematology analyzer B', 'Hematologi']]) { await B.fill('#naN', nm); await B.selectOption('#naC', cat); await B.click('[data-act=alatAdd]'); await B.waitForTimeout(400); }
  ok((await B.evaluate(() => Core.S.alats.length)) === 2 && await B.evaluate(() => Core.S.setup.alat), 'Binaan mendaftarkan alat sendiri; langkah Peralatan selesai');
  const alK = await B.evaluate(() => Core.S.alats.find(a => a.cat === 'Kimia').id);
  await B.fill(`[data-alat="${alK}"][data-k=noSeri]`, 'SN-77'); await B.press(`[data-alat="${alK}"][data-k=noSeri]`, 'Tab'); await B.waitForTimeout(300);
  ok((await B.evaluate(id => Core.byId(Core.S.alats, id).noSeri, alK)) === 'SN-77', 'Spesifikasi alat tersimpan');
  ok(await B.evaluate(() => !Core.S.setup.done), 'Belum akses penuh sebelum Manajemen Risiko diisi');
  // Bahan Habis Pakai
  await go(B, 'sd-bhp');
  const addB = async (j, n, c, lv, lot) => { await B.selectOption('#nbJ', j); await B.fill('#nbN', n); await B.selectOption('#nbC', c); await B.fill('#nbL', lv); await B.fill('#nbLot', lot); await B.fill('#nbE', '2027-06-30'); await B.fill('#nbS', '2–8 °C'); await B.click('[data-act=bhpAdd]'); await B.waitForTimeout(400); };
  await addB('kontrol', 'Kontrol Kimia N', 'Kimia', 'Level 1', 'GLU-1'); await addB('kontrol', 'Kontrol Kimia P', 'Kimia', 'Level 2', 'GLU-2'); await addB('reagen', 'Reagen Glukosa', 'Kimia', '', 'RG-1');
  await addB('kontrol', 'Kontrol Hema Low', 'Hematologi', 'Low', 'HL-1'); await addB('reagen', 'Diluent Hema', 'Hematologi', '', 'HR-1');
  ok((await B.evaluate(() => Core.S.bhp.length)) === 5, 'Binaan mengisi daftar bahan kontrol & reagensia');
  const dupB = await api(B, 'bhp_add', { jenis: 'kontrol', name: 'Kontrol Kimia N', cat: 'Kimia', lot: 'GLU-1' }); ok(!dupB.ok, 'LOT ganda pada produk yang sama ditolak');
  // LOT baru menyalin spesifikasi
  const rg1 = await B.evaluate(() => Core.S.bhp.find(b => b.lot === 'RG-1').id);
  await B.click(`[data-act=bhpNewLot][data-id="${rg1}"]`); await B.fill('#mbL', 'RG-NEW'); await B.fill('#mbE', '2027-12-31'); await B.click('#mbOK'); await B.waitForTimeout(500);
  const rgn = await B.evaluate(() => Core.S.bhp.find(b => b.lot === 'RG-NEW'));
  ok(rgn && rgn.name === 'Reagen Glukosa' && rgn.suhu === '2–8 °C' && rgn.cat === 'Kimia' && rgn.prevId === rg1, 'Tombol LOT baru menyalin spesifikasi dari LOT sebelumnya');
  // Manajemen Risiko → akses penuh
  for (const a of ['bahan', 'lingkungan', 'personel']) { await go(B, 'risiko-' + a); await B.click('[data-act=riskSave]'); await B.waitForTimeout(400); }
  const alH = await B.evaluate(() => Core.S.alats.find(a => a.cat === 'Hematologi').id);
  await go(B, 'risiko-alat-' + alK); await B.click('[data-act=riskSave]'); await B.waitForTimeout(400);
  ok(await B.evaluate(() => !Core.S.setup.done && Core.S.setup.alatRiskMissing.length === 1), 'Setiap alat dipakai wajib dinilai sebelum akses penuh');
  await go(B, 'risiko-alat-' + alH); await B.click('[data-act=riskSave]'); await B.waitForTimeout(400);
  ok(await B.evaluate(() => Core.S.setup.done), 'Akses penuh setelah SDM, Alat, dan Manajemen Risiko lengkap');
  await go(B, 'beranda'); ok(/Beranda · Puskesmas Binaan 01/.test(await txt(B)) && !(await B.locator('.nav a.lock').count()), 'Beranda & semua menu terbuka');
  // alat baru tanpa penilaian risiko: akun tidak dikunci, alat belum bisa untuk QC
  const alX = (await api(B, 'alat_add', { name: 'Fotometer Cadangan', cat: 'Kimia' })).r.id;
  ok(await B.evaluate(() => Core.S.setup.done), 'Alat baru tanpa penilaian tidak mengunci akun kembali');
  const nx = await api(B, 'sheet_create', { paramId: 1, alatId: alX, lv: 'all' }); ok(!nx.ok && /belum dinilai/.test(nx.e), 'Alat belum dinilai risikonya tidak bisa dipakai untuk lembar QC');
  await go(B, 'qc'); ok(/belum dinilai risikonya/.test(await txt(B)) && !(await B.locator(`#nsAlat option[value="${alX}"]`).count()), 'Halaman QC memberi peringatan alat belum dinilai');
  await api(B, 'alat_active', { id: alX, v: false });
  await go(B, 'qc');
  await B.selectOption('#nsParam', '1'); await B.selectOption('#nsAlat', String(alK)); await B.click('[data-act=newSheet]'); await B.waitForTimeout(500);
  const sid = await B.evaluate(() => location.hash.slice(8));
  ok(/Kontrol presisi/.test(await txt(B)) && sid, 'Lembar Glukosa dibuat');
  const idN = await B.evaluate(() => Core.S.bhp.find(b => b.lot === 'GLU-1').id), idP = await B.evaluate(() => Core.S.bhp.find(b => b.lot === 'GLU-2').id);
  ok(!(await B.locator(`[data-lvbhp="0"] option[value="${await B.evaluate(() => Core.S.bhp.find(b => b.lot === 'HL-1').id)}"]`).count()), 'Pilihan bahan kontrol hanya kategori yang sesuai');
  await B.selectOption('[data-lvbhp="0"]', String(idN)); await B.waitForTimeout(400); await B.selectOption('[data-lvbhp="1"]', String(idP)); await B.waitForTimeout(400);
  await B.selectOption('[data-reagenpick]', { label: 'Reagen Glukosa · LOT RG-1 · ED 2027-06-30' }); await B.waitForTimeout(400);
  const lv0 = await B.evaluate(id => Core.byId(Core.S.sheets, id), sid);
  ok(lv0.levels[0].lot === 'GLU-1' && lv0.levels[0].edPabrik === '2027-06-30' && lv0.levels[0].control === 'Kontrol Kimia N' && lv0.reagenLot === 'RG-1', 'Nama kontrol, LOT, ED pabrik, reagen terisi dari daftar Bahan Habis Pakai');
  for (const [i, k, v] of [[0, 'mean', '95'], [0, 'sd', '3'], [1, 'mean', '250'], [1, 'sd', '7'], [0, 'edBuka', '2026-10-30']]) { await B.fill(`[data-lv="${i}"][data-k=${k}]`, v); await B.press(`[data-lv="${i}"][data-k=${k}]`, 'Tab'); await B.waitForTimeout(250); }
  const oldK = await api(B, 'sheet_level', { sheetId: +sid, i: 0, k: 'lot', v: 'X' }); ok(!oldK.ok, 'LOT kontrol tidak bisa diketik bebas di lembar');
  // 20 run normal via API (server)
  const base = new Date('2026-09-01');
  for (let r = 0; r < 20; r++) { const d = new Date(base.getTime() + r * 86400000).toISOString().slice(0, 10); const z1 = [0.3, -0.5, 0.8, -0.2, 0.1][r % 5], z2 = [-0.4, 0.6, -0.1, 0.2, -0.7][r % 5]; const res = await api(B, 'run_add', { sheetId: +sid, date: d, values: [95 + 3 * z1, 250 + 7 * z2] }); if (!res.ok) console.log(res.e); }
  await B.evaluate(id => Core.invalidate(id), sid); await go(B, 'qc'); await go(B, 'lembar-' + sid); await B.waitForTimeout(500);
  ok(await B.locator('svg.lj').count() === 2, 'Grafik Levey-Jennings tampil untuk 2 level');
  const idN2 = (await api(B, 'bhp_add', { jenis: 'kontrol', name: 'Kontrol Kimia N', cat: 'Kimia', lot: 'GLU-1B' })).r.id;
  const lc = await api(B, 'level_bhp', { sheetId: +sid, i: 0, bhpId: idN2 }); ok(!lc.ok && /lembar baru/.test(lc.e), 'Setelah ada hasil, LOT kontrol berbeda ditolak (wajib lembar baru)');
  const rp = await api(B, 'reagen_pick', { sheetId: +sid, bhpId: rgn.id }); ok(!rp.ok, 'Setelah ada hasil, ganti reagen wajib lewat "Catat ganti LOT reagen"');
  const dl1 = await api(B, 'bhp_del', { id: idN }); ok(!dl1.ok && /tidak dapat dihapus/.test(dl1.e), 'Bahan yang dipakai di QC tidak dapat dihapus');
  const ul1 = await api(B, 'bhp_update', { id: idN, k: 'lot', v: 'ZZZ' }); ok(!ul1.ok, 'LOT bahan yang dipakai di QC terkunci');
  const ul2 = await api(B, 'bhp_update', { id: idN, k: 'suhu', v: '2–8 °C, gelap' }); ok(ul2.ok, 'Suhu simpan bahan yang dipakai tetap dapat diubah');
  const dl2 = await api(B, 'bhp_del', { id: idN2 }); ok(dl2.ok && !(await B.evaluate(id => Core.byId(Core.S.bhp, id), idN2)), 'Bahan yang belum dipakai dapat dihapus');
  // run tertolak via UI: level 1 > 3SD
  await B.fill('#runDate', '2026-10-01'); await B.fill('#runV0', '106'); await B.fill('#runV1', '251'); await B.click('[data-act=addRun]'); await B.waitForTimeout(700);
  let st = await B.evaluate(() => Core.S.cases.slice(-1)[0]);
  ok(st && st.type === 'Tertolak' && st.indication === 'Acak' && st.state === 'Terbuka', 'Run >3SD → kasus Tertolak, indikasi Acak (server)');
  ok(/Notifikasi pelanggaran aturan Westgard/.test(await txt(B)), 'Notifikasi tampil di lembar kerja');
  const cid = st.id;
  // server vs klien: status run identik
  const same = await B.evaluate(async id => { const sh = Core.cachedSheet(id); const ev = Core.evalSheet(sh); return ev.length; }, sid);
  ok(same === 21, 'Klien menerima 21 run dari server');
  // alur kasus mandiri
  await go(B, 'kasus-' + cid); await B.waitForTimeout(500);
  ok(/Saran prioritas investigasi/.test(await txt(B)), 'Halaman kasus: prioritas investigasi');
  await B.click('[data-act=caseMode][data-v=mandiri]'); await B.waitForTimeout(500);
  await B.check('[data-find="personel:0"]'); await B.check('[data-find="bahan:3"]'); await B.click('[data-act=saveFind]'); await B.waitForTimeout(500);
  ok(/Latih ulang teknik pemipetan/.test(await txt(B)), 'Saran tindakan koreksi muncul dari temuan');
  await B.check('[data-corr="0"]'); await B.click('[data-act=saveCorr][data-finish="1"]'); await B.waitForTimeout(500);
  ok(/Belum ada run diterima/.test(await txt(B)), 'Verifikasi menunggu QC ulang');
  await api(B, 'run_add', { sheetId: +sid, date: '2026-10-01', values: [95.5, 249] });
  await B.evaluate(id => Core.invalidate(id), sid); await go(B, 'beranda'); await go(B, 'kasus-' + cid); await B.waitForTimeout(600);
  await B.click('[data-act=closeCase]'); await B.waitForTimeout(500);
  ok((await B.evaluate(id => Core.byId(Core.S.cases, id).state, cid)) === 'Ditutup', 'Kasus ditutup setelah verifikasi');
  // kasus kedua: 2-2s antar level → konsultasi
  await api(B, 'run_add', { sheetId: +sid, date: '2026-10-02', values: [101.6, 265.5] });
  const c2 = await B.evaluate(() => Core.S.cases.slice(-1)[0]);
  ok(c2.indication === 'Sistematik' && c2.violations.includes('2_2s'), 'Kedua level >+2SD → 2-2s, Sistematik');
  await go(B, 'kasus-' + c2.id); await B.waitForTimeout(500); await B.click('[data-act=caseMode][data-v=konsultasi]'); await B.waitForTimeout(400);
  // risiko
  await go(B, 'risiko-lingkungan'); await B.selectOption('[data-risk="0"][data-k=L]', '5'); await B.selectOption('[data-risk="0"][data-k=S]', '5'); await B.click('[data-act=riskSave]'); await B.waitForTimeout(500);
  ok((await B.evaluate(() => Core.riskMax(Core.latestRisk(Core.me().pkmId, 'lingkungan')))) === 25, 'Penilaian risiko tersimpan di server');
  const alatK = alK;
  await go(B, 'risiko-alat-' + alatK); ok(/Fotometer A/.test(await txt(B)), 'Lembar risiko per alat');
  // kop
  await go(B, 'kop'); await B.setInputFiles('#kopFile', '/home/claude/sipandu-proto/test/kop_800x300.png'); await B.waitForTimeout(800);
  const kopDim = await B.evaluate(() => new Promise(r => { const i = document.querySelector('.card img'); if (!i) return r(null); const f = () => r([i.naturalWidth, i.naturalHeight]); i.complete ? f() : i.onload = f; }));
  ok(kopDim && kopDim[0] === 2008 && kopDim[1] === 413, 'Kop diunggah & disesuaikan server ke 2008×413');
  // komentar binaan
  await go(B, 'lembar-' + sid); await B.waitForTimeout(400); await B.fill('#cmt', 'Mohon arahan.'); await B.click('[data-act=sheetCmt]'); await B.waitForTimeout(500);
  // reagen lot + level lock + ganti LOT kontrol
  ok(await B.locator('[data-act=lvActive][disabled]').count() === 2, 'Level aktif terkunci setelah ada hasil');
  await B.click('[data-act=reagenLot]'); await B.fill('#mrD', '2026-09-10'); await B.selectOption('#mrL', String(rgn.id)); await B.click('#mrOK'); await B.waitForTimeout(600);
  ok(/LOT reagen RG-NEW/.test(await B.content()) && (await B.evaluate(id => Core.byId(Core.S.sheets, id).reagenLot, sid)) === 'RG-NEW', 'Ganti LOT reagen dipilih dari daftar & ditandai di grafik');
  // lembar kedua: Hemoglobin 1 level → kondisi tidak ideal
  await go(B, 'qc'); await B.selectOption('#nsParam', { label: 'Hemoglobin (Hematologi)' }); await B.selectOption('#nsAlat', { index: 1 }); await B.selectOption('#nsLv', '1'); await B.click('[data-act=newSheet]'); await B.waitForTimeout(600);
  ok(/Level kontrol aktif 1 dari 3/.test(await txt(B)) && /tidak dapat dinilai/.test(await txt(B)), 'Lembar 1 level: kondisi tidak ideal + aturan tidak dapat dinilai');
  // ganti LOT kontrol pada lembar glukosa
  await go(B, 'lembar-' + sid); await B.waitForTimeout(400); await B.click('[data-act=newLot]'); await B.click('#mlOK'); await B.waitForTimeout(800);
  const newSid = await B.evaluate(() => location.hash.slice(8));
  ok(newSid !== sid && (await B.evaluate(id => Core.byId(Core.S.sheets, id).status, sid)) === 'ditutup', 'Ganti LOT kontrol: lembar lama ditutup, lembar baru dibuka');
  ok(await B.evaluate(id => Core.byId(Core.S.sheets, id).levels.every(l => !l.bhpId && !l.lot && l.mean == null), newSid), 'Lembar baru: bahan kontrol dipilih ulang, mean/SD kosong');

  // ---- Pembina
  const P = await newPage();
  await firstLogin(P, 'pembina1', acc.pembina1, 'Pembina1Baru');
  ok(/Beranda Pembina/.test(await txt(P)), 'Pembina masuk');
  const nn = await P.evaluate(() => Core.S.notifs.map(n => n.text));
  ok(nn.some(x => /^Tertolak – Glukosa/.test(x)) && nn.some(x => /Kasus #\d+ \(Puskesmas Binaan 01\): Binaan memilih investigasi mandiri/.test(x)), 'Pembina menerima notifikasi pelanggaran & tiap langkah kasus');
  ok(nn.some(x => /menanggapi di lembar/.test(x)) && nn.some(x => /mengganti LOT kontrol/.test(x)), 'Pembina menerima notifikasi komentar & ganti LOT');
  await go(P, 'pantau'); ok(/Puskesmas Binaan 01/.test(await txt(P)) && /Puskesmas Uji 28/.test(await txt(P)), 'Pantauan Binaan memuat semua Puskesmas termasuk yang baru');
  await go(P, 'lembar-' + sid); await P.waitForTimeout(600);
  ok(/Mohon arahan/.test(await txt(P)) && !(await P.locator('#runV0').count()), 'Pembina melihat lembar binaan (baca saja) + komentar binaan');
  await P.fill('#cmt', 'Periksa kalibrasi.'); await P.click('[data-act=sheetCmt]'); await P.waitForTimeout(500);
  await go(P, 'kasus'); await P.click('[data-act=caseFilter][data-v=kons]'); await P.waitForTimeout(200); ok(new RegExp('#' + c2.id).test(await txt(P)), 'Daftar konsultasi memuat kasus binaan');
  await go(P, 'kasus-' + c2.id); await P.waitForTimeout(700); await P.fill('#caseCmt', 'Cek suhu ruang.'); await P.click('[data-act=caseCmt]'); await P.waitForTimeout(600);
  const pr = await P.evaluate(() => [...document.querySelectorAll('.prio li b')].filter((_, i) => i % 1 === 0).map(b => b.textContent));
  ok(pr[0] === 'Lingkungan' && pr[1] === 'Peralatan/Instrumen', 'Prioritas memakai skor risiko binaan (lingkungan 20+25 > alat 40+0): ' + pr.filter(x => /[a-z]/.test(x)).join(' > '));
  const pRes = await api(P, 'run_add', { sheetId: +newSid, date: '2026-10-05', values: [1, 2] });
  ok(!pRes.ok, 'Pembina tidak bisa mengubah data QC binaan (' + pRes.e + ')');
  const kopP = await P.evaluate(async () => (await fetch('kop.php?k=pkm:1')).status); ok(kopP === 200, 'Pembina dapat melihat kop binaan');
  ok(!(await P.locator('.nav a.lock').count()), 'Pembina tidak dikunci alur data awal');
  await go(P, 'sd-sdm'); await P.selectOption('[data-sdowner]', '1'); await P.waitForTimeout(300);
  ok(/Ani ATLM/.test(await txt(P)) && /Hanya melihat/.test(await txt(P)) && !(await P.locator('#nsdN').count()), 'Pembina melihat Data Personel binaan (baca saja)');
  const fid = await P.evaluate(() => Core.S.personel.find(x => x.name === 'Ani ATLM').fotoId);
  ok((await P.evaluate(async f => (await fetch('foto.php?f=' + f)).status, fid)) === 200, 'Pembina dapat melihat foto personel binaan');
  const pu = await api(P, 'sdm_update', { id: +perId, k: 'name', v: 'X' }); ok(!pu.ok, 'Pembina tidak bisa mengubah data personel binaan');
  await P.selectOption('[data-sdowner]', '0'); await P.waitForTimeout(300); await P.fill('#nsdN', 'Budi Pembina'); await P.click('[data-act=sdmAdd]'); await P.waitForTimeout(500);
  ok(/Budi Pembina/.test(await txt(P)) && (await P.evaluate(() => Core.S.personel.find(x => x.name === 'Budi Pembina').pkmId)) === 0, 'Pembina mengisi Sumber Daya Labkesmas sendiri');
  await go(P, 'sd-bhp'); await P.selectOption('[data-sdowner]', '1'); await P.waitForTimeout(300); ok(/GLU-1/.test(await P.content()) && !(await P.locator('#nbN').count()), 'Pembina melihat Bahan Habis Pakai binaan (baca saja)');
  // Admin melihat Sumber Daya
  await A.reload(); await A.waitForTimeout(300); await go(A, 'sd-sdm'); await A.selectOption('[data-sdowner]', '1'); await A.waitForTimeout(300); ok(/Ani ATLM/.test(await txt(A)) && !(await A.locator('#nsdN').count()), 'Admin melihat Sumber Daya (baca saja)');
  const as = await api(A, 'sdm_add', { name: 'X' }); ok(!as.ok, 'Admin tidak bisa menambah data Sumber Daya');

  // ---- Binaan lain: hak akses silang
  const B2 = await newPage();
  await firstLogin(B2, 'pkm02', pw2, 'Puskesmas02Baru');
  const su2 = await apiSetup(B2, 'P02'); ok(su2 && su2.done, 'Binaan lain menyelesaikan data awal (foto, alat, risiko)');
  const x1 = await api(B2, 'sheet_get', { id: +sid }); ok(!x1.ok, 'Binaan lain tidak bisa membuka lembar Puskesmas 01 (' + x1.e + ')');
  const x2 = await api(B2, 'case_mode', { id: c2.id, v: 'mandiri' }); ok(!x2.ok, 'Binaan lain tidak bisa mengubah kasus Puskesmas 01');
  const x3 = await api(B2, 'user_add', { role: 'binaan', name: 'x', username: 'hack1', pkmId: 1 }); ok(!x3.ok, 'Binaan tidak bisa memakai aksi Administrator');
  const x4 = await B2.evaluate(async () => (await fetch('kop.php?k=pkm:1')).status); ok(x4 === 404, 'Binaan lain tidak bisa melihat kop Puskesmas 01');
  const x5 = await B2.evaluate(async f => (await fetch('foto.php?f=' + f)).status, fid); ok(x5 === 404, 'Binaan lain tidak bisa melihat foto personel Puskesmas 01');
  const x6 = await api(B2, 'sdm_update', { id: +perId, k: 'name', v: 'X' }); ok(!x6.ok, 'Binaan lain tidak bisa mengubah personel Puskesmas 01');
  const x7 = await api(B2, 'bhp_newlot', { id: idP, lot: 'HACK', ed: '' }); ok(!x7.ok, 'Binaan lain tidak bisa memakai bahan Puskesmas 01');
  const st2 = await B2.evaluate(() => { const me = Core.me().pkmId, own = l => (l || []).every(x => x.pkmId === me); return { sheets: Core.S.sheets.length, cases: Core.S.cases.length, own: own(Core.S.risks) && own(Core.S.personel) && own(Core.S.bhp) && own(Core.S.alats), pkms: Core.S.pkms.length }; });
  ok(st2.sheets === 0 && st2.cases === 0 && st2.own && st2.pkms === 1, 'Status klien Binaan hanya berisi data Puskesmasnya sendiri');
  const csrf = await B2.evaluate(async () => (await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ a: 'state' }) })).status);
  ok(csrf === 403, 'Permintaan tanpa token CSRF ditolak (403)');
  // binaan pkm01 melihat komentar & saran pembina + notifikasi
  await go(B, 'beranda'); await B.reload(); await B.waitForTimeout(500);
  const nb = await B.evaluate(() => Core.S.notifs.map(n => n.text));
  ok(nb.some(x => /Komentar Pembina pada lembar/.test(x)) && nb.some(x => /Saran Pembina pada kasus/.test(x)), 'Binaan menerima notifikasi komentar & saran Pembina');
  // pkm28 baru
  const B28 = await newPage(); await firstLogin(B28, 'pkm28', pw28, 'Puskesmas28Baru'); ok(/Puskesmas Uji 28/.test(await txt(B28)), 'Akun Binaan baru (Puskesmas tambahan) dapat dipakai');
  // menu tahap berikut
  await go(B, 'dokkm'); ok(/tahap berikutnya \(Tahap 2\)/.test(await txt(B)), 'Menu Tahap 2 menampilkan keterangan tahap berikutnya');
  // logout
  await B.click('a[href^="logout.php"]'); await B.waitForTimeout(400); ok(/Nama pengguna/.test(await txt(B)), 'Keluar mengembalikan ke halaman masuk');
  // reset semua sandi sementara + CSV
  await A.reload(); await A.waitForTimeout(300); await go(A, 'pengguna');
  const before = await A.evaluate(() => Core.S.users.filter(x => x.role !== 'admin' && x.mustChange && x.active).map(x => x.username));
  ok(before.includes('pkm03') && !before.includes('pkm01') && !before.includes('pkm02'), 'Sasaran reset massal: hanya akun yang belum ganti sandi');
  await A.click('[data-act=userResetAll]'); ok((await txt(A)).includes(before.length + ' akun aktif'), 'Konfirmasi reset massal menyebut jumlah akun');
  const [dl] = await Promise.all([A.waitForEvent('download', { timeout: 15000 }).catch(() => null), (async () => { await A.click('#raOK'); await A.waitForSelector('#raCsv'); await A.click('#raCsv'); })()]);
  const csvTxt = dl ? require('fs').readFileSync(await dl.path(), 'utf8') : '';
  const rows = csvTxt.trim().split(/\r\n/).slice(1).map(l => l.split(',').map(c => c.replace(/^"|"$/g, '')));
  ok(dl && dl.suggestedFilename() === 'akun-sipandu.csv' && rows.length === before.length && rows.every(r => r.length === 4 && r[3].length >= 8), 'CSV terunduh berisi ' + before.length + ' akun dengan sandi sementara baru');
  const r3 = rows.find(r => r[2] === 'pkm03');
  ok(r3 && (await txt(A)).includes(r3[3]), 'Daftar di layar sama dengan isi CSV');
  const X = await newPage(); await login(X, 'pkm03', r3[3]); ok(/ganti kata sandi sementara/.test(await txt(X)), 'Sandi sementara baru dari CSV dapat dipakai masuk');
  const Y = await newPage(); await login(Y, 'pkm01', 'Puskesmas01Baru'); ok(!/salah/.test(await txt(Y)), 'Akun yang sudah ganti sandi tidak ikut direset');
  const nbr = await api(Y, 'user_reset_all', {}); ok(!nbr.ok, 'Selain Admin tidak bisa reset massal');
  // selftest
  const S = await newPage(); await S.goto(BASE + '/selftest.php?token=salah'); ok((await txt(S)).trim() === '403', 'Uji mandiri tanpa token ditolak');
  await S.goto(BASE + '/selftest.php?token=SELF-UJI-456'); const stt = await txt(S);
  ok(/mesin Westgard: setara Excel \+ skenario paper\s+OK/.test(stt) && /tabel lengkap\s+OK/.test(stt), 'Uji mandiri: mesin & tabel OK');
  await A.screenshot({ path: '/tmp/sp/admin.png', fullPage: true });
  await go(P, 'lembar-' + sid); await P.waitForTimeout(500); await P.screenshot({ path: '/tmp/sp/pembina_lembar.png', fullPage: true });
  console.log(errors.length ? 'GALAT JS:\n' + errors.join('\n') : 'Tanpa galat JavaScript');
  console.log(`pemeriksaan: ${n}, gagal: ${fail}`);
  await browser.close();
  process.exit(fail || errors.length ? 1 : 0);
})();
