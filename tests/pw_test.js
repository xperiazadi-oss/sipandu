// Uji tombol mata kata sandi: terlihat hanya selama ditekan-tahan (mouse, sentuh, papan ketik)
const { chromium } = require('/opt/npm-tools/node_modules/playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8099';
(async () => {
  const browser = await chromium.launch({ executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox'] });
  let n = 0, fail = 0; const errors = [];
  const ok = (c, m) => { n++; if (!c) fail++; console.log((c ? 'ok   ' : 'GAGAL ') + m); };
  const page = async (opts) => { const ctx = await browser.newContext(opts || { viewport: { width: 1200, height: 900 } }); const p = await ctx.newPage(); p.on('pageerror', e => errors.push(e.message)); return p; };
  const type = (p, sel) => p.getAttribute(sel, 'type');
  const box = async (p, sel) => { const b = await p.locator(sel).boundingBox(); return [b.x + b.width / 2, b.y + b.height / 2]; };
  // halaman masuk – mouse
  const p = await page(); await p.goto(BASE + '/');
  const inp = '[name=password]', eye = '.pw .pw-eye';
  ok(await p.locator(eye).count() === 1, 'Halaman masuk: ikon mata ada di kolom kata sandi');
  ok((await p.getAttribute(eye, 'title')) === 'Klik dan tahan untuk tampilkan', 'Teks keterangan: "Klik dan tahan untuk tampilkan"');
  await p.fill(inp, 'Rahasia123'); ok(await type(p, inp) === 'password', 'Awal: tersembunyi');
  const [x, y] = await box(p, eye); await p.mouse.move(x, y); await p.mouse.down();
  ok(await type(p, inp) === 'text', 'Ditekan-tahan (mouse): terlihat');
  await p.waitForTimeout(600); ok(await type(p, inp) === 'text', 'Tetap terlihat selama masih ditahan');
  await p.mouse.up(); ok(await type(p, inp) === 'password', 'Dilepas: tersembunyi lagi');
  await p.mouse.move(x, y); await p.mouse.down(); await p.mouse.move(x + 300, y + 200); await p.mouse.up();
  ok(await type(p, inp) === 'password', 'Ditahan lalu digeser keluar dan dilepas: tersembunyi');
  await p.click(eye); ok(await type(p, inp) === 'password' && (await p.inputValue(inp)) === 'Rahasia123', 'Klik singkat tidak membuat sandi terus terlihat; isian tetap utuh');
  ok((await p.url()) === BASE + '/', 'Menekan ikon mata tidak mengirim formulir');
  // papan ketik
  await p.focus(eye); await p.keyboard.down(' '); ok(await type(p, inp) === 'text', 'Papan ketik: tahan Spasi pada ikon → terlihat');
  await p.keyboard.up(' '); ok(await type(p, inp) === 'password', 'Spasi dilepas → tersembunyi');
  // sentuh (ponsel)
  const m = await page({ viewport: { width: 390, height: 800 }, hasTouch: true, isMobile: true }); await m.goto(BASE + '/');
  await m.fill(inp, 'Rahasia123'); const cdp = await m.context().newCDPSession(m); const [tx, ty] = await box(m, eye);
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: tx, y: ty }] });
  await m.waitForTimeout(700); ok(await type(m, inp) === 'text', 'Ponsel: sentuh-tahan → terlihat');
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }); await m.waitForTimeout(100);
  ok(await type(m, inp) === 'password', 'Ponsel: jari diangkat → tersembunyi');
  // ganti sandi wajib (masuk pertama) – akun pkm03 dengan sandi yang diberikan lewat env
  if (process.env.PKM_USER) {
    const q = await page(); await q.goto(BASE + '/'); await q.fill('[name=username]', process.env.PKM_USER); await q.fill(inp, process.env.PKM_PW); await q.click('button[type=submit]'); await q.waitForLoadState();
    ok(await q.locator('.pw .pw-eye').count() === 3, 'Ganti sandi wajib: ikon mata di 3 kolom');
    for (const nm of ['old', 'new', 'new2']) {
      await q.fill(`[name=${nm}]`, 'Abc' + nm + '123'); const e = q.locator(`[name=${nm}] + .pw-eye`); const b = await e.boundingBox();
      await q.mouse.move(b.x + 10, b.y + 10); await q.mouse.down(); const t1 = await type(q, `[name=${nm}]`); await q.mouse.up(); const t2 = await type(q, `[name=${nm}]`);
      ok(t1 === 'text' && t2 === 'password', `Ganti sandi wajib, kolom ${nm}: tahan → terlihat, lepas → tersembunyi (lainnya tetap tersembunyi)`);
    }
  }
  // Ganti kata sandi dari tombol atas (aplikasi)
  if (process.env.ADM_PW) {
    const a = await page(); await a.goto(BASE + '/'); await a.fill('[name=username]', 'admin'); await a.fill(inp, process.env.ADM_PW); await a.click('button[type=submit]'); await a.waitForLoadState(); await a.waitForTimeout(300);
    await a.click('[data-act=pwModal]'); await a.waitForTimeout(200);
    ok(await a.locator('.modal .pw .pw-eye').count() === 2, 'Ganti kata sandi (tombol atas): ikon mata di 2 kolom');
    await a.fill('#pwO', 'xyz12345'); const b = await a.locator('#pwO + .pw-eye').boundingBox();
    await a.mouse.move(b.x + 10, b.y + 10); await a.mouse.down(); const t1 = await type(a, '#pwO'); await a.mouse.up();
    ok(t1 === 'text' && await type(a, '#pwO') === 'password' && await a.locator('.modal').count() === 1, 'Modal: tahan → terlihat, lepas → tersembunyi, modal tidak tertutup');
  }
  console.log(errors.length ? 'GALAT JS:\n' + errors.join('\n') : 'Tanpa galat JavaScript');
  console.log(`pemeriksaan: ${n}, gagal: ${fail}`);
  await browser.close(); process.exit(fail || errors.length ? 1 : 0);
})();
