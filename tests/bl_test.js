// Uji integrasi subdomain pembelajaran (Si PANDU – Peningkatan Kapasitas Kompetensi SDM).
// Dijalankan SETELAH tests/e2e_server.js. Si PANDU di BASE (8099), subdomain di BL (8098, SIPANDU_APP=bl), basis data sama.
const { chromium } = require('/opt/npm-tools/node_modules/playwright');
const fs = require('fs'), os = require('os'), path = require('path');
const BASE = process.env.BASE || 'http://127.0.0.1:8099';
const BL = process.env.BL || 'http://127.0.0.1:8098';
const TOKEN = process.env.TOKEN || 'TOKEN-UJI-123';
const DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'blt-'));
function pdf(text, pad) {
  const objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>'];
  const st = `BT /F1 18 Tf 60 760 Td (${text}) Tj ET`; objs.push(`<< /Length ${st.length} >>\nstream\n${st}\nendstream`); objs.push('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
  let out = '%PDF-1.4\n'; const offs = [];
  objs.forEach((o, i) => { offs.push(out.length); out += `${i + 1} 0 obj\n${o}\nendobj\n`; });
  if (pad) out += '%' + 'x'.repeat(pad) + '\n';
  const x = out.length; out += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n` + offs.map(o => String(o).padStart(10, '0') + ' 00000 n \n').join('');
  return out + `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${x}\n%%EOF\n`;
}
const F = { surat: path.join(DIR, 'surat.pdf'), besar: path.join(DIR, 'besar.pdf'), materi: path.join(DIR, 'materi.pdf'), bukan: path.join(DIR, 'bukan.pdf'), foto: '/tmp/sp_foto.png' };
fs.writeFileSync(F.surat, pdf('Surat Tugas')); fs.writeFileSync(F.besar, pdf('Besar', 1100000)); fs.writeFileSync(F.materi, pdf('Materi QC')); fs.writeFileSync(F.bukan, 'bukan pdf');

(async () => {
  const browser = await chromium.launch({ executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox'] });
  let fail = 0, n = 0; const ok = (c, m) => { n++; if (!c) { fail++; console.log('GAGAL', m); } else console.log('ok  ', m); };
  const errors = [];
  const newPage = async () => { const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 }, acceptDownloads: true }); const p = await ctx.newPage(); p.on('pageerror', e => errors.push(e.message)); p.on('console', m => { if (m.type() === 'error' && !/fonts\.g|ERR_|Failed to load resource/.test(m.text())) errors.push(m.text()); }); return p; };
  const txt = p => p.evaluate(() => document.body.innerText);
  const go = async (p, h) => { await p.evaluate(h => location.hash = h, h); await p.waitForTimeout(350); };
  const api = (p, a, d) => p.evaluate(([a, d]) => Core.api(a, d).then(r => ({ ok: true, r }), e => ({ ok: false, e: e.message })), [a, d]);
  const login = async (p, u, pw, base) => { await p.goto((base || BL) + '/'); await p.fill('[name=username]', u); await p.fill('[name=password]', pw); await p.click('button[type=submit]'); await p.waitForLoadState(); await p.waitForTimeout(300); };
  const firstLogin = async (p, u, pw, npw) => { await login(p, u, pw); ok(/ganti kata sandi sementara/.test(await txt(p)), u + ': wajib ganti sandi saat masuk pertama'); await p.fill('[name=old]', pw); await p.fill('[name=new]', npw); await p.fill('[name=new2]', npw); await p.click('button[type=submit]'); await p.waitForLoadState(); await p.waitForTimeout(400); };
  const status = (p, url) => p.evaluate(u => fetch(u).then(r => r.status), url);
  const click = async (p, sel) => { await p.click(sel); await p.waitForTimeout(450); };
  const toasts = p => p.evaluate(() => [...document.querySelectorAll('.toast.bad')].map(x => x.textContent).join(' '));
  const signup = async (p, f) => {
    await p.goto(BL + '/?daftar=1');
    for (const k of ['nama', 'email', 'hp', 'instansi', 'username']) if (f[k] != null) await p.fill(`[name=${k}]`, f[k]);
    await p.fill('[name=p1]', f.pw); await p.fill('[name=p2]', f.pw2 || f.pw);
    if (f.bot) await p.evaluate(() => { document.querySelector('[name=website]').value = 'http://spam'; });
    await p.click('button[type=submit]'); await p.waitForLoadState(); await p.waitForTimeout(400);
  };

  // ---- Si PANDU: menu pembelajaran dipindah ke subdomain
  const SA = await newPage(); await login(SA, 'admin', 'AdminKuat2026', BASE);
  ok(!(await SA.locator('.nav a[href="#bl"]').count()) && !/Peningkatan Kompetensi SDM/.test(await txt(SA)), 'Si PANDU: menu Peningkatan Kompetensi SDM dihapus (Admin)');
  await go(SA, 'pengguna'); ok(!(await SA.locator('#nuRole option[value=fasil]').count()) && (await SA.locator('#nuRole option').count()) === 2, 'Si PANDU: peran Fasilitator tidak ada lagi di Pengguna');
  ok(!(await api(SA, 'bl_get', { id: 1 })).ok && !(await api(SA, 'user_add', { role: 'fasil', name: 'x', username: 'fasilx' })).ok, 'Si PANDU: aksi pembelajaran & pembuatan Fasilitator ditolak server');
  const SB = await newPage(); await login(SB, 'pkm01', 'Puskesmas01Baru', BASE);
  const link = SB.locator('.nav a', { hasText: 'Pembelajaran' });
  ok((await link.count()) === 1 && (await link.getAttribute('href')) === BL + '/' && !(await link.getAttribute('target')), 'Si PANDU Binaan: menu "Pembelajaran" langsung ke subdomain (tab yang sama)');
  ok((await SB.evaluate(() => Core.S.personel.every(p => !('subUser' in p)))) && !(await api(SB, 'bl_reset_sub', { personelId: 1 })).ok, 'Si PANDU: sub-akun personel dihapus');
  const SP = await newPage(); await login(SP, 'pembina1', 'Pembina1Baru', BASE);
  ok(!(await SP.locator('.nav a', { hasText: /Pembelajaran|Kompetensi/ }).count()), 'Si PANDU Pembina: tanpa menu pembelajaran');
  ok((await status(SB, BASE + '/berkas.php?j=surat&id=1')) === 404, 'Si PANDU: berkas pembelajaran tidak tersedia di domain utama');

  // ---- Pemasangan Admin subdomain
  const P0 = await newPage(); await P0.goto(BL + '/');
  ok(/belum dipasang/.test(await txt(P0)), 'Subdomain sebelum dipasang: pesan belum dipasang');
  await P0.goto(BL + '/install.php?token=salah'); ok(/tidak valid/.test(await txt(P0)), 'Pemasangan subdomain tanpa token benar ditolak');
  await P0.goto(BL + '/install.php?token=' + TOKEN); await P0.fill('[name=p1]', 'BelajarAdmin2026'); await P0.fill('[name=p2]', 'BelajarAdmin2026'); await click(P0, 'button[type=submit]');
  ok(/PEMASANGAN SELESAI/.test(await txt(P0)), 'Akun Admin subdomain dibuat saat pemasangan');
  await P0.goto(BL + '/install.php?token=' + TOKEN); ok(/sudah terpasang/.test(await txt(P0)), 'Pemasangan subdomain terkunci setelah selesai');

  // ---- Akun terpisah
  const X = await newPage(); await login(X, 'admin', 'AdminKuat2026'); ok(/salah/.test(await txt(X)), 'Akun Si PANDU tidak berlaku di subdomain');
  await login(X, 'pkm01', 'Puskesmas01Baru'); ok(/salah/.test(await txt(X)), 'Akun Binaan Si PANDU tidak berlaku di subdomain');
  const A = await newPage(); await login(A, 'admin', 'BelajarAdmin2026');
  ok(/Si PANDU – Peningkatan Kapasitas Kompetensi SDM/.test(await txt(A)), 'Judul situs: "Si PANDU – Peningkatan Kapasitas Kompetensi SDM"');
  ok((await A.evaluate(() => [...document.querySelectorAll('.nav a')].map(a => a.getAttribute('href')).join())) === '#bl,#bl-pengguna,#bl-laporan,#bl-jenis,#bl-audit', 'Menu Admin subdomain: Kegiatan, Pengguna, Laporan Kegiatan, Jenis dokumen, Audit trail');
  ok((await status(A, BL + '/kop.php?k=pembina')) === 404 && (await status(A, BL + '/foto.php?f=1')) === 404, 'Admin subdomain tidak dapat membuka berkas Si PANDU');
  const mainSess = await SA.evaluate(() => Core.api('state').then(() => Core.me().username)); ok(mainSess === 'admin', 'Sesi Si PANDU tidak terganggu sesi subdomain');

  // ---- Admin: akun Pembina, Fasilitator, Pengawas
  await go(A, 'bl-pengguna');
  await A.selectOption('#buR', 'fasil'); await A.fill('#buN', 'Dr. Rina Fasilitator'); await A.fill('#buU', 'fasil1'); await A.fill('#buI', 'Labkesda Balikpapan'); await click(A, '[data-act=blUserAdd]');
  const pwF1 = await A.evaluate(() => document.querySelectorAll('.modal code')[1].textContent); await A.click('.modal [data-close]');
  ok(pwF1.length === 10 && (await A.evaluate(() => Core.S.users.some(u => u.name === 'Dr. Rina Fasilitator' && u.role === 'fasil' && u.mustChange))), 'Admin membuat akun Fasilitator; sandi sementara tampil sekali');
  const mk = async (role, name, un) => (await api(A, 'bl_user_add', { role, name, username: un })).r;
  const uF2 = await mk('fasil', 'Fasilitator Lain', 'fasil2'), uP = await mk('pembina', 'Pembina Labkesda', 'pembina1'), uW = await mk('pengawas', 'Pengawas Dinkes', 'pengawas1'), uW2 = await mk('pengawas', 'Pengawas Lain', 'pengawas2');
  ok(uF2 && uP && uW && uW2 && !(await api(A, 'bl_user_add', { role: 'peserta', name: 'x', username: 'px' })).ok && !(await api(A, 'bl_user_add', { role: 'pembina', name: 'x', username: 'pembina1' })).ok, 'Akun Pembina & Pengawas dibuat; peran Peserta dan nama pengguna ganda ditolak');
  const fid = (await A.evaluate(() => Core.S.users.find(u => u.username === 'fasil1').id));

  // ---- Peserta membuat akun sendiri
  const S1 = await newPage();
  await S1.goto(BL + '/'); ok(await S1.locator('a[href="./?daftar=1"]').count() === 1, 'Halaman masuk: tautan Buat akun');
  await signup(S1, { nama: 'Ani Lestari, A.Md.AK', email: 'ani@contoh', hp: '081234567890', instansi: 'Puskesmas Baru Ilir', username: 'ani', pw: 'PesertaAni123' });
  ok(/Format email tidak valid/.test(await txt(S1)) && (await S1.inputValue('[name=nama]')) === 'Ani Lestari, A.Md.AK', 'Email salah ditolak; isian tetap ada');
  await signup(S1, { nama: 'Ani Lestari, A.Md.AK', email: 'ani@contoh.id', hp: '081234567890', instansi: 'Puskesmas Baru Ilir', username: 'ani', pw: 'PesertaAni123', bot: true });
  ok(/tidak dapat diproses/.test(await txt(S1)), 'Kolom jebakan bot terisi → ditolak');
  await signup(S1, { nama: 'Ani Lestari, A.Md.AK', email: 'ani@contoh.id', hp: '081234567890', instansi: 'Puskesmas Baru Ilir', username: 'ani', pw: 'PesertaAni123', pw2: 'Lain12345' });
  ok(/tidak sama/.test(await txt(S1)), 'Sandi ulang berbeda ditolak');
  await signup(S1, { nama: 'Ani Lestari, A.Md.AK', email: 'ani@contoh.id', hp: '081234567890', instansi: 'Puskesmas Baru Ilir', username: 'ani', pw: 'PesertaAni123' });
  ok((await S1.evaluate(() => Core.me().role)) === 'peserta' && (await S1.evaluate(() => [...document.querySelectorAll('.nav a')].map(a => a.getAttribute('href')).join())) === '#bl,#bl-riwayat', 'Akun peserta langsung aktif & masuk; menu Kegiatan, Riwayat saya');
  const S2 = await newPage(); await signup(S2, { nama: 'Cici Rahma, S.Tr.Kes', email: 'cici@contoh.id', hp: '+62 812-0000-1111', instansi: 'RSUD Kanujoso', username: 'cici', pw: 'PesertaCici123' });
  const SBu = await newPage(); await signup(SBu, { nama: 'Budi Santoso', email: 'budi@contoh.id', hp: '081300000000', instansi: 'Klinik Sehat', username: 'budi', pw: 'PesertaBudi123' });
  const SD = await newPage(); await signup(SD, { nama: 'Dedi', email: 'dedi@contoh.id', hp: '081300000001', instansi: 'Puskesmas Damai', username: 'dedi', pw: 'PesertaDedi123' });
  const SE = await newPage(); await signup(SE, { nama: 'Eka', email: 'eka@contoh.id', hp: '081300000002', instansi: 'Puskesmas Damai', username: 'ani', pw: 'PesertaEka123' });
  ok(/sudah dipakai/.test(await txt(SE)), 'Nama pengguna yang sudah dipakai ditolak');
  await signup(SE, { nama: 'Eka', email: 'eka@contoh.id', hp: '081300000002', instansi: 'Puskesmas Damai', username: 'eka', pw: 'PesertaEka123' });
  ok((await S2.evaluate(() => Core.me().username)) === 'cici' && (await SE.evaluate(() => Core.me().username)) === 'eka', 'Beberapa peserta membuat akun');
  const SF = await newPage(); await signup(SF, { nama: 'Fajar', email: 'fajar@contoh.id', hp: '081300000003', instansi: 'X', username: 'fajar', pw: 'PesertaFajar123' });
  ok(/Terlalu banyak akun/.test(await txt(SF)), 'Batas 5 akun per jam per jaringan (perlindungan bot)');
  await login(X, 'ani', 'PesertaAni123', BASE); ok(/salah/.test(await txt(X)), 'Akun peserta subdomain tidak berlaku di Si PANDU');

  // ---- Admin: kegiatan, kuota, segmen
  await go(A, 'bl'); await A.fill('#bkT', 'Pelatihan QC Westgard 2026'); await A.fill('#bkO', 'Labkesda Balikpapan'); await click(A, '[data-act=blKegAdd]');
  ok(/Kuota harus/.test(await toasts(A)), 'Kegiatan tanpa kuota ditolak (kuota wajib)');
  await A.fill('#bkT', 'Pelatihan QC Westgard 2026'); await A.fill('#bkO', 'Labkesda Balikpapan'); await A.fill('#bkK', '2'); await click(A, '[data-act=blKegAdd]');
  const kid = await A.evaluate(() => location.hash.split('-')[1]);
  let d = (await api(A, 'bl_get', { id: +kid })).r;
  ok(kid && d.keg.kuota === 2 && d.segs.map(s => s.jenis).join() === 'pretest,materi,posttest,penutup', 'Kegiatan dibuat dengan kuota 2 dan segmen wajib');
  await click(A, '[data-act=blSegAdd][data-v=praktik]'); await click(A, '[data-act=blSegAdd][data-v=materi]');
  d = (await api(A, 'bl_get', { id: +kid })).r;
  const prak = d.segs.find(s => s.jenis === 'praktik'), m1 = d.segs[1], m2 = d.segs[3];
  await api(A, 'bl_seg_move', { id: prak.id, dir: 1 });
  ok(!(await api(A, 'bl_seg_move', { id: m1.id, dir: -1 })).ok, 'Urutan segmen yang melanggar ditolak');
  const pemb = (await A.evaluate(() => Core.S.users.find(u => u.username === 'pembina1').id)), pw1 = (await A.evaluate(() => Core.S.users.find(u => u.username === 'pengawas1').id));
  await A.reload(); await A.waitForTimeout(400);
  await A.check(`[data-blpeople=fasil][value="${fid}"]`); await A.waitForTimeout(450);
  await A.check(`[data-blpeople=pembuka][value="${pemb}"]`); await A.waitForTimeout(450);
  await A.check(`[data-blpeople=pengawas][value="${pw1}"]`); await A.waitForTimeout(450);
  ok(!(await A.locator(`[data-blseg="${m1.id}"][data-k=picId] option[value="${pw1}"]`).count()), 'Pengawas tidak muncul sebagai pilihan narasumber/PIC');
  await A.selectOption(`[data-blseg="${m1.id}"][data-k=picId]`, String(fid)); await A.waitForTimeout(450);
  await A.fill(`[data-blseg="${m1.id}"][data-k=date]`, '2026-12-01'); await A.press(`[data-blseg="${m1.id}"][data-k=date]`, 'Tab'); await A.waitForTimeout(400);
  await api(A, 'bl_seg_sesi', { id: m1.id, sesi: [{ title: 'Aturan Westgard', date: '2026-12-01', from: '08:00', to: '10:00', pic: 'Dr. Rina' }, { title: 'Grafik Levey-Jennings', date: '2026-12-01', from: '10:15', to: '12:00' }] });
  d = (await api(A, 'bl_get', { id: +kid })).r;
  ok(d.keg.fasil.includes(fid) && d.keg.pembuka.includes(pemb) && d.keg.pengawas.includes(pw1) && d.segs[1].picId === fid && d.segs[1].sesi.length === 2, 'Fasilitator, pembuka segmen, Pengawas, PIC, jadwal, sesi tersimpan');
  await A.fill('[data-blk=kuota]', '0'); await A.press('[data-blk=kuota]', 'Tab'); await A.waitForTimeout(400); ok(/Kuota harus/.test(await toasts(A)), 'Kuota 0 ditolak');

  // ---- draf tidak terlihat
  await S1.reload(); await S1.waitForTimeout(300);
  const W = await newPage(); await firstLogin(W, 'pengawas1', uW.password, 'Pengawas1Baru');
  ok((await S1.evaluate(() => Core.S.bl.length)) === 0 && !(await api(S1, 'bl_get', { id: +kid })).ok && (await W.evaluate(() => Core.S.bl.length)) === 0, 'Kegiatan draf tidak terlihat oleh peserta maupun Pengawas');
  ok((await W.evaluate(() => [...document.querySelectorAll('.nav a')].map(a => a.getAttribute('href')).join())) === '#bl', 'Menu Pengawas: Kegiatan');

  // ---- Fasilitator & Pembina menyusun isi
  const FA = await newPage(); await firstLogin(FA, 'fasil1', pwF1, 'Fasilitator1Baru');
  ok((await FA.evaluate(() => [...document.querySelectorAll('.nav a')].map(a => a.getAttribute('href')).join())) === '#bl,#bl-profil,#bl-laporan', 'Menu Fasilitator: Kegiatan, Profil narasumber, Laporan Kegiatan');
  const FB = await newPage(); await firstLogin(FB, 'fasil2', uF2.password, 'Fasilitator2Baru');
  ok((await FB.evaluate(() => Core.S.bl.length)) === 0 && !(await api(FB, 'bl_get', { id: +kid })).ok, 'Fasilitator yang tidak ditugaskan tidak melihat kegiatan');
  await go(FA, 'bl-profil'); await FA.waitForTimeout(400); await FA.fill('#bpJ', 'Ahli Teknologi Laboratorium Medik'); await FA.fill('#bpI', 'Labkesda Balikpapan'); await click(FA, '[data-act=blProfilSave]');
  await FA.setInputFiles('#blFoto', F.foto); await FA.waitForTimeout(800);
  ok((await api(FA, 'bl_get_profil', {})).r.foto, 'Profil & foto narasumber tersimpan');
  await go(FA, 'bl-' + kid); await FA.waitForTimeout(400); await click(FA, '[data-act=blTab][data-v=soal]');
  await FA.fill('#sqT', 'Aturan 1-3s berarti?'); for (const x of ['a', 'b', 'c', 'd']) await FA.fill('#sq' + x, 'Pilihan ' + x.toUpperCase()); await FA.selectOption('#sqK', 'c'); await click(FA, '[data-act=blSoalSave]');
  ok(/Tersimpan: 1 soal\./.test(await txt(FA)) && /10–25 soal/.test(await txt(FA)), 'Soal ditambahkan lewat formulir; keterangan 10–25 soal');
  const keys = 'abcdabcdabcdabcdabcdabcd'.split('');
  for (let i = 0; i < 24; i++) await api(FA, 'bl_soal_save', { kegId: +kid, text: 'Soal ' + (i + 2), oa: 'A', ob: 'B', oc: 'C', od: 'D', key: keys[i] });
  await click(FA, '[data-act=blTab][data-v=materi]');
  await FA.fill('#bmT', 'Modul Westgard'); await FA.selectOption('#bmS', String(m1.id)); await FA.setInputFiles('#bmF', F.materi); await click(FA, '[data-act=blMateriUp]'); await FA.waitForTimeout(400);
  ok(/Modul Westgard/.test(await txt(FA)), 'Materi PDF diunggah');
  await api(FA, 'bl_aspek_add', { kegId: +kid, jenis: 'sikap', name: 'Kedisiplinan', def: 80 });
  await api(FA, 'bl_aspek_add', { kegId: +kid, jenis: 'sikap', name: 'Kerja sama', def: 70 });
  await api(FA, 'bl_aspek_add', { kegId: +kid, jenis: 'praktik', segId: prak.id, name: 'Ketepatan pipetasi', def: 60 });
  d = (await api(FA, 'bl_get', { id: +kid })).r; ok(d.soal.length === 25 && d.aspek.length === 3 && !('eval' in d), '25 soal & 3 aspek; Fasilitator tidak melihat penilaian narasumber');

  // ---- terbit & pendaftaran dengan kuota
  await go(A, 'bl-' + kid); await A.reload(); await A.waitForTimeout(400);
  await click(A, '[data-act=blKegStatus][data-v=terbit]'); await click(A, '#cbOK');
  await A.check('[data-act=blDaftar]'); await A.waitForTimeout(500);
  await S1.reload(); await S1.waitForTimeout(400);
  ok(/Pelatihan QC Westgard 2026/.test(await txt(S1)) && /Pendaftaran dibuka · sisa kuota 2/.test(await txt(S1)), 'Peserta melihat kegiatan terbit dan sisa kuota');
  await go(S1, 'bl-' + kid); await S1.waitForTimeout(400);
  ok((await S1.inputValue('#brN')) === 'Ani Lestari, A.Md.AK' && (await S1.inputValue('#brI')) === 'Puskesmas Baru Ilir' && (await S1.inputValue('#brE')) === 'ani@contoh.id' && (await S1.inputValue('#brH')) === '081234567890', 'Formulir terisi dari akun: nama, instansi, email, No. HP');
  ok(await S1.locator('[data-act=blKirim]').count() === 1 && await S1.locator('[data-act=blKosongkan]').count() === 1 && await S1.locator('a.btn[href="#bl"]').count() === 1, 'Tombol Kirim, Kosongkan, Batal');
  await S1.fill('#brJ', 'Pranata Laboratorium Kesehatan'); await S1.fill('#brP', '199001012020122001');
  await S1.setInputFiles('#brS', F.besar); await click(S1, '[data-act=blKirim]'); ok(/1 MB/.test(await toasts(S1)), 'Surat tugas > 1 MB ditolak');
  await S1.fill('#brJ', 'Pranata Laboratorium Kesehatan'); await S1.fill('#brP', '199001012020122001'); await S1.fill('#brE', 'salah');
  await S1.setInputFiles('#brS', F.surat); await click(S1, '[data-act=blKirim]'); ok(/email tidak valid/.test(await toasts(S1)), 'Email tidak valid pada formulir ditolak');
  await S1.fill('#brJ', 'Pranata Laboratorium Kesehatan'); await S1.fill('#brP', '199001012020122001'); await S1.fill('#brE', 'ani@contoh.id');
  await S1.setInputFiles('#brS', F.surat); await click(S1, '[data-act=blKirim]'); await S1.waitForTimeout(300);
  const waitTxt = 'Sedang menunggu verifikasi Admin, silahkan kembali ke halaman ini beberapa saat lagi';
  ok((await S1.evaluate(() => document.querySelector('.content').innerText.trim())).endsWith(waitTxt) && !(await S1.locator('.content button, .content input').count()), 'Setelah dikirim: jendela polos menunggu verifikasi');
  const reg = (p, data) => p.evaluate(async (x) => { const fd = new FormData(); Object.keys(x).forEach(k => fd.append(k, x[k])); fd.append('surat', new Blob(['%PDF-1.4 x'], { type: 'application/pdf' }), 's.pdf'); return Core.api('bl_daftar', null, { form: fd }).then(() => 'ok', e => e.message); }, data);
  const rd = (who, inst) => ({ kegId: kid, name: who, jabatan: 'ATLM', nip: 'K-' + who, instansi: inst || 'Instansi ' + who, email: who.toLowerCase() + '@contoh.id', hp: '081211112222' });
  ok(/sudah terdaftar/.test(await reg(S1, rd('Ani'))), 'Peserta tidak dapat mendaftar dua kali');
  ok((await reg(SBu, rd('Budi'))) === 'ok', 'Peserta kedua mendaftar (kuota terisi 2 dari 2)');
  await go(S2, 'bl'); await S2.reload(); await S2.waitForTimeout(400);
  ok(/Kuota penuh/.test(await txt(S2)), 'Kuota penuh → peserta lain melihat "Kuota penuh"');
  await go(S2, 'bl-' + kid); await S2.waitForTimeout(400); ok(!(await S2.locator('#brS').count()) && /Kuota penuh/.test(await txt(S2)), 'Formulir pendaftaran tertutup saat kuota penuh');
  ok(/Kuota kegiatan ini sudah penuh/.test(await reg(S2, rd('Cici'))), 'Server menolak pendaftaran saat kuota penuh');

  // ---- Admin: verifikasi & kuota terbuka kembali
  await A.reload(); await A.waitForTimeout(400);
  ok(/2/.test(await A.evaluate(() => (document.querySelector('.nav a[href="#bl"] .nbadge') || {}).textContent || '')), 'Menu Admin menandai 2 pendaftar menunggu verifikasi');
  await click(A, '[data-act=blTab][data-v=peserta]');
  ok(/Kuota terisi 2 dari 2/.test(await txt(A)) && /ani@contoh\.id/.test(await txt(A)) && /Puskesmas Baru Ilir/.test(await txt(A)), 'Verifikasi: kuota terisi, instansi, email/No. HP pendaftar tampil');
  d = (await api(A, 'bl_get', { id: +kid })).r;
  const pAni = d.peserta.find(p => p.username === 'ani'), pBudi = d.peserta.find(p => p.username === 'budi');
  ok((await status(S1, 'berkas.php?j=surat&id=' + pAni.id)) === 200 && (await status(SBu, 'berkas.php?j=surat&id=' + pAni.id)) === 404 && (await status(A, 'berkas.php?j=surat&id=' + pAni.id)) === 200 && (await status(W, 'berkas.php?j=surat&id=' + pAni.id)) === 404, 'Surat tugas hanya untuk pemilik dan Admin');
  await click(A, `[data-act=blVerif][data-id="${pAni.id}"][data-v=lengkapi]`); await A.fill('#vMsg', 'Surat tugas belum ditandatangani.'); await click(A, '#vOK');
  ok(/Kuota kegiatan ini sudah penuh/.test(await reg(S2, rd('Cici'))), '"Diminta melengkapi" tetap mengisi kuota');
  await click(A, `[data-act=blVerif][data-id="${pBudi.id}"][data-v=ditolak]`); await A.fill('#vMsg', 'Bukan tenaga laboratorium.'); await click(A, '#vOK');
  await S2.reload(); await S2.waitForTimeout(400); await go(S2, 'bl'); ok(/sisa kuota 1/.test(await txt(S2)), 'Pendaftar ditolak → formulir terbuka kembali (sisa kuota 1)');
  ok((await reg(S2, rd('Cici', 'RSUD Kanujoso'))) === 'ok', 'Peserta berikutnya dapat mendaftar setelah kuota terbuka');
  ok(/sudah penuh/.test(await reg(SD, rd('Dedi'))), 'Kuota penuh lagi → pendaftar berikutnya ditolak');
  await go(SBu, 'bl-' + kid); await SBu.waitForTimeout(400);
  ok(/Ditolak/.test(await txt(SBu)) && /Bukan tenaga laboratorium/.test(await txt(SBu)) && !(await SBu.locator('[data-act=blKirim]').count()), 'Ditolak: pesan Admin tampil, pendaftaran berhenti');
  await S1.reload(); await S1.waitForTimeout(300); await go(S1, 'bl-' + kid); await S1.waitForTimeout(400);
  ok(/Perlu dilengkapi/.test(await txt(S1)) && /belum ditandatangani/.test(await txt(S1)), 'Diminta melengkapi: pesan Admin tampil');
  await S1.fill('#brJ', 'Pranata Laboratorium Kesehatan Ahli Pertama'); await S1.setInputFiles('#brS', F.surat); await click(S1, '[data-act=blKirim][data-ulang="1"]');
  d = (await api(A, 'bl_get', { id: +kid })).r; const pCici = d.peserta.find(p => p.username === 'cici');
  ok(d.peserta.find(p => p.id === pAni.id).status === 'menunggu', 'Dilengkapi & dikirim ulang → menunggu verifikasi');
  await api(A, 'bl_verif', { id: pAni.id, v: 'diterima' }); await api(A, 'bl_verif', { id: pCici.id, v: 'diterima' });
  ok(!(await api(A, 'bl_keg_update', { id: +kid, k: 'kuota', v: '1' })).ok && (await api(A, 'bl_keg_update', { id: +kid, k: 'kuota', v: '3' })).ok, 'Kuota tidak boleh di bawah jumlah peserta diterima; kuota dapat dinaikkan');
  await api(A, 'bl_keg_update', { id: +kid, k: 'kuota', v: '2' });

  // ---- pembelajaran
  const P = await newPage(); await firstLogin(P, 'pembina1', uP.password, 'Pembina1Baru');
  ok(!(await api(S1, 'bl_jawab', { segId: d.segs[0].id, soalId: 1, v: 'a' })).ok, 'Segmen belum dibuka: jawaban ditolak');
  const segs = d.segs, pre = segs[0], post = segs[4], pen = segs[5];
  await go(P, 'bl-' + kid); await P.waitForTimeout(400);
  await P.click(`[data-act=blSegState][data-id="${pre.id}"][data-v=buka]`); await P.waitForTimeout(200); await click(P, '#cbOK');
  ok((await api(P, 'bl_get', { id: +kid })).r.segs[0].status === 'buka', 'Pembina pembuka segmen membuka Pretest');
  ok(!(await api(W, 'bl_seg_state', { id: pre.id, v: 'tutup' })).ok && !(await api(W, 'bl_verif', { id: pAni.id, v: 'diterima' })).ok && !(await api(W, 'bl_nilai', { pesertaId: pAni.id, aspekId: 1, v: '90' })).ok, 'Pengawas tidak dapat membuka segmen, verifikasi, atau menilai');
  const soalA = (await api(A, 'bl_get', { id: +kid })).r.soal; const kunci = {}; soalA.forEach(q => kunci[q.id] = q.key);
  await S1.reload(); await S1.waitForTimeout(400); await go(S1, `bl-${kid}-s${pre.id}`); await S1.waitForTimeout(500);
  const qs = (await api(S1, 'bl_get', { id: +kid })).r.tests[pre.id].soal;
  const wrong = k => ({ a: 'b', b: 'c', c: 'd', d: 'a' })[k];
  for (let i = 0; i < 2; i++) { await S1.check(`[data-bljawab="${qs[i].id}"][value="${kunci[qs[i].id]}"]`); await S1.waitForTimeout(250); }
  ok(/Terjawab 2\/25/.test(await txt(S1)), 'Jawaban tersimpan per soal');
  for (let i = 2; i < 15; i++) await api(S1, 'bl_jawab', { segId: pre.id, soalId: qs[i].id, v: kunci[qs[i].id] });
  for (let i = 15; i < 25; i++) await api(S1, 'bl_jawab', { segId: pre.id, soalId: qs[i].id, v: wrong(kunci[qs[i].id]) });
  await api(S1, 'bl_selesai', { segId: pre.id });
  for (let i = 0; i < 25; i++) await api(S2, 'bl_jawab', { segId: pre.id, soalId: qs[i].id, v: i < 10 ? kunci[qs[i].id] : wrong(kunci[qs[i].id]) });
  await api(S2, 'bl_selesai', { segId: pre.id });
  // Pengawas: timeline
  await W.reload(); await W.waitForTimeout(400); await go(W, 'bl-' + kid); await W.waitForTimeout(500);
  const wt = await txt(W);
  ok((await W.locator('.timeline .tl-item').count()) === segs.length + 1 && /2 dari 2 peserta menyelesaikan · rata-rata nilai 50/.test(wt) && /Sedang berlangsung/.test(wt) && /Hasil akhir/.test(wt) && /Predikat diumumkan/.test(wt), 'Pengawas: kartu timeline sesuai rundown (progres Pretest 2/2, rata-rata 50) + kartu Hasil akhir');
  const order = await W.evaluate(() => [...document.querySelectorAll('.timeline .tl-item b')].map(b => b.textContent.replace(/^\d+\.\s*/, '')));
  ok(order.slice(0, segs.length).join('|') === segs.map(s => s.title).join('|'), 'Urutan kartu = rundown segmen');
  ok(!(await W.locator('.tabs, input, [data-act=blVerif], [data-act=blSegState]').count()), 'Pengawas hanya melihat (tanpa tombol aksi)');
  const W2 = await newPage(); await firstLogin(W2, 'pengawas2', uW2.password, 'Pengawas2Baru');
  ok((await W2.evaluate(() => Core.S.bl.length)) === 0 && !(await api(W2, 'bl_get', { id: +kid })).ok, 'Pengawas yang tidak ditugaskan tidak melihat kegiatan');
  // materi & praktik
  await api(P, 'bl_seg_state', { id: pre.id, v: 'tutup' });
  for (const s of [segs[1], segs[2], segs[3]]) await api(P, 'bl_seg_state', { id: s.id, v: 'buka' });
  const mid = (await api(A, 'bl_get', { id: +kid })).r.materi[0].id;
  ok((await status(S1, 'berkas.php?j=materi&id=' + mid)) === 200 && (await status(SBu, 'berkas.php?j=materi&id=' + mid)) === 404, 'Materi untuk peserta diterima yang sudah Pretest');
  for (const s of [segs[1], segs[2], segs[3]]) await api(S1, 'bl_selesai', { segId: s.id });
  await api(S2, 'bl_selesai', { segId: segs[1].id }); await api(S2, 'bl_selesai', { segId: segs[2].id });
  for (const s of [segs[1], segs[2], segs[3]]) await api(P, 'bl_seg_state', { id: s.id, v: 'tutup' });
  const asp = (await api(FA, 'bl_get', { id: +kid })).r.aspek;
  await api(FA, 'bl_nilai', { pesertaId: pAni.id, aspekId: asp[0].id, v: '90' }); await api(FA, 'bl_nilai', { pesertaId: pAni.id, aspekId: asp[2].id, v: '85' });
  await api(P, 'bl_seg_state', { id: post.id, v: 'buka' });
  for (let i = 0; i < 25; i++) await api(S1, 'bl_jawab', { segId: post.id, soalId: qs[i].id, v: i < 22 ? kunci[qs[i].id] : wrong(kunci[qs[i].id]) });
  await api(S1, 'bl_selesai', { segId: post.id });
  for (let i = 0; i < 25; i++) await api(S2, 'bl_jawab', { segId: post.id, soalId: qs[i].id, v: i < 20 ? kunci[qs[i].id] : wrong(kunci[qs[i].id]) });
  await api(S2, 'bl_selesai', { segId: post.id });
  await api(P, 'bl_seg_state', { id: post.id, v: 'tutup' });
  await api(P, 'bl_seg_state', { id: pen.id, v: 'buka' });
  const narsum = (await api(S1, 'bl_get', { id: +kid })).r.narsum.map(x => x.id);
  for (const nid of narsum) for (let i = 0; i < 10; i++) { await api(S1, 'bl_eval', { segId: pen.id, narsumId: nid, aspek: i, v: String(80 + i) }); await api(S2, 'bl_eval', { segId: pen.id, narsumId: nid, aspek: i, v: '70' }); }
  await api(S1, 'bl_selesai', { segId: pen.id }); await api(S2, 'bl_selesai', { segId: pen.id });
  await api(P, 'bl_seg_state', { id: pen.id, v: 'tutup' });
  d = (await api(S1, 'bl_get', { id: +kid })).r; const r1 = d.result.mine;
  ok(r1.pre === 60 && r1.post === 88 && r1.sikap === 80 && r1.praktik === 85 && r1.terbaik === 82.4 && r1.instansi === 'Puskesmas Baru Ilir', 'Nilai Ani: pre 60, post 88, sikap 80, praktik 85, skor terbaik 82,4');
  const r2 = (await api(S2, 'bl_get', { id: +kid })).r.result.mine;
  ok(r2.pre === 40 && r2.post === 80 && r2.praktik === null && r2.terbaik === 52.8, 'Cici: praktik tidak diselesaikan → tidak tercatat; skor terbaik 52,8');
  ok(d.result.predikat.post[0].me && d.result.predikat.terbaik[0].me && /Cici/.test(d.result.predikat.progres[0].name) && d.result.predikat.progres[0].instansi === 'RSUD Kanujoso', 'Predikat dengan nama & instansi');
  await go(S1, 'bl-' + kid); await S1.waitForTimeout(500);
  ok(/Selamat! Anda meraih predikat Nilai Tertinggi, Peserta Terbaik/.test(await txt(S1)) && /RSUD Kanujoso/.test(await txt(S1)), 'Peserta melihat hasil, ucapan predikat, dan instansi peraih');
  // Pengawas: hasil akhir
  await W.reload(); await W.waitForTimeout(400); await go(W, 'bl-' + kid); await W.waitForTimeout(500);
  const wf = await txt(W);
  ok(/Hasil akhir/.test(wf) && /Nilai Tertinggi/.test(wf) && /Ani Lestari/.test(wf) && /82,4/.test(wf) && !/Predikat diumumkan/.test(wf) && (await W.locator('.tl-item.tutup').count()) === segs.length + 1, 'Pengawas: hasil akhir (predikat & skor) setelah semua segmen ditutup');
  // Riwayat saya
  await go(S1, 'bl-riwayat'); await S1.waitForTimeout(500);
  const rw = await txt(S1);
  ok(/Riwayat saya/.test(rw) && /Pelatihan QC Westgard 2026/.test(rw) && /Diterima/.test(rw) && /88/.test(rw) && /Nilai Tertinggi, Peserta Terbaik/.test(rw), 'Riwayat saya: kegiatan, status, nilai, predikat');
  await go(SBu, 'bl-riwayat'); await SBu.waitForTimeout(500); ok(/Ditolak/.test(await txt(SBu)), 'Riwayat peserta ditolak menampilkan status Ditolak');
  ok(!(await api(W, 'bl_riwayat', {})).ok, 'Riwayat hanya untuk peserta');
  // rekap
  await A.reload(); await A.waitForTimeout(400); await click(A, '[data-act=blTab][data-v=rekap]');
  ok(/82,4/.test(await txt(A)) && /Penilaian peserta terhadap narasumber/.test(await txt(A)), 'Rekap Admin: nilai & penilaian narasumber');

  const anyToast = p => p.evaluate(() => [...document.querySelectorAll('.toast')].map(x => x.textContent).join(' '));
  const dbq = sql => require('child_process').execFileSync('php', ['-r', '$d=new PDO("sqlite:/tmp/sp/db.sqlite"); echo $d->query($argv[1])->fetchColumn();', sql]).toString().trim();
  // ---- Laporan format lama dipindahkan otomatis ke bab baru
  const f2id = dbq("SELECT id FROM bl_users WHERE username='fasil2'");
  dbq(`INSERT INTO bl_laporan (keg_id, user_id, pendahuluan, mekanisme, kendala, kesimpulan, rekomendasi, status, ttd_tempat) VALUES (${kid}, ${f2id}, 'Latar lama.', 'Mekanisme lama:' || char(10) || '1. Pretest' || char(10) || '2. Materi', '1. Jaringan putus.', 'Kesimpulan lama.', 'Rekomendasi lama.', 'draf', 'Balikpapan')`);
  const oldId = +dbq(`SELECT id FROM bl_laporan WHERE keg_id=${kid} AND user_id=${f2id}`);
  const OL = (await api(A, 'bl_lap_get', { lapId: oldId })).r;
  ok(OL.bab[0].sub[0].html === '<p>Latar lama.</p>' && OL.bab[1].sub[2].html === '<p>Mekanisme lama:</p><ol><li>Pretest</li><li>Materi</li></ol>' && OL.bab[2].sub[0].html === '<ol><li>Jaringan putus.</li></ol>' && OL.bab[3].html === '<p>Kesimpulan lama.</p><p>Rekomendasi lama.</p>' && OL.sampul.judulKegiatan === 'Pelatihan QC Westgard 2026', 'Isi format lama dipindahkan: Pendahuluan→A.1, Mekanisme→B.3, Kendala→C.1, Kesimpulan & Rekomendasi→D');
  ok((await api(A, 'bl_lap_get', { lapId: oldId })).r.bab[0].sub[0].id === OL.bab[0].sub[0].id, 'Struktur hasil pemindahan tersimpan (id sub-bab tetap)');
  dbq(`DELETE FROM bl_laporan WHERE id=${oldId}`);
  // ---- Laporan Kegiatan (Fasilitator): sampul, bab/sub-bab berformat, tabel data, lampiran
  require('child_process').execFileSync('python3', ['-c', "from PIL import Image, ImageDraw\nim=Image.new('RGB',(1600,1000),(30,120,160)); ImageDraw.Draw(im).ellipse((500,200,1100,800),fill=(240,200,40)); im.save('/tmp/uji_sampul.jpg',quality=90)\np=[Image.new('RGB',(1240,1754),(255,255,255)) for i in range(2)]\nfor i,x in enumerate(p): ImageDraw.Draw(x).rectangle((100,100,1140,300+i*200),fill=(200,30,30))\np[0].save('/tmp/uji_lamp.pdf',save_all=True,append_images=p[1:])"]);
  const ed = (p, bi, n) => p.locator(`.rt[data-rtb="${bi}"]`).nth(n).locator('.rt-ed');
  const tb = async (p, bi, n, cmd) => { await p.locator(`.rt[data-rtb="${bi}"]`).nth(n).locator(`[data-rtc="${cmd}"]`).click(); await p.waitForTimeout(250); };
  const lapGet = async () => (await api(FA, 'bl_lap_get', { kegId: +kid })).r;
  await go(FA, 'bl-laporan'); await FA.waitForTimeout(500);
  ok(/Laporan Kegiatan/.test(await txt(FA)) && /Pelatihan QC Westgard 2026/.test(await txt(FA)), 'Laporan Kegiatan: daftar kegiatan yang ditugaskan');
  ok((await FA.evaluate(() => document.querySelector('.nav a.on').getAttribute('href'))) === '#bl-laporan', 'Menu Laporan Kegiatan aktif');
  await go(FA, 'bl-laporan-' + kid); await FA.waitForTimeout(700);
  let lt = await txt(FA);
  ok(/A\. PENDAHULUAN/.test(lt) && /B\. METODOLOGI PELAKSANAAN KEGIATAN/.test(lt) && /C\. HASIL PELAKSANAAN KEGIATAN/.test(lt) && /D\. PENUTUP/.test(lt) && /1\. Latar Belakang/.test(lt) && /4\. Susunan Acara/.test(lt) && /3\. Rencana Aksi/.test(lt), 'Struktur bab & sub-bab awal sesuai daftar isi');
  ok((await FA.inputValue('[data-lap=judulKegiatan]')) === 'Pelatihan QC Westgard 2026' && (await FA.inputValue('[data-lap=nama]')) !== '' && (await FA.locator('.rt-ed').count()) === 14, 'Sampul terisi awal (judul kegiatan, penyusun); 14 penyunting');
  await FA.fill('[data-lap=judulLaporan]', 'PEMBINAAN LABKESMAS TINGKAT 2 KOTA BALIKPAPAN'); await FA.press('[data-lap=judulLaporan]', 'Tab'); await FA.waitForTimeout(400);
  await FA.fill('[data-lap=jabatan]', 'Crosschecker Malaria Kota Balikpapan'); await FA.press('[data-lap=jabatan]', 'Tab'); await FA.waitForTimeout(400);
  await FA.setInputFiles('#blLapFoto', '/tmp/uji_sampul.jpg'); await FA.waitForTimeout(1500);
  const fotoOk = await FA.evaluate(() => { const i = document.querySelector('img.lap-foto'); return i && i.complete && i.naturalWidth > 100; });
  ok(fotoOk && (await lapGet()).sampul.foto, 'Foto sampul diunggah (diperkecil di browser) dan tampil');
  await FA.setInputFiles('#blTor', 'tests/data/tor_contoh.docx'); await FA.waitForTimeout(900);
  ok(/^Pemantapan mutu internal/.test(await ed(FA, 0, 1).innerText()) && /TOR sudah diunggah/.test(await txt(FA)), 'TOR (.docx) → sub-bab Latar Belakang');
  await FA.setInputFiles('#blTor', F.surat); await FA.waitForTimeout(700); ok(/\.docx/.test(await toasts(FA)), 'TOR selain .docx ditolak');
  // penyunting: ketik, tebal, rata tengah, daftar bernomor, tabel manual
  await ed(FA, 1, 1).click(); await FA.keyboard.type('Pelatihan dilaksanakan di Aula Labkesda.');
  await FA.keyboard.down('Shift'); for (let i = 0; i < 9; i++) await FA.keyboard.press('ArrowLeft'); await FA.keyboard.up('Shift');
  await tb(FA, 1, 1, 'bold');
  await FA.keyboard.press('End'); await tb(FA, 1, 1, 'bold'); await FA.keyboard.press('Enter'); await FA.keyboard.type('Rangkaian:');
  await tb(FA, 1, 1, 'justifyCenter');
  await FA.keyboard.press('Enter'); await tb(FA, 1, 1, 'justifyLeft'); await tb(FA, 1, 1, 'insertOrderedList'); await FA.keyboard.type('Pretest'); await FA.keyboard.press('Enter'); await FA.keyboard.type('Materi');
  await FA.keyboard.press('Enter'); await FA.keyboard.press('Enter');
  await tb(FA, 1, 1, 'tabel'); await FA.fill('#rtR', '2'); await FA.fill('#rtC', '2'); await FA.click('#rtOK'); await FA.waitForTimeout(300);
  await FA.keyboard.type('Sesi'); await ed(FA, 1, 1).locator('th').nth(1).click(); await FA.keyboard.type('Jam');
  await tb(FA, 1, 1, 'barisTambah'); await tb(FA, 1, 1, 'kolomTambah');
  await FA.waitForTimeout(2600);   // tersimpan otomatis setelah jeda ketik
  let LP = await lapGet(), h1 = LP.bab[1].sub[0].html;
  ok(/Aula <b>Labkesda\.<\/b><\/p>/.test(h1) && /text-align:center[^>]*>Rangkaian:/.test(h1) && /<ol><li>Pretest<\/li><li>Materi<\/li><\/ol>/.test(h1), 'Penyunting: tebal, rata tengah, daftar bernomor tersimpan otomatis');
  ok(/<table><tr><th>Sesi<\/th><th>Jam<\/th><th><br><\/th><\/tr><tr><td><br><\/td><td><br><\/td><td><br><\/td><\/tr><tr><td><br><\/td><td><br><\/td><td><br><\/td><\/tr><\/table>/.test(h1), 'Penyunting: sisip tabel, tambah baris & kolom');
  // tabel olahan website dengan filter
  const st = (await api(FA, 'bl_lap_tabel', { kegId: +kid, jenis: 'stat' })).r;
  ok(/Skor akhir \(Skor Peserta Terbaik\)/.test(st.html) && /67,6/.test(st.html) && /82,4/.test(st.html), 'Tabel data: statistik penilaian (rata-rata skor akhir 67,6)');
  const dt = (await api(FA, 'bl_lap_tabel', { kegId: +kid, jenis: 'detail', instansi: ['Puskesmas Baru Ilir'] })).r;
  ok(dt.n === 1 && dt.baris[0][dt.kepala.indexOf('Skor akhir')] === '82,4' && dt.opsi.instansi.length === 2, 'Tabel data: filter instansi');
  const sb = (await api(FA, 'bl_lap_tabel', { kegId: +kid, jenis: 'instansi' })).r;
  ok(sb.baris.length === 2 && sb.kepala.join('|') === 'No|Instansi|Jumlah peserta', 'Tabel data: sebaran instansi');
  await ed(FA, 2, 1).click(); await FA.keyboard.type('Jaringan internet sempat terputus.'); await FA.keyboard.press('Enter');
  await tb(FA, 2, 1, 'data'); await FA.waitForTimeout(700);
  await FA.selectOption('.modal [data-td=jenis]', 'detail'); await FA.waitForTimeout(700);
  ok(/82,4/.test(await FA.innerText('.td-prev')) && /52,8/.test(await FA.innerText('.td-prev')), 'Pratinjau tabel rincian nilai');
  await FA.locator('.modal [data-td=kolom][value=jabatan]').check(); await FA.waitForTimeout(600);
  await FA.locator('.modal [data-td=kategori][value=b]').check(); await FA.waitForTimeout(700);
  const prev = await FA.innerText('.td-prev');
  ok(/82,4/.test(prev) && !/52,8/.test(prev) && /Jabatan\/profesi/.test(prev), 'Filter kategori & pilihan kolom pada pratinjau');
  await FA.click('#tdOK'); await FA.waitForTimeout(2600);
  LP = await lapGet();
  ok(/Jaringan internet sempat terputus\./.test(LP.bab[2].sub[0].html) && /<th[^>]*>Skor akhir<\/th>/.test(LP.bab[2].sub[0].html) && /82,4/.test(LP.bab[2].sub[0].html), 'Tabel data disisipkan ke penyunting');
  // sub-bab: tambah, ubah nama, hapus
  await FA.click('[data-act=blSubTambah][data-bab="3"]'); await FA.fill('#nmV', 'Saran'); await FA.click('#nmOK'); await FA.waitForTimeout(600);
  await FA.click('[data-act=blSubNama][data-bab="3"]'); await FA.fill('#nmV', 'Saran dan Masukan'); await FA.click('#nmOK'); await FA.waitForTimeout(600);
  const ruang = (await lapGet()).bab[0].sub[2].id;
  await FA.click(`[data-act=blSubHapus][data-id="${ruang}"]`); await FA.click('#cbOK'); await FA.waitForTimeout(600);
  LP = await lapGet();
  ok(LP.bab[3].sub.length === 1 && LP.bab[3].sub[0].judul === 'Saran dan Masukan' && LP.bab[0].sub.length === 2 && /1\. Saran dan Masukan/.test(await txt(FA)), 'Sub-bab: tambah, ubah nama, hapus');
  await ed(FA, 3, 0).click(); await FA.keyboard.type('Kegiatan berjalan baik.'); await ed(FA, 3, 1).click(); await FA.keyboard.type('Perlu pelatihan lanjutan.');
  // lampiran: PDF (halaman → gambar di browser) & foto
  await FA.fill('#lampBaru', 'Materi paparan'); await click(FA, '[data-act=blLampTambah]');
  await FA.fill('#lampBaru', 'Dokumentasi'); await click(FA, '[data-act=blLampTambah]');
  LP = await lapGet();
  ok(LP.lampiran.length === 2 && /LAMPIRAN I\./.test(await txt(FA)) && /LAMPIRAN II\./.test(await txt(FA)), 'Lampiran ditambahkan (bernomor I, II)');
  await FA.setInputFiles(`[data-lampup="${LP.lampiran[0].id}"]`, '/tmp/uji_lamp.pdf'); await FA.waitForTimeout(6000);
  await FA.setInputFiles(`[data-lampup="${LP.lampiran[1].id}"]`, ['/tmp/uji_sampul.jpg', F.foto]); await FA.waitForTimeout(3000);
  LP = await lapGet();
  ok(LP.lampiran[0].files.length === 1 && LP.lampiran[0].files[0].jenis === 'pdf' && LP.lampiran[0].files[0].hal.length === 2 && /2 halaman/.test(await txt(FA)), 'Lampiran PDF 2 halaman diubah menjadi gambar');
  ok(LP.lampiran[1].files.length === 2 && (await FA.locator('.lamp-thumbs img').count()) === 4, 'Lampiran foto (JPG & PNG) + gambar mini');
  fs.writeFileSync('/tmp/uji_lamp.txt', 'teks');
  await FA.setInputFiles(`[data-lampup="${LP.lampiran[1].id}"]`, '/tmp/uji_lamp.txt'); await FA.waitForTimeout(800);
  ok(/JPG, PNG, atau PDF/.test(await toasts(FA)), 'Format lampiran selain JPG/PNG/PDF ditolak');
  await FA.waitForTimeout(4000);
  await FA.setInputFiles(`[data-lampup="${LP.lampiran[1].id}"]`, F.bukan); await FA.waitForTimeout(1500);
  ok(/PDF tidak dapat dibaca/.test(await toasts(FA)), 'PDF rusak ditolak dengan pesan');
  await FA.fill(`[data-lampjudul="${LP.lampiran[1].id}"]`, 'Dokumentasi kegiatan'); await FA.press(`[data-lampjudul="${LP.lampiran[1].id}"]`, 'Tab'); await FA.waitForTimeout(600);
  await FA.click(`[data-act=blLampFileHapus][data-file="${LP.lampiran[1].files[1].id}"]`); await FA.waitForTimeout(700);
  LP = await lapGet();
  ok(LP.lampiran[1].judul === 'Dokumentasi kegiatan' && LP.lampiran[1].files.length === 1, 'Ubah judul lampiran & hapus file');
  ok((await FA.evaluate(u => fetch(u).then(r => r.status), 'berkas.php?j=lapimg&lap=' + LP.lapId + '&f=' + LP.lampiran[0].files[0].hal[0].f)) === 200 && (await W.evaluate(u => fetch(u).then(r => r.status), 'berkas.php?j=lapimg&lap=' + LP.lapId + '&f=' + LP.lampiran[0].files[0].hal[0].f)) === 404, 'Gambar laporan hanya untuk yang berhak');
  await FA.selectOption('[data-lap=pembinaId]', String(pemb)); await FA.waitForTimeout(500);
  await FA.reload(); await FA.waitForTimeout(900);
  ok((await ed(FA, 3, 0).innerText()).startsWith('Kegiatan berjalan baik') && (await ed(FA, 3, 1).innerText()).startsWith('Perlu pelatihan') && (await FA.inputValue('[data-lap=pembinaId]')) === String(pemb) && (await FA.inputValue('[data-lap=judulLaporan]')).startsWith('PEMBINAAN'), 'Isian penyunting (tersimpan saat pindah kolom), sampul & Pembina tersimpan');
  ok(!(await api(FA, 'bl_lap_bab', { kegId: +kid, op: 'isi', bab: 9, sub: '', html: 'x' })).ok && (await api(FA, 'bl_lap_bab', { kegId: +kid, op: 'isi', bab: 3, sub: '', html: '<p onclick="x()">Aman<script>alert(1)</script><img src=x onerror=y></p>' })).ok && (await lapGet()).bab[3].html === '<p>Aman</p>', 'HTML penyunting dibersihkan di server');
  const dl = (p, f) => p.evaluate(([k, f]) => fetch('berkas.php?j=lap&f=' + f + '&id=' + k).then(async r => ({ s: r.status, t: r.headers.get('content-type') || '', b: new Uint8Array(await r.arrayBuffer()) })).then(x => ({ s: x.s, t: x.t, n: x.b.length, h: String.fromCharCode.apply(null, x.b.slice(0, 5)) })), [kid, f]);
  const pdfR = await dl(FA, 'pdf'), docR = await dl(FA, 'docx');
  ok(pdfR.s === 200 && pdfR.h === '%PDF-' && pdfR.n > 3000, 'Unduh PDF');
  ok(docR.s === 200 && docR.h.startsWith('PK') && /wordprocessingml/.test(docR.t), 'Unduh Word (.docx)');
  ok((await dl(P, 'pdf')).s === 404 && (await dl(W, 'pdf')).s === 404 && (await dl(FB, 'pdf')).s === 404, 'Draf laporan hanya untuk Fasilitator yang ditugaskan');
  const lapId = (await api(FA, 'bl_lap_get', { kegId: +kid })).r.lapId;
  ok((await api(P, 'bl_lap_list', {})).r.length === 0 && !(await api(P, 'bl_lap_get', { lapId })).ok && !(await api(W, 'bl_lap_list', {})).ok, 'Pembina belum melihat draf yang belum diajukan; Pengawas tidak punya akses');
  const pdfBin = await FA.evaluate(k => fetch('berkas.php?j=lap&f=pdf&id=' + k).then(r => r.arrayBuffer()).then(b => Array.from(new Uint8Array(b))), kid);
  fs.writeFileSync('/tmp/laporan_uji.pdf', Buffer.from(pdfBin));
  const docBin = await FA.evaluate(k => fetch('berkas.php?j=lap&f=docx&id=' + k).then(r => r.arrayBuffer()).then(b => Array.from(new Uint8Array(b))), kid);
  fs.writeFileSync('/tmp/laporan_uji.docx', Buffer.from(docBin));
  const pdft = require('child_process').execFileSync('pdftotext', ['-layout', '/tmp/laporan_uji.pdf', '-']).toString();
  ok(/LEMBAR PENGESAHAN/.test(pdft) && /DAFTAR ISI/.test(pdft) && /PEMBINAAN LABKESMAS TINGKAT 2/.test(pdft) && /1\. Saran dan Masukan/.test(pdft) && /LAMPIRAN II\. Dokumentasi kegiatan/.test(pdft) && /Jaringan internet sempat terputus/.test(pdft), 'PDF: sampul, Lembar Pengesahan, Daftar Isi, bab berformat, lampiran');
  const nImg = Number(require('child_process').execFileSync('sh', ['-c', 'pdfimages -list /tmp/laporan_uji.pdf | tail -n +3 | grep -c image || true']).toString().trim());
  ok(nImg >= 6, 'PDF memuat templat sampul, foto sampul, dan gambar lampiran (' + nImg + ')');
  const docXml = require('child_process').execFileSync('unzip', ['-p', '/tmp/laporan_uji.docx', 'word/document.xml']).toString();
  ok(/TOC \\o/.test(docXml) && /LEMBAR PENGESAHAN/.test(docXml) && /w:framePr/.test(docXml) && (docXml.match(/<wp:inline/g) || []).length >= 3, 'Word: sampul berbingkai, daftar isi (bidang TOC), lampiran');

  // ---- Penerbitan & VeriDoc: Terbitkan → Kembalikan/Sahkan → Ganti → Cabut
  const py = (code) => require('child_process').execFileSync('python3', ['-c', code]).toString().trim();
  const vd = (kode, akses) => fetch('http://127.0.0.1:3912/api/dok' + (akses ? '' : '?kode=' + kode), akses ? { method: 'POST', body: JSON.stringify({ kode, akses }) } : {}).then(r => r.json());
  const bytes = (b) => Buffer.from(b).toString('latin1');
  ok(bytes(pdfBin).includes('DRAF') && Buffer.from(docBin).includes('header1.xml'), 'Draf yang diunduh bercap DRAF – BELUM SAH (PDF & Word)');
  ok(await FA.isDisabled('[data-act=blLapTerbit]'), 'Terbitkan nonaktif sebelum sifat & masa berlaku dipilih');
  await FA.selectOption('[data-lap=kendali]', 'terkendali'); await FA.waitForTimeout(400);
  await FA.selectOption('[data-lap=berlakuMode]', 'tanggal'); await FA.waitForTimeout(500);
  await api(FA, 'bl_lap_save', { kegId: +kid, k: 'berlakuSampai', v: '2020-01-01' });
  ok(!(await api(FA, 'bl_lap_terbit', { kegId: +kid })).ok, 'Masa berlaku yang sudah lewat ditolak');
  await go(FA, 'bl-laporan'); await go(FA, 'bl-laporan-' + kid); await FA.waitForTimeout(600);
  await FA.fill('[data-lap=berlakuSampai]', '2099-12-31'); await FA.press('[data-lap=berlakuSampai]', 'Tab'); await FA.waitForTimeout(600);
  ok(!(await FA.isDisabled('[data-act=blLapTerbit]')), 'Terbitkan aktif setelah Pembina, sifat, dan masa berlaku lengkap');
  await click(FA, '[data-act=blLapTerbit]'); await FA.waitForTimeout(600);
  ok(/Laporan dikirim kepada .+ untuk disahkan\./.test(await anyToast(FA)) && /Menunggu pengesahan/.test(await txt(FA)) && !(await FA.locator('.rt-ed').count()) && (await FA.locator('.rt-view').count()) > 0, 'Terbitkan: dikirim ke Pembina, isian terkunci');
  ok(!(await api(FA, 'bl_lap_bab', { kegId: +kid, op: 'isi', bab: 3, sub: '', html: 'x' })).ok && !(await api(FA, 'bl_lap_save', { kegId: +kid, k: 'judulLaporan', v: 'x' })).ok, 'Isi tidak bisa diubah selama menunggu pengesahan');
  ok(!(await api(FB, 'bl_lap_sahkan', { lapId })).ok && !(await api(A, 'bl_lap_sahkan', { lapId })).ok, 'Hanya Pembina terpilih yang dapat mengesahkan');
  await go(P, 'bl-laporan'); await P.waitForTimeout(500);
  ok(/Pelatihan QC Westgard 2026/.test(await txt(P)) && /Menunggu pengesahan/.test(await txt(P)), 'Pembina: daftar laporan yang diajukan kepadanya');
  await go(P, 'bl-laporan-l' + lapId); await P.waitForTimeout(600);
  await click(P, '[data-act=blLapKembali]'); ok(await P.isDisabled('#dkOK'), 'Kembalikan: catatan wajib');
  await P.fill('#dkWhy', 'Mohon lengkapi kendala teknis.'); await click(P, '#dkOK'); await P.waitForTimeout(600);
  ok(/Laporan dikembalikan kepada Fasilitator\./.test(await anyToast(P)), 'Laporan dikembalikan');
  await go(FA, 'bl-laporan'); await go(FA, 'bl-laporan-' + kid); await FA.waitForTimeout(600);
  ok(/Catatan untuk Fasilitator:\s*Mohon lengkapi kendala teknis\./.test(await txt(FA)) && (await FA.locator('.rt-ed').count()) > 0, 'Fasilitator melihat catatan & dapat menyunting lagi');
  await click(FA, '[data-act=blLapTerbit]'); await FA.waitForTimeout(500);
  await go(P, 'bl-laporan'); await go(P, 'bl-laporan-l' + lapId); await P.waitForTimeout(600);
  await click(P, '[data-act=blLapSahkan]'); await click(P, '#cbOK'); await P.waitForTimeout(1500);
  const th = new Date().getFullYear();
  let L1 = (await api(P, 'bl_lap_get', { lapId })).r;
  ok(/Laporan sudah disahkan dan terdaftar di VeriDoc\./.test(await anyToast(P)) && L1.tampil === 'sah' && L1.dokInfo.nomor === 'LAP/1/Labkesda/' + th, 'Sahkan: nomor otomatis LAP/1/Labkesda/' + th);
  ok(/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/.test(L1.dokInfo.kode) && /^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/.test(L1.dokInfo.akses) && L1.dokInfo.sync === 'ok', 'Kode verifikasi, kode akses (Rahasia–Terkendali), terdaftar di VeriDoc');
  let v1 = await vd(L1.dokInfo.kode);
  ok(v1.status === 'sah' && v1.terkunci && v1.isi === null && v1.sifat === 'rahasia' && v1.kendali === 'terkendali' && v1.pengesah.jabatan === 'Pembina' && v1.sampai === '2099-12-31', 'VeriDoc: identitas & status tampil, isi rahasia terkunci');
  v1 = await vd(L1.dokInfo.kode, L1.dokInfo.akses.toLowerCase());
  const isiTeks = JSON.stringify(v1.isi || []);
  ok(!v1.terkunci && /Judul laporan/.test(isiTeks) && /PEMBINAAN LABKESMAS/.test(isiTeks) && /Skor akhir/.test(isiTeks) && /82,4/.test(isiTeks) && /Jaringan internet sempat terputus/.test(isiTeks) && /LAMPIRAN II\. Dokumentasi kegiatan/.test(isiTeks) && !/Nomor/.test(JSON.stringify(v1.isi[0])), 'VeriDoc: ringkasan isi (identitas sampul, paragraf pertama, tabel lengkap, judul lampiran)');
  ok(!(await api(FA, 'bl_lap_bab', { kegId: +kid, op: 'isi', bab: 3, sub: '', html: 'x' })).ok, 'Isi dokumen sah terkunci');
  const pdf2 = await FA.evaluate(k => fetch('berkas.php?j=lap&f=pdf&id=' + k).then(r => r.arrayBuffer()).then(b => Array.from(new Uint8Array(b))), kid);
  fs.writeFileSync('/tmp/laporan_sah.pdf', Buffer.from(pdf2));
  const doc2 = await FA.evaluate(k => fetch('berkas.php?j=lap&f=docx&id=' + k).then(r => r.arrayBuffer()).then(b => Array.from(new Uint8Array(b))), kid);
  fs.writeFileSync('/tmp/laporan_sah.docx', Buffer.from(doc2));
  ok(!bytes(pdf2).includes('DRAF') && bytes(pdf2).includes('LAP/1/Labkesda/' + th) && bytes(pdf2).includes(L1.dokInfo.kode) && bytes(pdf2).includes(L1.dokInfo.akses) && bytes(pdf2).includes('RAHASIA'), 'PDF sah: tanpa cap DRAF; nomor, sifat, kode verifikasi & kode akses tercetak');
  const qrPdf = py(`import cv2,subprocess,glob\nsubprocess.run(['pdftoppm','-r','200','-png','/tmp/laporan_sah.pdf','/tmp/lsah'],check=True)\nd=cv2.QRCodeDetector()\nout=[]\nfor f in sorted(glob.glob('/tmp/lsah*.png')):\n  v,_,_=d.detectAndDecode(cv2.imread(f))\n  if v: out.append(v)\nprint(out[-1] if out else '')`);
  ok(qrPdf === 'http://127.0.0.1:3912/d/' + L1.dokInfo.kode, 'QR pada PDF terbaca dan mengarah ke halaman VeriDoc dokumen');
  const qrDoc = py(`import zipfile,cv2,numpy as np\nz=zipfile.ZipFile('/tmp/laporan_sah.docx')\nout=''\nfor n in z.namelist():\n  if not n.endswith('.png'): continue\n  im=cv2.imdecode(np.frombuffer(z.read(n),np.uint8),1)\n  for f in (1,0.75,0.5):\n    v=cv2.QRCodeDetector().detectAndDecode(cv2.resize(im,None,fx=f,fy=f,interpolation=cv2.INTER_AREA))[0]\n    if v: out=v; break\nprint(out)`);
  ok(qrDoc === 'http://127.0.0.1:3912/d/' + L1.dokInfo.kode && !Buffer.from(doc2).includes('header1.xml'), 'QR pada Word terbaca; tanpa cap DRAF');
  // Admin: jenis dokumen
  await go(A, 'bl-jenis'); await A.waitForTimeout(500);
  ok(/Jenis dokumen/.test(await txt(A)) && (await A.inputValue('[data-jenis=format]')) === 'LAP/{urut}/Labkesda/{tahun}' && (await A.inputValue('[data-jenis=next]')) === '2', 'Admin › Jenis dokumen: format & nomor berikutnya');
  await A.fill('[data-jenis=next]', '778'); await A.press('[data-jenis=next]', 'Tab'); await A.waitForTimeout(500);
  ok(!(await api(P, 'bl_jenis_save', { k: 'next', v: 5 })).ok && !(await api(A, 'bl_jenis_save', { k: 'format', v: 'TANPA-URUT' })).ok, 'Jenis dokumen hanya Admin; format wajib memuat {urut}');
  // Ganti (dengan pembaruan)
  await go(P, 'bl-laporan'); await go(P, 'bl-laporan-l' + lapId); await P.waitForTimeout(600);
  await click(P, '[data-act=blLapGanti]'); await P.fill('#dkWhy', 'pendek'); ok(await P.isDisabled('#dkOK'), 'Alasan ganti minimal 10 karakter');
  await P.fill('#dkWhy', 'Perbaikan data hasil pelatihan.'); await click(P, '#dkOK'); await P.waitForTimeout(700);
  ok(/Draf pengganti dibuat; dokumen lama tetap berlaku sampai pengganti disahkan\./.test(await anyToast(P)) && (await vd(L1.dokInfo.kode)).status === 'sah', 'Ganti: draf pengganti dibuat, dokumen lama tetap sah');
  ok((await api(FA, 'bl_lap_bab', { kegId: +kid, op: 'isi', bab: 3, sub: '', html: '<p>Kegiatan berjalan sangat baik.</p>' })).ok, 'Fasilitator menyunting draf pengganti');
  await api(FA, 'bl_lap_terbit', { kegId: +kid });
  ok((await api(P, 'bl_lap_sahkan', { lapId })).ok, 'Pengganti disahkan');
  const Lg = (await api(P, 'bl_lap_get', { lapId })).r;
  const vOld = await vd(L1.dokInfo.kode), vNew = await vd(Lg.dokInfo.kode);
  ok(Lg.dokInfo.nomor === 'LAP/778/Labkesda/' + th && Lg.dokInfo.revisi === 1 && vNew.status === 'sah', 'Dokumen pengganti: nomor LAP/778 (nomor berikutnya dari Admin), revisi 1');
  ok(vOld.status === 'digantikan' && vOld.pengganti === 'LAP/778/Labkesda/' + th, 'VeriDoc: dokumen lama DIGANTIKAN + nomor pengganti');
  // masa berlaku habis dihitung dari tanggal
  dbq(`UPDATE bl_dok SET berlaku_sampai='2020-01-01' WHERE id=${Lg.dokInfo.id}`);
  ok((await api(P, 'bl_lap_get', { lapId })).r.tampil === 'habis', 'Status Masa berlaku habis dihitung dari tanggal');
  dbq(`UPDATE bl_dok SET berlaku_sampai='2099-12-31' WHERE id=${Lg.dokInfo.id}`);

  // ---- Admin: kelola akun
  await go(A, 'bl-pengguna'); await A.waitForTimeout(300);
  const vals = await A.evaluate(() => [...document.querySelectorAll('[data-bluser]')].map(i => i.value).join(' '));
  ok(/ani@contoh\.id/.test(vals) && /Puskesmas Baru Ilir/.test(vals), 'Daftar pengguna menampilkan akun peserta beserta email & instansi');
  const bud = await A.evaluate(() => Core.S.users.find(u => u.username === 'budi').id);
  await A.uncheck(`[data-act=blUserActive][data-id="${bud}"]`); await A.waitForTimeout(500);
  await login(X, 'budi', 'PesertaBudi123'); ok(/salah/.test(await txt(X)), 'Akun dinonaktifkan Admin tidak bisa masuk');
  await click(A, `[data-act=blUserReset][data-id="${await A.evaluate(() => Core.S.users.find(u => u.username === 'dedi').id)}"]`); await click(A, '#cbOK');
  const pwD = await A.evaluate(() => document.querySelectorAll('.modal code')[1].textContent); await A.click('.modal [data-close]');
  await login(X, 'dedi', pwD); ok(/ganti kata sandi sementara/.test(await txt(X)), 'Reset sandi oleh Admin → wajib ganti saat masuk');
  ok(!(await api(S1, 'bl_user_active', { id: bud, v: true })).ok, 'Selain Admin tidak dapat mengelola akun');
  // arsip
  await api(A, 'bl_keg_status', { id: +kid, v: 'arsip' });
  const X2 = await newPage(); await login(X2, 'fasil1', 'Fasilitator1Baru'); ok(/salah/.test(await txt(X2)), 'Kegiatan diarsipkan → akun Fasilitator dinonaktifkan');
  ok((await api(S2, 'bl_get', { id: +kid })).r.result.mine.post === 80 && (await api(W, 'bl_get', { id: +kid })).ok, 'Peserta & Pengawas tetap dapat melihat hasil setelah diarsipkan');
  // Setelah arsip: Admin menyunting draf pengganti; Cabut tanpa VeriDoc → antrean dikirim ulang
  const lapId2 = (await api(A, 'bl_lap_list', {})).r.find(x => x.title === 'Pelatihan QC Westgard 2026').lapId;
  ok((await api(A, 'bl_lap_ganti', { lapId: lapId2, alasan: 'Koreksi setelah kegiatan diarsipkan.' })).ok, 'Admin: Ganti setelah arsip');
  await go(A, 'bl-laporan'); await go(A, 'bl-laporan-l' + lapId2); await A.waitForTimeout(600);
  ok((await A.locator('.rt-ed').count()) > 0 && !(await api(P, 'bl_lap_bab', { lapId: lapId2, op: 'isi', bab: 3, sub: '', html: 'x' })).ok, 'Draf pengganti setelah arsip disunting Admin (Pembina tidak)');
  await A.locator('.rt[data-rtb="3"]').nth(0).locator('.rt-ed').click(); await A.keyboard.press('End'); await A.keyboard.type(' Perlu pelatihan lanjutan tahun depan.'); await A.locator('[data-lap=judulLaporan]').click(); await A.waitForTimeout(700);
  ok((await api(A, 'bl_lap_terbit', { lapId: lapId2 })).ok && (await api(P, 'bl_lap_sahkan', { lapId: lapId2 })).ok, 'Admin menerbitkan, Pembina mengesahkan pengganti');
  const L3 = (await api(A, 'bl_lap_get', { lapId: lapId2 })).r;
  try { require('child_process').execFileSync('pkill', ['-f', 'portal/server.mjs']); } catch (e) {} await new Promise(r => setTimeout(r, 300));
  await go(A, 'bl-laporan'); await go(A, 'bl-laporan-l' + lapId2); await A.waitForTimeout(600);
  await click(A, '[data-act=blLapCabut]'); await A.fill('#dkWhy', 'Dokumen tidak lagi diperlukan.'); await click(A, '#dkOK'); await A.waitForTimeout(1500);
  let L4 = (await api(A, 'bl_lap_get', { lapId: lapId2 })).r;
  ok(/Dokumen dicabut\./.test(await anyToast(A)) && L4.tampil === 'dicabut' && L4.dokInfo.sync === 'tunda', 'Cabut saat VeriDoc tidak terjangkau: status dicabut, masuk antrean');
  require('child_process').spawn(process.execPath, ['portal/server.mjs'], { cwd: '/home/claude/sicinta-lhu', env: { ...process.env, SICINTA_DATA_DIR: '/tmp/vdtest', PORT: '3912', LHU_PRIVATE_ORIGIN: 'http://127.0.0.1:9' }, detached: true, stdio: 'ignore' }).unref();
  await new Promise(r => setTimeout(r, 900));
  dbq(`UPDATE settings SET v='0' WHERE k='veridoc_coba'`);
  await api(A, 'bl_lap_list', {});
  L4 = (await api(A, 'bl_lap_get', { lapId: lapId2 })).r;
  const v4 = await vd(L3.dokInfo.kode);
  ok(L4.dokInfo.sync === 'ok' && v4.status === 'dicabut' && v4.sejak === L4.today, 'Antrean terkirim ulang: VeriDoc menampilkan DICABUT');
  ok(!(await api(A, 'bl_lap_ganti', { lapId: lapId2, alasan: 'Tidak boleh setelah dicabut.' })).ok, 'Dokumen dicabut tidak dapat diganti/dicabut lagi');

  // ---- jumlah soal 10–25: nilai = benar ÷ jumlah soal × 100
  const k2 = (await api(A, 'bl_keg_add', { title: 'Kegiatan 12 soal', org: 'Labkesda', kuota: '5' })).r.id;
  let d2 = (await api(A, 'bl_get', { id: k2 })).r; const pre2 = d2.segs[0], post2 = d2.segs.find(s => s.jenis === 'posttest');
  for (let i = 0; i < 9; i++) await api(P, 'bl_soal_save', { kegId: k2, text: 'S' + i, oa: 'A', ob: 'B', oc: 'C', od: 'D', key: 'abcd'[i % 4] });
  await api(A, 'bl_keg_status', { id: k2, v: 'terbit' }); await api(A, 'bl_keg_daftar', { id: k2, v: true });
  ok(/10–25 soal/.test((await api(A, 'bl_seg_state', { id: pre2.id, v: 'buka' })).e || ''), 'Pretest dengan 9 soal tidak dapat dibuka (minimal 10)');
  for (let i = 9; i < 12; i++) await api(P, 'bl_soal_save', { kegId: k2, text: 'S' + i, oa: 'A', ob: 'B', oc: 'C', od: 'D', key: 'abcd'[i % 4] });
  ok((await reg(SE, { kegId: k2, name: 'Eka', jabatan: 'ATLM', nip: '1', instansi: 'Puskesmas Damai', email: 'eka@contoh.id', hp: '081300000002' })) === 'ok', 'Peserta mendaftar kegiatan 12 soal');
  d2 = (await api(A, 'bl_get', { id: k2 })).r; await api(A, 'bl_verif', { id: d2.peserta[0].id, v: 'diterima' });
  ok((await api(A, 'bl_seg_state', { id: pre2.id, v: 'buka' })).ok, 'Pretest dengan 12 soal dapat dibuka');
  const kunci2 = {}; d2.soal.forEach(q => kunci2[q.id] = q.key);
  const q2 = (await api(SE, 'bl_get', { id: k2 })).r.tests[pre2.id].soal;
  for (let i = 0; i < 12; i++) await api(SE, 'bl_jawab', { segId: pre2.id, soalId: q2[i].id, v: i < 9 ? kunci2[q2[i].id] : ({ a: 'b', b: 'c', c: 'd', d: 'a' })[kunci2[q2[i].id]] });
  await api(SE, 'bl_selesai', { segId: pre2.id }); await api(A, 'bl_seg_state', { id: pre2.id, v: 'tutup' });
  await api(A, 'bl_seg_state', { id: post2.id, v: 'buka' });
  for (const q of q2) await api(SE, 'bl_jawab', { segId: post2.id, soalId: q.id, v: kunci2[q.id] });
  await api(SE, 'bl_selesai', { segId: post2.id });
  const rr = (await api(A, 'bl_get', { id: k2 })).r.scores.rows[0];
  ok(rr.pre === 75 && rr.post === 100, '12 soal: 9 benar = 75, semua benar = 100');
  ok((await api(A, 'bl_get', { id: +kid })).r.scores.rows.find(r => r.name === 'Ani Lestari, A.Md.AK').post === 88, 'Kegiatan lama (25 soal) tidak berubah: Ani tetap 88');
  // ---- Segmen yang sudah ditutup tetap dapat diselesaikan peserta (sampai arsip) + kartu nilai
  const k3 = (await api(A, 'bl_keg_add', { title: 'Kegiatan susulan', org: 'Labkesda', kuota: '5' })).r.id;
  let d4 = (await api(A, 'bl_get', { id: k3 })).r; const pre3 = d4.segs[0], post3 = d4.segs.find(x => x.jenis === 'posttest'), mat3 = d4.segs[1];
  for (let i = 0; i < 10; i++) await api(P, 'bl_soal_save', { kegId: k3, text: 'T' + i, oa: 'A', ob: 'B', oc: 'C', od: 'D', key: 'abcd'[i % 4] });
  await api(A, 'bl_keg_status', { id: k3, v: 'terbit' }); await api(A, 'bl_keg_daftar', { id: k3, v: true });
  for (const [pg, nm] of [[S1, 'Ani'], [S2, 'Cici']]) await reg(pg, { kegId: k3, name: nm, jabatan: 'ATLM', nip: '9', instansi: 'Instansi ' + nm, email: nm.toLowerCase() + '3@contoh.id', hp: '081211113333' });
  d4 = (await api(A, 'bl_get', { id: k3 })).r; for (const x of d4.peserta) await api(A, 'bl_verif', { id: x.id, v: 'diterima' });
  ok(/masih dapat menyelesaikannya sampai kegiatan diarsipkan/.test(await A.evaluate(() => Core.t('bl.konfirm_tutup'))) && (await A.evaluate(() => Core.t('bl.segmen_ditutup_ok'))) === 'Segmen ditutup.', 'Teks konfirmasi & notifikasi tutup segmen diperbarui');
  await api(A, 'bl_seg_state', { id: pre3.id, v: 'buka' }); await api(A, 'bl_seg_state', { id: pre3.id, v: 'tutup' }); await api(A, 'bl_seg_state', { id: post3.id, v: 'buka' });
  const kunci3 = {}; (await api(A, 'bl_get', { id: k3 })).r.soal.forEach(q => kunci3[q.id] = q.key);
  await go(S1, 'bl-' + k3); await S1.waitForTimeout(400);
  ok(await S1.locator(`a[href="#bl-${k3}-s${pre3.id}"].btn`).count() === 1 && /Ditutup/.test(await txt(S1)), 'Pretest yang sudah ditutup tetap dapat dimulai peserta');
  await go(S1, `bl-${k3}-s${pre3.id}`); await S1.waitForTimeout(400);
  ok(/Segmen ini sudah ditutup fasilitator. Anda masih dapat menyelesaikannya sampai kegiatan diarsipkan./.test(await txt(S1)), 'Catatan segmen ditutup tampil');
  const q3 = (await api(S1, 'bl_get', { id: k3 })).r.tests[pre3.id].soal;
  for (let i = 0; i < 10; i++) await api(S1, 'bl_jawab', { segId: pre3.id, soalId: q3[i].id, v: i < 6 ? kunci3[q3[i].id] : ({ a: 'b', b: 'c', c: 'd', d: 'a' })[kunci3[q3[i].id]] });
  await go(S1, `bl-${k3}`); await go(S1, `bl-${k3}-s${pre3.id}`); await S1.waitForTimeout(400);
  await click(S1, `[data-act=blSelesai][data-id="${pre3.id}"]`); await click(S1, '#cbOK'); await S1.waitForTimeout(700);
  const kn = await S1.evaluate(() => (document.querySelector('#blNilaiTes') || {}).innerText || '');
  ok(/Nilai Pretest Anda/.test(kn) && /60/.test(kn) && /6 dari 10 soal benar/.test(kn) && (await S1.evaluate(() => location.hash)) === `#bl-${k3}-s${pre3.id}`, 'Kartu nilai Pretest tampil segera setelah selesai (60 · 6 dari 10)');
  ok((await api(A, 'bl_get', { id: k3 })).r.scores.rows.find(r => r.name === 'Ani').pre === 60, 'Nilai Pretest susulan masuk rekap');
  const q3p = (await api(S1, 'bl_get', { id: k3 })).r.tests[post3.id].soal;
  for (const q of q3p) await api(S1, 'bl_jawab', { segId: post3.id, soalId: q.id, v: kunci3[q.id] });
  await go(S1, `bl-${k3}-s${post3.id}`); await S1.waitForTimeout(400);
  await click(S1, `[data-act=blSelesai][data-id="${post3.id}"]`); await click(S1, '#cbOK'); await S1.waitForTimeout(700);
  ok(/Nilai Post test Anda[\s\S]*100[\s\S]*10 dari 10 soal benar/.test(await S1.evaluate(() => document.querySelector('#blNilaiTes').innerText)), 'Kartu nilai Post test tampil (100 · 10 dari 10)');
  const own = (await api(S1, 'bl_get', { id: k3 })).r;
  ok(Object.keys(own.myTest).length === 2 && !JSON.stringify(own.myTest).includes('Cici') && !own.scores, 'Kartu nilai hanya berisi nilai akun itu sendiri');
  await go(S1, 'bl-' + k3); await S1.waitForTimeout(300); await click(S1, `a[href="#bl-${k3}-s${pre3.id}"]`); await S1.waitForTimeout(400);
  ok(/Nilai Pretest Anda/.test(await txt(S1)), 'Kartu nilai dapat dibuka lagi dari segmen yang selesai');
  // Pretest tidak boleh setelah Post test selesai
  for (const q of q3p) await api(S2, 'bl_jawab', { segId: post3.id, soalId: q.id, v: kunci3[q.id] });
  await api(S2, 'bl_selesai', { segId: post3.id });
  const c3 = (await api(S2, 'bl_get', { id: k3 })).r;
  ok(c3.preBlocked && !c3.tests[pre3.id] && /sudah menyelesaikan Post test/.test((await api(S2, 'bl_jawab', { segId: pre3.id, soalId: q3[0].id, v: 'a' })).e) && /sudah menyelesaikan Post test/.test((await api(S2, 'bl_selesai', { segId: pre3.id })).e), 'Pretest ditolak bila Post test sudah selesai');
  await go(S2, 'bl-' + k3); await S2.waitForTimeout(400);
  ok(!(await S2.locator(`a.btn[href="#bl-${k3}-s${pre3.id}"]`).count()), 'Tombol mulai Pretest tidak tampil bagi peserta itu');
  await go(S2, `bl-${k3}-s${pre3.id}`); await S2.waitForTimeout(300); ok(/Pretest tidak dapat dikerjakan karena Anda sudah menyelesaikan Post test./.test(await txt(S2)), 'Halaman Pretest menampilkan alasan');
  // segmen ditutup: tetap bisa sampai arsip, setelah arsip tidak
  await api(A, 'bl_seg_state', { id: mat3.id, v: 'buka' }); await api(A, 'bl_seg_state', { id: mat3.id, v: 'tutup' });
  ok((await api(S1, 'bl_selesai', { segId: mat3.id })).ok, 'Segmen materi yang sudah ditutup dapat diselesaikan');
  await api(A, 'bl_keg_status', { id: k3, v: 'arsip' });
  ok(/diarsipkan/.test((await api(S2, 'bl_selesai', { segId: mat3.id })).e || ''), 'Setelah kegiatan diarsipkan, segmen tidak dapat diselesaikan');

  // ---- Admin: hapus peserta (alasan wajib, audit trail)
  const aniId = await A.evaluate(() => Core.S.users.find(u => u.username === 'ani').id);
  ok(/diarsipkan sehingga tidak dapat dihapus/.test((await api(A, 'bl_user_del', { id: aniId, alasan: 'Uji penghapusan akun arsip' })).e || ''), 'Akun yang tercatat di kegiatan arsip tidak dapat dihapus');
  await go(A, 'bl-' + kid); await click(A, '[data-act=blTab][data-v=peserta]');
  ok(await A.locator('[data-act=blPesDel]').count() === 0, 'Kegiatan arsip: tombol Hapus dari kegiatan tidak tampil');
  const ekaPid = (await api(A, 'bl_get', { id: k2 })).r.peserta[0].id;
  ok(/minimal 10 karakter/.test((await api(A, 'bl_pes_del', { id: ekaPid, alasan: 'singkat' })).e || ''), 'Alasan kurang dari 10 karakter ditolak');
  ok(!(await api(P, 'bl_pes_del', { id: ekaPid, alasan: 'Bukan admin mencoba hapus' })).ok && !(await api(P, 'bl_audit_list', {})).ok, 'Selain Admin tidak dapat menghapus peserta / membuka audit');
  await go(A, 'bl-' + k2); await click(A, '[data-act=blTab][data-v=peserta]');
  await click(A, `[data-act=blPesDel][data-id="${ekaPid}"]`);
  ok(/akan dihapus permanen/.test(await A.evaluate(() => document.querySelector('.modal').innerText)) && await A.isDisabled('#delOK'), 'Modal hapus: keterangan tampil, tombol nonaktif sebelum alasan diisi');
  await A.fill('#delWhy', 'abc'); ok(await A.isDisabled('#delOK'), 'Alasan 3 karakter: tombol tetap nonaktif');
  await A.fill('#delWhy', 'Peserta salah memilih kegiatan'); ok(!(await A.isDisabled('#delOK')), 'Alasan cukup: tombol aktif');
  await click(A, '#delOK'); await A.waitForTimeout(300);
  let d3 = (await api(A, 'bl_get', { id: k2 })).r;
  ok(/Peserta sudah dihapus dari kegiatan/.test(await anyToast(A)) && d3.peserta.length === 0 && d3.keg.terisi === 0, 'Peserta terhapus dari kegiatan; kuota kembali kosong');
  ok(dbq(`SELECT (SELECT COUNT(*) FROM bl_jawab WHERE peserta_id=${ekaPid})+(SELECT COUNT(*) FROM bl_selesai WHERE peserta_id=${ekaPid})+(SELECT COUNT(*) FROM bl_nilai WHERE peserta_id=${ekaPid})+(SELECT COUNT(*) FROM bl_eval WHERE peserta_id=${ekaPid})`) === '0', 'Jawaban, penyelesaian, nilai, evaluasi peserta di kegiatan itu ikut terhapus');
  ok((await api(SE, 'bl_riwayat', {})).ok && !(await api(SE, 'bl_riwayat', {})).r.some(x => x.kegId === k2), 'Akun peserta tetap ada; kegiatan hilang dari Riwayat saya');
  ok((await reg(SE, { kegId: k2, name: 'Eka', jabatan: 'ATLM', nip: '1', instansi: 'Puskesmas Damai', email: 'eka@contoh.id', hp: '081300000002' })) === 'ok', 'Peserta dapat mendaftar ulang setelah dihapus dari kegiatan');
  const suratAda = () => fs.readdirSync('/tmp/sp/data/surat').length;
  const nSurat = suratAda();
  const ekaId = await A.evaluate(() => Core.S.users.find(u => u.username === 'eka').id);
  await go(A, 'bl-pengguna'); await A.waitForTimeout(300);
  ok(await A.locator('[data-act=blUserDel]').count() === (await A.evaluate(() => Core.S.users.filter(u => u.role === 'peserta').length)), 'Tombol Hapus akun hanya pada akun Peserta');
  ok(!(await api(A, 'bl_user_del', { id: await A.evaluate(() => Core.S.users.find(u => u.role === 'pembina').id), alasan: 'Mencoba hapus akun pembina' })).ok, 'Akun selain Peserta tidak dapat dihapus');
  await click(A, `[data-act=blUserDel][data-id="${ekaId}"]`);
  ok(/semua kegiatan/.test(await A.evaluate(() => document.querySelector('.modal').innerText)), 'Modal hapus akun tampil');
  await A.fill('#delWhy', 'Akun ganda, peserta memakai akun lain'); await click(A, '#delOK'); await A.waitForTimeout(300);
  ok(/Akun peserta sudah dihapus/.test(await anyToast(A)) && !(await A.evaluate(() => Core.S.users.some(u => u.username === 'eka'))), 'Akun peserta terhapus dari daftar pengguna');
  ok((await api(A, 'bl_get', { id: k2 })).r.peserta.length === 0 && suratAda() === nSurat - 1, 'Pendaftaran & surat PDF ikut terhapus');
  ok(dbq(`SELECT COUNT(*) FROM bl_users WHERE id=${ekaId}`) === '0' && dbq(`SELECT COUNT(*) FROM bl_audit WHERE action='bl_hapus_akun'`) === '1', 'Baris akun terhapus dari basis data; audit tercatat');
  ok(/sesi/i.test((await api(SE, 'bl_riwayat', {})).e || ''), 'Sesi akun yang dihapus berakhir');
  const X3 = await newPage(); await login(X3, 'eka', 'PesertaEka123'); ok(/salah/.test(await txt(X3)), 'Akun yang dihapus tidak bisa masuk');
  await go(A, 'bl-audit'); await A.waitForTimeout(500);
  const au = await A.evaluate(() => document.querySelector('.content').innerText);
  ok(/Audit trail/.test(au) && /Akun ganda, peserta memakai akun lain/.test(au) && /Peserta salah memilih kegiatan/.test(au) && /Hapus akun/.test(au) && /Hapus dari kegiatan/.test(au) && /eka@contoh\.id/.test(au) && /Kegiatan 12 soal/.test(au), 'Audit trail menampilkan tindakan, peserta, kegiatan, alasan');
  ok((await A.evaluate(() => document.querySelector('.nav a.on').getAttribute('href'))) === '#bl-audit', 'Menu Audit trail aktif');
  const al = (await api(A, 'bl_audit_list', {})).r;
  ok(al.length === 2 && al[0].jenis === 'akun' && al[0].oleh && al[1].keg[0].status === 'diterima', 'Catatan audit lengkap (urut terbaru, admin, status pendaftaran)');

  // watermark
  const wm = 'Si PANDU © 2026 Adi Darma Aprianda, S.Tr.Kes, M.H.';
  const L = await newPage(); await L.goto(BL + '/'); const L2 = await newPage(); await L2.goto(BL + '/?daftar=1');
  const wmc = async p => p.evaluate(() => { const w = document.querySelector('.hak-cipta'); return w ? w.textContent : null; });
  ok([await wmc(L), await wmc(L2), await wmc(A), await wmc(W)].every(x => x === wm), 'Watermark hak cipta di halaman masuk, buat akun, dan aplikasi subdomain');
  console.log(errors.length ? 'GALAT JS:\n' + errors.join('\n') : 'Tanpa galat JavaScript');
  console.log(`pemeriksaan: ${n}, gagal: ${fail}`);
  await browser.close(); process.exit(fail || errors.length ? 1 : 0);
})();
