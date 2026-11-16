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
  // alat
  await go(A, 'ref-alat');
  for (const [nm, cat] of [['Fotometer A', 'Kimia'], ['Hematology analyzer B', 'Hematologi']]) { await A.selectOption('#naP', { label: 'Puskesmas Binaan 01' }); await A.fill('#naN', nm); await A.selectOption('#naC', cat); await A.click('[data-act=alatAdd]'); await A.waitForTimeout(300); }
  await A.selectOption('#naP', { label: 'Puskesmas Binaan 02' }); await A.fill('#naN', 'Fotometer C'); await A.selectOption('#naC', 'Kimia'); await A.click('[data-act=alatAdd]'); await A.waitForTimeout(300);
  ok((await A.evaluate(() => Core.S.alats.length)) === 3, 'Admin mendaftarkan alat');
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
  ok(/Beranda · Puskesmas Binaan 01/.test(await txt(B)), 'Binaan masuk setelah ganti sandi');
  await go(B, 'qc');
  await B.selectOption('#nsParam', '1'); await B.click('[data-act=newSheet]'); await B.waitForTimeout(500);
  const sid = await B.evaluate(() => location.hash.slice(8));
  ok(/Kontrol presisi/.test(await txt(B)) && sid, 'Lembar Glukosa dibuat');
  for (const [i, k, v] of [[0, 'mean', '95'], [0, 'sd', '3'], [1, 'mean', '250'], [1, 'sd', '7'], [0, 'lot', 'GLU-1'], [1, 'lot', 'GLU-2']]) { await B.fill(`[data-lv="${i}"][data-k=${k}]`, v); await B.press(`[data-lv="${i}"][data-k=${k}]`, 'Tab'); await B.waitForTimeout(250); }
  // 20 run normal via API (server)
  const base = new Date('2026-09-01');
  for (let r = 0; r < 20; r++) { const d = new Date(base.getTime() + r * 86400000).toISOString().slice(0, 10); const z1 = [0.3, -0.5, 0.8, -0.2, 0.1][r % 5], z2 = [-0.4, 0.6, -0.1, 0.2, -0.7][r % 5]; const res = await api(B, 'run_add', { sheetId: +sid, date: d, values: [95 + 3 * z1, 250 + 7 * z2] }); if (!res.ok) console.log(res.e); }
  await B.evaluate(id => Core.invalidate(id), sid); await go(B, 'qc'); await go(B, 'lembar-' + sid); await B.waitForTimeout(500);
  ok(await B.locator('svg.lj').count() === 2, 'Grafik Levey-Jennings tampil untuk 2 level');
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
  const alatK = await B.evaluate(() => Core.S.alats.find(a => a.cat === 'Kimia').id);
  await go(B, 'risiko-alat-' + alatK); ok(/Fotometer A/.test(await txt(B)), 'Lembar risiko per alat');
  // kop
  await go(B, 'kop'); await B.setInputFiles('#kopFile', '/home/claude/sipandu-proto/test/kop_800x300.png'); await B.waitForTimeout(800);
  const kopDim = await B.evaluate(() => new Promise(r => { const i = document.querySelector('.card img'); if (!i) return r(null); const f = () => r([i.naturalWidth, i.naturalHeight]); i.complete ? f() : i.onload = f; }));
  ok(kopDim && kopDim[0] === 2008 && kopDim[1] === 413, 'Kop diunggah & disesuaikan server ke 2008×413');
  // komentar binaan
  await go(B, 'lembar-' + sid); await B.waitForTimeout(400); await B.fill('#cmt', 'Mohon arahan.'); await B.click('[data-act=sheetCmt]'); await B.waitForTimeout(500);
  // reagen lot + level lock + ganti LOT kontrol
  ok(await B.locator('[data-act=lvActive][disabled]').count() === 2, 'Level aktif terkunci setelah ada hasil');
  await B.click('[data-act=reagenLot]'); await B.fill('#mrD', '2026-09-10'); await B.fill('#mrL', 'RG-NEW'); await B.click('#mrOK'); await B.waitForTimeout(600);
  ok(/LOT reagen RG-NEW/.test(await B.content()), 'Ganti LOT reagen ditandai di grafik');
  // lembar kedua: Hemoglobin 1 level → kondisi tidak ideal
  await go(B, 'qc'); await B.selectOption('#nsParam', { label: 'Hemoglobin (Hematologi)' }); await B.selectOption('#nsAlat', { index: 1 }); await B.selectOption('#nsLv', '1'); await B.click('[data-act=newSheet]'); await B.waitForTimeout(600);
  ok(/Level kontrol aktif 1 dari 3/.test(await txt(B)) && /tidak dapat dinilai/.test(await txt(B)), 'Lembar 1 level: kondisi tidak ideal + aturan tidak dapat dinilai');
  // ganti LOT kontrol pada lembar glukosa
  await go(B, 'lembar-' + sid); await B.waitForTimeout(400); await B.click('[data-act=newLot]'); await B.click('#mlOK'); await B.waitForTimeout(800);
  const newSid = await B.evaluate(() => location.hash.slice(8));
  ok(newSid !== sid && (await B.evaluate(id => Core.byId(Core.S.sheets, id).status, sid)) === 'ditutup', 'Ganti LOT kontrol: lembar lama ditutup, lembar baru dibuka');

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

  // ---- Binaan lain: hak akses silang
  const B2 = await newPage();
  await firstLogin(B2, 'pkm02', pw2, 'Puskesmas02Baru');
  const x1 = await api(B2, 'sheet_get', { id: +sid }); ok(!x1.ok, 'Binaan lain tidak bisa membuka lembar Puskesmas 01 (' + x1.e + ')');
  const x2 = await api(B2, 'case_mode', { id: c2.id, v: 'mandiri' }); ok(!x2.ok, 'Binaan lain tidak bisa mengubah kasus Puskesmas 01');
  const x3 = await api(B2, 'user_add', { role: 'binaan', name: 'x', username: 'hack1', pkmId: 1 }); ok(!x3.ok, 'Binaan tidak bisa memakai aksi Administrator');
  const x4 = await B2.evaluate(async () => (await fetch('kop.php?k=pkm:1')).status); ok(x4 === 404, 'Binaan lain tidak bisa melihat kop Puskesmas 01');
  const st2 = await B2.evaluate(() => ({ sheets: Core.S.sheets.length, cases: Core.S.cases.length, risks: Core.S.risks.length, pkms: Core.S.pkms.length }));
  ok(st2.sheets === 0 && st2.cases === 0 && st2.risks === 0 && st2.pkms === 1, 'Status klien Binaan hanya berisi data Puskesmasnya sendiri');
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
