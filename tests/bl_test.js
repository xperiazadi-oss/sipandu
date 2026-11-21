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
  ok((await A.evaluate(() => [...document.querySelectorAll('.nav a')].map(a => a.getAttribute('href')).join())) === '#bl,#bl-pengguna', 'Menu Admin subdomain: Kegiatan, Pengguna');
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
  ok((await FA.evaluate(() => [...document.querySelectorAll('.nav a')].map(a => a.getAttribute('href')).join())) === '#bl,#bl-profil', 'Menu Fasilitator: Kegiatan, Profil narasumber');
  const FB = await newPage(); await firstLogin(FB, 'fasil2', uF2.password, 'Fasilitator2Baru');
  ok((await FB.evaluate(() => Core.S.bl.length)) === 0 && !(await api(FB, 'bl_get', { id: +kid })).ok, 'Fasilitator yang tidak ditugaskan tidak melihat kegiatan');
  await go(FA, 'bl-profil'); await FA.waitForTimeout(400); await FA.fill('#bpJ', 'Ahli Teknologi Laboratorium Medik'); await FA.fill('#bpI', 'Labkesda Balikpapan'); await click(FA, '[data-act=blProfilSave]');
  await FA.setInputFiles('#blFoto', F.foto); await FA.waitForTimeout(800);
  ok((await api(FA, 'bl_get_profil', {})).r.foto, 'Profil & foto narasumber tersimpan');
  await go(FA, 'bl-' + kid); await FA.waitForTimeout(400); await click(FA, '[data-act=blTab][data-v=soal]');
  await FA.fill('#sqT', 'Aturan 1-3s berarti?'); for (const x of ['a', 'b', 'c', 'd']) await FA.fill('#sq' + x, 'Pilihan ' + x.toUpperCase()); await FA.selectOption('#sqK', 'c'); await click(FA, '[data-act=blSoalSave]');
  ok(/1 dari 25/.test(await txt(FA)), 'Soal ditambahkan lewat formulir');
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

  // watermark
  const wm = 'Si PANDU © 2026 Adi Darma Aprianda, S.Tr.Kes, M.H.';
  const L = await newPage(); await L.goto(BL + '/'); const L2 = await newPage(); await L2.goto(BL + '/?daftar=1');
  const wmc = async p => p.evaluate(() => { const w = document.querySelector('.hak-cipta'); return w ? w.textContent : null; });
  ok([await wmc(L), await wmc(L2), await wmc(A), await wmc(W)].every(x => x === wm), 'Watermark hak cipta di halaman masuk, buat akun, dan aplikasi subdomain');
  console.log(errors.length ? 'GALAT JS:\n' + errors.join('\n') : 'Tanpa galat JavaScript');
  console.log(`pemeriksaan: ${n}, gagal: ${fail}`);
  await browser.close(); process.exit(fail || errors.length ? 1 : 0);
})();
