// Uji integrasi modul Peningkatan Kompetensi SDM. Dijalankan SETELAH tests/e2e_server.js pada basis data yang sama.
const { chromium } = require('/opt/npm-tools/node_modules/playwright');
const fs = require('fs'), os = require('os'), path = require('path');
const BASE = process.env.BASE || 'http://127.0.0.1:8099';
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
  const newPage = async () => { const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 }, acceptDownloads: true }); const p = await ctx.newPage(); p.on('pageerror', e => errors.push(e.message)); return p; };
  const txt = p => p.evaluate(() => document.body.innerText);
  const go = async (p, h) => { await p.evaluate(h => location.hash = h, h); await p.waitForTimeout(350); };
  const api = (p, a, d) => p.evaluate(([a, d]) => Core.api(a, d).then(r => ({ ok: true, r }), e => ({ ok: false, e: e.message })), [a, d]);
  const login = async (p, u, pw) => { await p.goto(BASE + '/'); await p.fill('[name=username]', u); await p.fill('[name=password]', pw); await p.click('button[type=submit]'); await p.waitForLoadState(); await p.waitForTimeout(300); };
  const firstLogin = async (p, u, pw, npw) => { await login(p, u, pw); ok(/ganti kata sandi sementara/.test(await txt(p)), u + ': wajib ganti sandi saat masuk pertama'); await p.fill('[name=old]', pw); await p.fill('[name=new]', npw); await p.fill('[name=new2]', npw); await p.click('button[type=submit]'); await p.waitForLoadState(); await p.waitForTimeout(400); };
  const status = (p, url) => p.evaluate(u => fetch(u).then(r => r.status), url);
  const click = async (p, sel) => { await p.click(sel); await p.waitForTimeout(450); };

  // ---- Admin: nama Puskesmas, menu "Masih dikembangkan", akun Fasilitator
  const A = await newPage(); await login(A, 'admin', 'AdminKuat2026');
  const pk = await A.evaluate(() => Core.S.pkms.slice(0, 3).map(p => p.name));
  ok(pk[0] === 'Puskesmas Baru Ilir' && pk[1] === 'Puskesmas Baru Tengah' && pk[2] === 'Puskesmas Baru Ulu', 'Nama Puskesmas resmi (urut abjad): ' + pk.join(', '));
  ok((await A.evaluate(() => Core.S.pkms.length)) >= 27 && (await A.evaluate(() => Core.S.pkms.find(p => p.kode === 'P27').name)) === 'Puskesmas Teritip', 'P27 = Puskesmas Teritip');
  ok(!/Tahap 2|Tahap 3/.test(await A.evaluate(() => document.querySelector('.nav').innerText)), 'Label "Tahap 2/3" tidak tampil di menu');
  const dev = A.locator('.nav a.dev').first();
  ok((await dev.getAttribute('data-tip')) === 'Masih dikembangkan', 'Menu belum jadi: keterangan "Masih dikembangkan"');
  await dev.hover(); const tip = await A.evaluate(() => { const a = document.querySelector('.nav a.dev:hover'); return a ? getComputedStyle(a, '::after').content : ''; });
  ok(/Masih dikembangkan/.test(tip), 'Kursor diarahkan → pesan "Masih dikembangkan" muncul');
  const h0 = await A.evaluate(() => location.hash); await dev.click(); await A.waitForTimeout(250);
  ok((await A.evaluate(() => location.hash)) === h0 && /Masih dikembangkan/.test(await A.evaluate(() => (document.querySelector('.toast.bad') || {}).textContent || '')), 'Diklik/disentuh → tidak pindah halaman, pesan muncul');
  await go(A, 'pengguna'); ok(await A.locator('#nuRole option[value=fasil]').count() === 1, 'Admin dapat memilih peran Fasilitator');
  const fa = await api(A, 'user_add', { role: 'fasil', name: 'Dr. Rina Fasilitator', username: 'fasil1' });
  const fb = await api(A, 'user_add', { role: 'fasil', name: 'Fasilitator Lain', username: 'fasil2' });
  ok(fa.ok && fb.ok, 'Akun Fasilitator dibuat');

  // ---- Admin: kegiatan & segmen
  await go(A, 'bl'); await A.fill('#bkT', 'Pelatihan QC Westgard 2026'); await A.fill('#bkO', 'Labkesda Balikpapan'); await click(A, '[data-act=blKegAdd]');
  const kid = await A.evaluate(() => location.hash.split('-')[1]);
  ok(/Pelatihan QC Westgard 2026/.test(await txt(A)) && kid, 'Kegiatan dibuat (draf) dengan segmen wajib');
  let d = (await api(A, 'bl_get', { id: +kid })).r;
  ok(d.segs.map(s => s.jenis).join() === 'pretest,materi,posttest,penutup', 'Segmen awal: Pretest, Materi 1, Post test, Penutup');
  await click(A, '[data-act=blSegAdd][data-v=praktik]'); await click(A, '[data-act=blSegAdd][data-v=materi]');
  d = (await api(A, 'bl_get', { id: +kid })).r;
  ok(d.segs.map(s => s.jenis).join() === 'pretest,materi,praktik,materi,posttest,penutup', 'Segmen ditambahkan sebelum Post test');
  const prak = d.segs.find(s => s.jenis === 'praktik'), m1 = d.segs[1], m2 = d.segs[3];
  await click(A, `[data-act=blSegMove][data-id="${prak.id}"][data-v="1"]`);
  d = (await api(A, 'bl_get', { id: +kid })).r; ok(d.segs.map(s => s.jenis).join() === 'pretest,materi,materi,praktik,posttest,penutup', 'Praktik dapat dipindah ke setelah materi terakhir');
  const bad = await api(A, 'bl_seg_move', { id: m1.id, dir: -1 }); ok(!bad.ok && /Urutan/.test(bad.e), 'Urutan yang melanggar (Materi sebelum Pretest) ditolak');
  const bad2 = await api(A, 'bl_seg_move', { id: prak.id, dir: -1 }); await api(A, 'bl_seg_move', { id: prak.id, dir: -1 });
  d = (await api(A, 'bl_get', { id: +kid })).r; const bad3 = await api(A, 'bl_seg_move', { id: prak.id, dir: -1 });
  ok(bad2.ok && d.segs[2].jenis === 'praktik' && !bad3.ok, 'Praktik boleh setelah materi mana pun, tidak boleh sebelum materi pertama');
  await api(A, 'bl_seg_move', { id: prak.id, dir: 1 }); await api(A, 'bl_seg_move', { id: prak.id, dir: 1 });
  const fid = fa.r.id, fid2 = fb.r.id, pemb1 = await A.evaluate(() => Core.S.users.find(u => u.username === 'pembina1').id);
  await A.reload(); await A.waitForTimeout(400);
  await A.check(`[data-blpeople=fasil][value="${fid}"]`); await A.waitForTimeout(450);
  await A.check(`[data-blpeople=pembuka][value="${pemb1}"]`); await A.waitForTimeout(450);
  await A.selectOption(`[data-blseg="${m1.id}"][data-k=picId]`, String(fid)); await A.waitForTimeout(450);
  await A.fill(`[data-blseg="${m1.id}"][data-k=date]`, '2026-12-01'); await A.press(`[data-blseg="${m1.id}"][data-k=date]`, 'Tab'); await A.waitForTimeout(400);
  await A.fill(`[data-blseg="${m2.id}"][data-k=title]`, 'Materi 2: Manajemen Risiko'); await A.press(`[data-blseg="${m2.id}"][data-k=title]`, 'Tab'); await A.waitForTimeout(400);
  await api(A, 'bl_seg_sesi', { id: m1.id, sesi: [{ title: 'Aturan Westgard', date: '2026-12-01', from: '08:00', to: '10:00', pic: 'Dr. Rina' }, { title: 'Grafik Levey-Jennings', date: '2026-12-01', from: '10:15', to: '12:00' }, { title: '' }] });
  d = (await api(A, 'bl_get', { id: +kid })).r;
  ok(d.keg.fasil.includes(fid) && d.keg.pembuka.includes(pemb1) && d.segs[1].picId === fid && d.segs[1].date === '2026-12-01' && d.segs[2].title === 'Materi 2: Manajemen Risiko' && d.segs[1].sesi.length === 2, 'Fasilitator, pembuka segmen, PIC, jadwal, judul, dan sesi tersimpan');
  ok(!(await api(A, 'bl_seg_update', { id: m1.id, k: 'from', v: '25:00' })).ok, 'Format jam salah ditolak');
  const early = await api(A, 'bl_seg_state', { id: d.segs[0].id, v: 'buka' }); ok(!early.ok, 'Segmen tidak dapat dibuka sebelum kegiatan terbit');

  // ---- Sub-akun & draf
  const B = await newPage(); await login(B, 'pkm01', 'Puskesmas01Baru');
  const ani = await B.evaluate(() => Core.S.personel.find(x => x.name === 'Ani ATLM').id);
  ok((await B.evaluate(id => Core.byId(Core.S.personel, id).subUser, ani)) === 'pkm01.ani', 'Personel yang sudah disimpan memiliki sub-akun pkm01.ani');
  const rsA = await api(B, 'bl_reset_sub', { personelId: ani });
  const S1 = await newPage(); await firstLogin(S1, 'pkm01.ani', rsA.r.password, 'PesertaAni123');
  ok((await S1.evaluate(() => Core.S.bl.length)) === 0 && !(await api(S1, 'bl_get', { id: +kid })).ok && (await B.evaluate(() => Core.S.bl.length)) === 0, 'Kegiatan draf tidak terlihat oleh sub-akun maupun akun Puskesmas');
  const P = await newPage(); await login(P, 'pembina1', 'Pembina1Baru');
  ok((await P.evaluate(() => Core.S.personel.filter(x => x.pkmId === 0).every(x => !x.subUser))), 'Personel Labkesmas (akun Pembina) tidak mendapat sub-akun');

  // ---- Fasilitator
  const FA = await newPage(); await firstLogin(FA, 'fasil1', fa.r.password, 'Fasilitator1Baru');
  const nav = await FA.evaluate(() => [...document.querySelectorAll('.nav a')].map(a => a.getAttribute('href')));
  ok(nav.join() === '#bl,#bl-profil', 'Fasilitator hanya melihat menu Pembelajaran & profil');
  ok(!(await api(FA, 'sheet_get', { id: 1 })).ok && !(await api(FA, 'sdm_add', { name: 'x' })).ok && (await FA.evaluate(() => Core.S.sheets.length + Core.S.personel.length + Core.S.alats.length)) === 0, 'Fasilitator tidak dapat mengakses data QC/Sumber Daya');
  const FB = await newPage(); await firstLogin(FB, 'fasil2', fb.r.password, 'Fasilitator2Baru');
  ok((await FB.evaluate(() => Core.S.bl.length)) === 0 && !(await api(FB, 'bl_get', { id: +kid })).ok, 'Fasilitator yang tidak ditugaskan tidak melihat kegiatan');
  await go(FA, 'bl-profil'); await FA.waitForTimeout(400); await FA.fill('#bpJ', 'Ahli Teknologi Laboratorium Medik'); await FA.fill('#bpI', 'Labkesda Balikpapan'); await FA.fill('#bpR', 'Pengalaman 10 tahun di bidang mutu.'); await click(FA, '[data-act=blProfilSave]');
  await FA.setInputFiles('#blFoto', F.foto); await FA.waitForTimeout(800);
  const prof = (await api(FA, 'bl_get_profil', {})).r; ok(prof.jabatan === 'Ahli Teknologi Laboratorium Medik' && prof.foto, 'Profil & foto narasumber tersimpan');
  await go(FA, 'bl-' + kid); await FA.waitForTimeout(500);
  ok(/Pelatihan QC Westgard 2026/.test(await txt(FA)) && await FA.locator('[data-act=blTab][data-v=soal]').count() === 1, 'Fasilitator membuka kegiatan yang ditugaskan');
  await click(FA, '[data-act=blTab][data-v=soal]');
  await FA.fill('#sqT', 'Aturan 1-3s berarti?'); for (const x of ['a', 'b', 'c', 'd']) await FA.fill('#sq' + x, 'Pilihan ' + x.toUpperCase()); await FA.selectOption('#sqK', 'c'); await click(FA, '[data-act=blSoalSave]');
  ok(/1 dari 25/.test(await txt(FA)), 'Soal ditambahkan lewat formulir');
  const keys = 'abcdabcdabcdabcdabcdabcd'.split('');
  for (let i = 0; i < 24; i++) await api(FA, 'bl_soal_save', { kegId: +kid, text: 'Soal ' + (i + 2), oa: 'A' + (i + 2), ob: 'B' + (i + 2), oc: 'C' + (i + 2), od: 'D' + (i + 2), key: keys[i] });
  ok(!(await api(FA, 'bl_soal_save', { kegId: +kid, text: 'x', oa: 'a', ob: 'b', oc: 'c', od: 'd', key: 'a' })).ok, 'Soal dibatasi 25');
  ok(!(await api(FA, 'bl_soal_save', { kegId: +kid, text: 'x', oa: 'a', ob: '', oc: 'c', od: 'd', key: 'a' })).ok, 'Soal tanpa pilihan lengkap ditolak');
  await click(FA, '[data-act=blTab][data-v=materi]');
  await FA.fill('#bmT', 'Modul Westgard'); await FA.selectOption('#bmS', String(m1.id)); await FA.setInputFiles('#bmF', F.materi); await click(FA, '[data-act=blMateriUp]'); await FA.waitForTimeout(400);
  ok(/Modul Westgard/.test(await txt(FA)), 'Materi PDF diunggah');
  const up2 = await FA.evaluate(async (f) => { const fd = new FormData(); fd.append('kegId', f.k); fd.append('title', 'x'); fd.append('file', new Blob(['bukan pdf'], { type: 'application/pdf' }), 'x.pdf'); return Core.api('bl_materi_upload', null, { form: fd }).then(() => 'ok', e => e.message); }, { k: kid });
  ok(/PDF/.test(up2), 'Berkas bukan PDF ditolak');
  await click(FA, '[data-act=blTab][data-v=nilai]');
  await FA.fill('#ba-sikap-0', 'Kedisiplinan'); await FA.fill('#bd-sikap-0', '80'); await click(FA, '[data-act=blAspAdd][data-j=sikap]');
  await api(FA, 'bl_aspek_add', { kegId: +kid, jenis: 'sikap', name: 'Kerja sama', def: 70 });
  await api(FA, 'bl_aspek_add', { kegId: +kid, jenis: 'praktik', segId: prak.id, name: 'Ketepatan pipetasi', def: 60 });
  d = (await api(FA, 'bl_get', { id: +kid })).r; ok(d.aspek.length === 3 && d.aspek[0].name === 'Kedisiplinan' && d.aspek[0].def === 80, 'Aspek sikap & praktik dengan nilai bawaan diatur Fasilitator');
  ok(!('eval' in d), 'Fasilitator tidak melihat rekap penilaian terhadap narasumber');
  ok(!(await api(FA, 'bl_keg_update', { id: +kid, k: 'title', v: 'x' })).ok && !(await api(FA, 'bl_verif', { id: 1, v: 'diterima' })).ok, 'Fasilitator tidak dapat mengubah pengaturan/verifikasi');
  ok(!(await api(FA, 'bl_seg_state', { id: d.segs[0].id, v: 'buka' })).ok, 'Fasilitator yang tidak dicentang sebagai pembuka tidak dapat membuka segmen');

  // ---- Admin: terbitkan & buka pendaftaran
  await go(A, 'bl-' + kid); await A.reload(); await A.waitForTimeout(400);
  await click(A, '[data-act=blKegStatus][data-v=terbit]'); await click(A, '#cbOK'); await A.waitForTimeout(300);
  await A.check('[data-act=blDaftar]'); await A.waitForTimeout(500);
  d = (await api(A, 'bl_get', { id: +kid })).r; ok(d.keg.status === 'terbit' && d.keg.open, 'Kegiatan diterbitkan & pendaftaran dibuka');

  // ---- Sub-akun mendaftarkan diri
  const nb = await api(B, 'sdm_add', { name: 'Budi Santoso', jabatan: 'Analis' }); const budi = nb.r.id;
  ok(nb.r.username === 'pkm01.budi' && nb.r.password.length >= 8, 'Personel baru → sub-akun pkm01.budi dibuat dengan sandi sementara');
  ok(!(await api(B, 'bl_daftar', { kegId: +kid })).ok, 'Akun Puskesmas tidak dapat mendaftarkan peserta');
  await S1.reload(); await S1.waitForTimeout(400); await go(S1, 'bl'); ok(/Pelatihan QC Westgard 2026/.test(await txt(S1)) && /Pendaftaran dibuka/.test(await txt(S1)), 'Sub-akun melihat kegiatan terbit');
  ok((await S1.evaluate(() => [...document.querySelectorAll('.nav a')].map(a => a.getAttribute('href')).join())) === '#bl', 'Sub-akun hanya melihat menu Pembelajaran');
  await go(S1, 'bl-' + kid); await S1.waitForTimeout(400);
  ok(/Konfirmasi kehadiran/.test(await txt(S1)) && await S1.locator('[data-act=blKirim]').count() === 1 && await S1.locator('[data-act=blKosongkan]').count() === 1 && await S1.locator('a.btn[href="#bl"]').count() === 1, 'Formulir konfirmasi kehadiran dengan tombol Kirim, Kosongkan, Batal');
  ok((await S1.inputValue('#brN')) === 'Ani ATLM' && (await S1.inputValue('#brJ')) === 'Penanggung jawab lab', 'Data terisi dari Data Personel');
  ok(!(await api(S1, 'bl_jawab', { segId: 1, soalId: 1, v: 'a' })).ok, 'Sebelum diverifikasi, proses pembelajaran tidak dapat diakses');
  await click(S1, '[data-act=blKosongkan]'); ok((await S1.inputValue('#brN')) === '' && (await S1.inputValue('#brJ')) === '', 'Kosongkan mengosongkan isian');
  await S1.fill('#brN', 'Ani Lestari, A.Md.AK'); await S1.fill('#brP', '199001012020122001'); await S1.setInputFiles('#brS', F.surat); await click(S1, '[data-act=blKirim]');
  ok(/wajib diisi/.test(await S1.evaluate(() => [...document.querySelectorAll('.toast.bad')].map(x => x.textContent).join(' '))), 'Data tidak lengkap ditolak');
  await S1.fill('#brN', 'Ani Lestari, A.Md.AK'); await S1.fill('#brJ', 'Pranata Laboratorium Kesehatan'); await S1.fill('#brP', '199001012020122001');
  await S1.setInputFiles('#brS', F.besar); await click(S1, '[data-act=blKirim]');
  ok(/1 MB/.test(await S1.evaluate(() => [...document.querySelectorAll('.toast.bad')].map(x => x.textContent).join(' '))), 'Surat tugas > 1 MB ditolak');
  await S1.fill('#brN', 'Ani Lestari, A.Md.AK'); await S1.fill('#brJ', 'Pranata Laboratorium Kesehatan'); await S1.fill('#brP', '199001012020122001');
  await S1.setInputFiles('#brS', F.bukan); await click(S1, '[data-act=blKirim]');
  ok(/PDF/.test(await S1.evaluate(() => [...document.querySelectorAll('.toast.bad')].map(x => x.textContent).join(' '))), 'Surat tugas bukan PDF ditolak');
  await S1.fill('#brN', 'Ani Lestari, A.Md.AK'); await S1.fill('#brJ', 'Pranata Laboratorium Kesehatan'); await S1.fill('#brP', '199001012020122001');
  await S1.setInputFiles('#brS', F.surat); await click(S1, '[data-act=blKirim]'); await S1.waitForTimeout(300);
  const waitTxt = 'Sedang menunggu verifikasi Admin, silahkan kembali ke halaman ini beberapa saat lagi';
  ok((await S1.evaluate(() => document.querySelector('.content').innerText.trim())).endsWith(waitTxt) && !(await S1.locator('.content .tabs, .content table, .content button, .content input').count()), 'Setelah dikirim: hanya jendela polos dengan pesan menunggu verifikasi');
  const reg = (f, p, data) => p.evaluate(async (x) => { const fd = new FormData(); Object.keys(x).forEach(k => fd.append(k, x[k])); fd.append('surat', new Blob(['%PDF-1.4 x'], { type: 'application/pdf' }), 's.pdf'); return Core.api('bl_daftar', null, { form: fd }).then(() => 'ok', e => e.message); }, data);
  ok(/sudah didaftarkan/.test(await reg(F, S1, { kegId: kid, name: 'x', jabatan: 'x', nip: 'x' })), 'Sub-akun tidak dapat mendaftar dua kali');
  const SB = await newPage(); await firstLogin(SB, 'pkm01.budi', nb.r.password, 'PesertaBudi123');
  ok((await reg(F, SB, { kegId: kid, name: 'Budi Santoso', jabatan: 'Analis', nip: 'K-001' })) === 'ok', 'Sub-akun kedua mendaftar');
  const B2 = await newPage(); await login(B2, 'pkm02', 'Puskesmas02Baru');
  const p02 = await B2.evaluate(() => Core.S.personel[0].id), rs2 = await api(B2, 'bl_reset_sub', { personelId: p02 });
  ok(rs2.ok && rs2.r.username === 'pkm02.petugas', 'Akun Puskesmas mereset sandi sub-akun personelnya: ' + rs2.r.username);
  ok(!(await api(B2, 'bl_reset_sub', { personelId: ani })).ok, 'Puskesmas lain tidak dapat mereset sub-akun personel Puskesmas lain');
  const S2 = await newPage(); await firstLogin(S2, rs2.r.username, rs2.r.password, 'PesertaDua123');
  ok((await reg(F, S2, { kegId: kid, name: 'Petugas P02, S.Tr.Kes', jabatan: 'ATLM', nip: '198801' })) === 'ok', 'Sub-akun Puskesmas lain mendaftar');
  await B.reload(); await B.waitForTimeout(300); await go(B, 'bl-' + kid); await B.waitForTimeout(400);
  ok(/pkm01\.ani/.test(await txt(B)) && /pkm01\.budi/.test(await txt(B)) && /Menunggu verifikasi/.test(await txt(B)) && !/Petugas P02/.test(await txt(B)) && !(await B.locator('#brS, [data-act=blKirim]').count()), 'Akun Puskesmas hanya memantau pendaftaran personelnya');

  // ---- Admin: verifikasi
  await A.reload(); await A.waitForTimeout(400);
  ok(/3/.test(await A.evaluate(() => (document.querySelector('.nav a[href="#bl"] .nbadge') || {}).textContent || '')), 'Menu Admin menandai 3 pendaftar menunggu verifikasi');
  await click(A, '[data-act=blTab][data-v=peserta]');
  d = (await api(A, 'bl_get', { id: +kid })).r;
  const pAni = d.peserta.find(p => p.personelId === ani), pBudi = d.peserta.find(p => p.personelId === budi), pP02 = d.peserta.find(p => p.personelId === p02);
  ok((await status(S1, 'berkas.php?j=surat&id=' + pAni.id)) === 200 && (await status(SB, 'berkas.php?j=surat&id=' + pAni.id)) === 404, 'Surat tugas hanya dapat dibuka pemiliknya, Puskesmasnya, dan Admin');
  ok((await status(A, 'berkas.php?j=surat&id=' + pAni.id)) === 200, 'Admin dapat membuka surat tugas');
  await click(A, `[data-act=blVerif][data-id="${pAni.id}"][data-v=lengkapi]`); await A.fill('#vMsg', 'Surat tugas belum ditandatangani Kepala Puskesmas.'); await click(A, '#vOK');
  await click(A, `[data-act=blVerif][data-id="${pBudi.id}"][data-v=ditolak]`); await click(A, '#vOK');
  ok(/wajib diisi/.test(await A.evaluate(() => [...document.querySelectorAll('.toast.bad')].map(x => x.textContent).join(' '))), 'Menolak tanpa pesan ditolak sistem');
  await click(A, `[data-act=blVerif][data-id="${pBudi.id}"][data-v=ditolak]`); await A.fill('#vMsg', 'Bukan tenaga laboratorium.'); await click(A, '#vOK');
  await go(SB, 'bl-' + kid); await SB.waitForTimeout(400);
  ok(/Ditolak/.test(await txt(SB)) && /Bukan tenaga laboratorium/.test(await txt(SB)) && !(await SB.locator('[data-act=blKirim]').count()), 'Ditolak: pesan Admin tampil, pendaftaran berhenti');
  ok(!(await api(SB, 'bl_kirim_ulang', { kegId: +kid, name: 'x', jabatan: 'x', nip: 'x' })).ok, 'Pendaftaran yang ditolak tidak dapat dikirim ulang');
  await S1.reload(); await S1.waitForTimeout(300); await go(S1, 'bl-' + kid); await S1.waitForTimeout(400);
  ok(/Perlu dilengkapi/.test(await txt(S1)) && /belum ditandatangani Kepala Puskesmas/.test(await txt(S1)), 'Diminta melengkapi: pesan Admin tampil');
  ok((await B.evaluate(() => Core.api('state').then(() => Core.S.notifs.filter(n => /Pelatihan QC/.test(n.text)).length))) >= 2, 'Akun Puskesmas mendapat notifikasi hasil verifikasi');
  await S1.fill('#brJ', 'Pranata Laboratorium Kesehatan Ahli Pertama'); await S1.setInputFiles('#brS', F.surat); await click(S1, '[data-act=blKirim][data-ulang="1"]');
  d = (await api(A, 'bl_get', { id: +kid })).r; ok(d.peserta.find(p => p.id === pAni.id).status === 'menunggu' && d.peserta.find(p => p.id === pAni.id).jabatan === 'Pranata Laboratorium Kesehatan Ahli Pertama', 'Dilengkapi & dikirim ulang → kembali menunggu verifikasi');
  await A.reload(); await A.waitForTimeout(400); await click(A, '[data-act=blTab][data-v=peserta]');
  await click(A, `[data-act=blVerif][data-id="${pAni.id}"][data-v=diterima]`); await click(A, '#vOK'); await A.waitForTimeout(300);
  ok((await api(A, 'bl_get', { id: +kid })).r.peserta.find(p => p.id === pAni.id).status === 'diterima', 'Admin menerima peserta');
  const acc2 = await api(A, 'bl_verif', { id: pP02.id, v: 'diterima' }); ok(acc2.ok, 'Peserta Puskesmas lain diterima');
  ok(!(await api(A, 'bl_verif', { id: pP02.id, v: 'ditolak', msg: 'x' })).ok, 'Pendaftaran yang sudah diverifikasi tidak dapat diverifikasi ulang');

  // ---- Peserta (sub-akun)
  ok(!(await api(S1, 'sheet_get', { id: 1 })).ok && !(await api(S1, 'risk_save', { aspect: 'bahan', items: [] })).ok && (await S1.evaluate(() => Core.S.sheets.length + Core.S.alats.length + Core.S.bhp.length)) === 0, 'Sub-akun tidak dapat mengakses data QC/Manajemen Risiko/Sumber Daya Puskesmas');
  await S1.reload(); await S1.waitForTimeout(400); await go(S1, 'bl-' + kid); await S1.waitForTimeout(400);
  const st1 = await txt(S1); ok(/Selamat datang, Ani Lestari, A\.Md\.AK/.test(st1) && /Pretest/.test(st1) && /Aturan Westgard/.test(st1) && /Dr\. Rina Fasilitator/.test(st1), 'Halaman utama pembelajaran: ucapan selamat datang, segmen, sesi, PIC');
  d = (await api(S1, 'bl_get', { id: +kid })).r;
  const mat = d.materi[0]; ok(!mat && d.materiLocked, 'Materi terkunci sebelum Pretest diselesaikan');
  const mid = (await api(A, 'bl_get', { id: +kid })).r.materi[0].id;
  ok((await status(S1, 'berkas.php?j=materi&id=' + mid)) === 404, 'Unduhan materi ditolak sebelum Pretest selesai');
  ok((await status(S1, 'berkas.php?j=surat&id=' + pP02.id)) === 404 && (await status(B2, 'berkas.php?j=surat&id=' + pAni.id)) === 404, 'Surat tugas tidak dapat dibuka peserta/Puskesmas lain');

  // ---- Pembina (pembuka) membuka Pretest
  await P.reload(); await P.waitForTimeout(300); await go(P, 'bl-' + kid); await P.waitForTimeout(500);
  const segs = d.segs; const pre = segs[0], post = segs[4], pen = segs[5];
  await P.click(`[data-act=blSegState][data-id="${pre.id}"][data-v=buka]`); await P.waitForTimeout(200); await click(P, '#cbOK');
  ok((await api(P, 'bl_get', { id: +kid })).r.segs[0].status === 'buka', 'Pembina yang dipilih Admin membuka Pretest');
  ok(!(await api(FA, 'bl_soal_save', { kegId: +kid, id: 0, text: 'x', oa: 'a', ob: 'b', oc: 'c', od: 'd', key: 'a' })).ok, 'Soal terkunci setelah Pretest dibuka');
  // peserta menjawab: Ani 15 benar dari 25 lewat antarmuka sebagian
  await S1.reload(); await S1.waitForTimeout(400); await go(S1, 'bl-' + kid); await S1.waitForTimeout(400);
  ok(await S1.locator(`a[href="#bl-${kid}-s${pre.id}"]`).count() === 1, 'Tombol "Mulai segmen" tampil saat segmen dibuka');
  await S1.click(`a[href="#bl-${kid}-s${pre.id}"]`); await S1.waitForTimeout(500);
  const soalA = (await api(A, 'bl_get', { id: +kid })).r.soal; const kunci = {}; soalA.forEach(q => kunci[q.id] = q.key);
  const qs = (await api(S1, 'bl_get', { id: +kid })).r.tests[pre.id].soal;
  ok(qs.length === 25 && qs.every(q => !('key' in q)) && qs.map(q => q.id).join() === soalA.map(q => q.id).join(), 'Pretest: 25 soal tanpa kunci jawaban, urutan asli');
  const wrong = k => ({ a: 'b', b: 'c', c: 'd', d: 'a' })[k];
  for (let i = 0; i < 3; i++) { await S1.check(`[data-bljawab="${qs[i].id}"][value="${kunci[qs[i].id]}"]`); await S1.waitForTimeout(250); }
  ok(/Terjawab 3\/25/.test(await txt(S1)), 'Jawaban tersimpan per soal (penghitung 3/25)');
  await S1.reload(); await S1.waitForTimeout(500); await go(S1, `bl-${kid}-s${pre.id}`); await S1.waitForTimeout(500);
  ok(await S1.isChecked(`[data-bljawab="${qs[1].id}"][value="${kunci[qs[1].id]}"]`), 'Jawaban tetap ada setelah halaman dimuat ulang');
  for (let i = 3; i < 15; i++) await api(S1, 'bl_jawab', { segId: pre.id, soalId: qs[i].id, v: kunci[qs[i].id] });
  for (let i = 15; i < 24; i++) await api(S1, 'bl_jawab', { segId: pre.id, soalId: qs[i].id, v: wrong(kunci[qs[i].id]) });
  // pemantauan
  await A.reload(); await A.waitForTimeout(400); await click(A, '[data-act=blTab][data-v=pantau]');
  ok(/Terjawab 24\/25/.test(await txt(A)), 'Pemantauan Admin: progres jawaban peserta (24/25)');
  await S1.reload(); await S1.waitForTimeout(400); await go(S1, `bl-${kid}-s${pre.id}`); await S1.waitForTimeout(400);
  await S1.click(`[data-act=blSelesai][data-id="${pre.id}"]`); await S1.waitForTimeout(200);
  ok(/1 soal yang belum dijawab/.test(await S1.evaluate(() => document.querySelector('.modal').innerText)), 'Peringatan soal belum dijawab sebelum menyelesaikan');
  await click(S1, '#cbOK'); await S1.waitForTimeout(300);
  ok(!(await api(S1, 'bl_jawab', { segId: pre.id, soalId: qs[24].id, v: 'a' })).ok, 'Setelah diselesaikan, jawaban tidak dapat diubah');
  d = (await api(S1, 'bl_get', { id: +kid })).r; ok(d.materi.length === 1 && (await status(S1, 'berkas.php?j=materi&id=' + mid)) === 200, 'Materi dapat diunduh setelah Pretest diselesaikan');
  // peserta 2: 10 benar, tidak menyelesaikan materi 2
  for (let i = 0; i < 25; i++) await api(S2, 'bl_jawab', { segId: pre.id, soalId: qs[i].id, v: i < 10 ? kunci[qs[i].id] : wrong(kunci[qs[i].id]) });
  await api(S2, 'bl_selesai', { segId: pre.id });
  await api(P, 'bl_seg_state', { id: pre.id, v: 'tutup' });
  ok(!(await api(S2, 'bl_jawab', { segId: pre.id, soalId: qs[0].id, v: 'a' })).ok, 'Segmen ditutup → jawaban terkunci');
  // materi & praktik
  for (const s of [segs[1], segs[2], segs[3]]) await api(P, 'bl_seg_state', { id: s.id, v: 'buka' });
  await go(S1, `bl-${kid}-s${segs[1].id}`); await S1.waitForTimeout(500);
  ok(/Modul Westgard/.test(await txt(S1)) && /Ahli Teknologi Laboratorium Medik/.test(await txt(S1)), 'Segmen materi: daftar materi & profil narasumber');
  const [dl] = await Promise.all([S1.waitForEvent('download', { timeout: 10000 }).catch(() => null), S1.click('a[href*="j=materi"]')]);
  ok(dl && fs.readFileSync(await dl.path()).slice(0, 5).toString() === '%PDF-', 'Peserta mengunduh materi PDF');
  for (const s of [segs[1], segs[2], segs[3]]) await api(S1, 'bl_selesai', { segId: s.id });
  await api(S2, 'bl_selesai', { segId: segs[1].id }); await api(S2, 'bl_selesai', { segId: segs[2].id });
  for (const s of [segs[1], segs[2], segs[3]]) await api(P, 'bl_seg_state', { id: s.id, v: 'tutup' });
  ok(!(await api(S2, 'bl_selesai', { segId: segs[3].id })).ok, 'Segmen yang ditutup tidak dapat diselesaikan lagi');
  // penilaian sikap & praktik (Ani: Kedisiplinan 90, Kerja sama kosong=70, praktik 85; peserta 2: praktik kosong=60)
  const asp = (await api(FA, 'bl_get', { id: +kid })).r.aspek;
  await go(FA, 'bl-' + kid); await FA.reload(); await FA.waitForTimeout(400); await click(FA, '[data-act=blTab][data-v=nilai]');
  await FA.fill(`[data-blnilai="${pAni.id}"][data-asp="${asp[0].id}"]`, '90'); await FA.press(`[data-blnilai="${pAni.id}"][data-asp="${asp[0].id}"]`, 'Tab'); await FA.waitForTimeout(400);
  await api(FA, 'bl_nilai', { pesertaId: pAni.id, aspekId: asp[2].id, v: '85' });
  ok(!(await api(FA, 'bl_nilai', { pesertaId: pAni.id, aspekId: asp[1].id, v: '120' })).ok, 'Nilai di luar 0–100 ditolak');
  // post test
  await api(P, 'bl_seg_state', { id: post.id, v: 'buka' });
  const qp = (await api(S1, 'bl_get', { id: +kid })).r.tests[post.id].soal;
  const qp2 = (await api(S2, 'bl_get', { id: +kid })).r.tests[post.id].soal;
  ok(qp.length === 25 && qp.map(q => q.id).join() !== qs.map(q => q.id).join() && qp.map(q => q.id).sort().join() === qs.map(q => q.id).sort().join(), 'Post test: soal sama dengan urutan diacak');
  ok(qp.map(q => q.id).join() !== qp2.map(q => q.id).join(), 'Urutan acak berbeda tiap peserta');
  const qpAgain = (await api(S1, 'bl_get', { id: +kid })).r.tests[post.id].soal; ok(qpAgain.map(q => q.id).join() === qp.map(q => q.id).join(), 'Urutan acak tetap sama saat dibuka ulang');
  for (let i = 0; i < 25; i++) await api(S1, 'bl_jawab', { segId: post.id, soalId: qs[i].id, v: i < 22 ? kunci[qs[i].id] : wrong(kunci[qs[i].id]) });
  await api(S1, 'bl_selesai', { segId: post.id });
  for (let i = 0; i < 25; i++) await api(S2, 'bl_jawab', { segId: post.id, soalId: qs[i].id, v: i < 20 ? kunci[qs[i].id] : wrong(kunci[qs[i].id]) });
  await api(S2, 'bl_selesai', { segId: post.id });
  await api(P, 'bl_seg_state', { id: post.id, v: 'tutup' });
  // penutup: penilaian narasumber
  await api(P, 'bl_seg_state', { id: pen.id, v: 'buka' });
  await go(S1, `bl-${kid}-s${pen.id}`); await S1.waitForTimeout(500);
  ok((await S1.locator('[data-bleval]').count()) === 10 && /Penguasaan materi/.test(await txt(S1)) && /Sikap dan penampilan/.test(await txt(S1)), 'Penutup: 10 aspek penilaian narasumber');
  await S1.fill(`[data-bleval][data-asp="0"]`, '95'); await S1.press(`[data-bleval][data-asp="0"]`, 'Tab'); await S1.waitForTimeout(300);
  ok(!(await api(S1, 'bl_selesai', { segId: pen.id })).ok, 'Penutup tidak dapat diselesaikan sebelum semua aspek dinilai');
  const narsum = (await api(S1, 'bl_get', { id: +kid })).r.narsum.map(x => x.id);
  for (const nid of narsum) for (let i = 0; i < 10; i++) await api(S1, 'bl_eval', { segId: pen.id, narsumId: nid, aspek: i, v: String(80 + i) });
  await api(S1, 'bl_selesai', { segId: pen.id });
  for (const nid of narsum) for (let i = 0; i < 10; i++) await api(S2, 'bl_eval', { segId: pen.id, narsumId: nid, aspek: i, v: '70' });
  await api(S2, 'bl_selesai', { segId: pen.id });
  // sebelum semua ditutup: hasil tampil, predikat belum
  d = (await api(S1, 'bl_get', { id: +kid })).r;
  ok(d.result && d.result.mine && !d.result.predikat, 'Seluruh segmen selesai → nilai & peringkat tampil; predikat menunggu semua segmen ditutup');
  await api(P, 'bl_seg_state', { id: pen.id, v: 'tutup' });
  d = (await api(S1, 'bl_get', { id: +kid })).r; const r1 = d.result.mine;
  // perhitungan: Ani pre 60, post 88, progres 28, gain 70, sikap (90+70)/2=80, praktik 85, selesai 100%
  // terbaik = .30*88 + .30*85 + .25*80 + .15*70 = 82.4
  ok(r1.pre === 60 && r1.post === 88 && r1.progres === 28 && r1.gain === 70 && r1.sikap === 80 && r1.praktik === 85 && r1.complete === 100 && r1.terbaik === 82.4, 'Nilai Ani: pre 60, post 88, progres 28, sikap 80, praktik 85, skor terbaik 82,4');
  const r2s = (await api(S2, 'bl_get', { id: +kid })).r.result.mine;
  // peserta 2: pre 40, post 80, progres 40, gain 66.7, sikap (80+70)/2=75 (materi selesai), praktik tidak tercatat (tidak menyelesaikan) → 0, selesai 5/6
  // terbaik = .30*80 + 0 + .25*75 + .15*66.67 = 52.75
  ok(r2s.pre === 40 && r2s.post === 80 && r2s.progres === 40 && r2s.praktik === null && r2s.complete === 83.3 && r2s.terbaik === 52.8, 'Peserta 2: segmen praktik tidak diselesaikan → tidak tercatat; skor terbaik 52,8');
  ok(r1.rank.post === 1 && r1.rank.progres === 2 && r1.rank.complete === 1 && r1.rank.terbaik === 1 && r2s.rank.progres === 1, 'Peringkat tiap kategori benar');
  const pr = d.result.predikat;
  ok(pr.post[0].me && pr.terbaik[0].me && !pr.progres[0].me && /Petugas P02/.test(pr.progres[0].name), 'Predikat: Nilai Tertinggi & Peserta Terbaik = Ani; Progres Terbesar = peserta 2');
  await go(S1, 'bl-' + kid); await S1.waitForTimeout(500);
  const rt = await txt(S1);
  ok(/Hasil pembelajaran saya/.test(rt) && /Nilai post test/.test(rt) && /Penyelesaian Pembelajaran/.test(rt) && /peringkat 1 dari 2/.test(rt) && /Selamat! Anda meraih predikat Nilai Tertinggi, Peserta Terbaik/.test(rt) && /Progres Terbesar/.test(rt), 'Peserta melihat nilai, peringkat, dan ucapan selamat predikat');
  const rt2 = await (async () => { await go(S2, 'bl-' + kid); await S2.waitForTimeout(500); return txt(S2); })();
  ok(/Selamat! Anda meraih predikat Progres Terbesar/.test(rt2) && !/88/.test(rt2.split('Peraih predikat')[0]), 'Peserta lain hanya melihat nilainya sendiri');
  // rekap Admin
  await A.reload(); await A.waitForTimeout(400); await click(A, '[data-act=blTab][data-v=rekap]');
  const at = await txt(A);
  ok(/Nilai Tertinggi/.test(at) && /82,4/.test(at) && /Penilaian peserta terhadap narasumber/.test(at) && /Penguasaan materi/.test(at), 'Rekap Admin: nilai, predikat, penilaian narasumber');
  const ev = (await api(P, 'bl_get', { id: +kid })).r.eval; ok(ev.length === 1 && ev[0].per[0] === 75 && ev[0].n === 2, 'Rekap penilaian narasumber (rata-rata 2 penilai)');
  // Puskesmas memantau & reset sandi sub-akun
  await B.reload(); await B.waitForTimeout(300); await go(B, 'bl-' + kid); await B.waitForTimeout(400);
  ok(/pkm01\.ani/.test(await txt(B)) && /Penyelesaian 100% · Pretest 60 · Post test 88/.test(await txt(B)), 'Akun Puskesmas memantau progres personelnya');
  await go(B, 'sd-sdm-' + ani); await B.waitForTimeout(300); ok(/pkm01\.ani/.test(await txt(B)), 'Data Personel menampilkan sub-akun');
  await click(B, `[data-act=subReset][data-id="${ani}"]`); await click(B, '#srOK'); await B.waitForTimeout(300);
  const newPw = await B.evaluate(() => document.querySelectorAll('.modal code')[1].textContent); await B.click('.modal [data-close]');
  const S1b = await newPage(); await login(S1b, 'pkm01.ani', newPw); ok(/ganti kata sandi sementara/.test(await txt(S1b)), 'Reset sandi sub-akun dari Data Personel berfungsi');
  await api(B, 'sdm_active', { id: budi, v: false });
  const SB2 = await newPage(); await login(SB2, 'pkm01.budi', 'PesertaBudi123'); ok(/salah/.test(await txt(SB2)), 'Personel dinonaktifkan → sub-akun ikut nonaktif');
  await api(B, 'sdm_active', { id: budi, v: true }); await login(SB2, 'pkm01.budi', 'PesertaBudi123'); ok(!/salah/.test(await txt(SB2)), 'Personel diaktifkan kembali → sub-akun aktif lagi');
  // arsip
  await api(A, 'bl_keg_status', { id: +kid, v: 'arsip' });
  const FA2 = await newPage(); await login(FA2, 'fasil1', 'Fasilitator1Baru'); ok(/salah/.test(await txt(FA2)), 'Kegiatan diarsipkan → akun Fasilitator dinonaktifkan');
  ok(!(await api(P, 'bl_aspek_add', { kegId: +kid, jenis: 'sikap', name: 'x', def: 1 })).ok && !(await api(A, 'bl_keg_update', { id: +kid, k: 'title', v: 'x' })).ok, 'Kegiatan arsip tidak dapat diubah');
  ok((await api(S2, 'bl_get', { id: +kid })).r.result.mine.post === 80, 'Peserta tetap dapat melihat hasil setelah diarsipkan');

  // watermark hak cipta di setiap halaman
  const wm = 'Si PANDU © 2026 Adi Darma Aprianda, S.Tr.Kes, M.H.';
  const L = await newPage(); await L.goto(BASE + '/');
  const wmc = async p => p.evaluate(() => { const w = document.querySelector('.hak-cipta'); if (!w) return null; const r = w.getBoundingClientRect(); return { t: w.textContent, fixed: getComputedStyle(w).position === 'fixed', br: r.right > innerWidth - 40 && r.bottom > innerHeight - 30 }; });
  const w1 = await wmc(L), w2 = await wmc(A), w3 = await wmc(S2), w4 = await wmc(S1b);
  ok([w1, w2, w3, w4].every(w => w && w.t === wm && w.fixed && w.br), 'Watermark "' + wm + '" di pojok kanan bawah: halaman masuk, ganti sandi wajib, aplikasi');
  console.log(errors.length ? 'GALAT JS:\n' + errors.join('\n') : 'Tanpa galat JavaScript');
  console.log(`pemeriksaan: ${n}, gagal: ${fail}`);
  await browser.close(); process.exit(fail || errors.length ? 1 : 0);
})();
