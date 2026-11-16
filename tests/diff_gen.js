// Membangkitkan lembar acak, dievaluasi mesin JS; hasilnya dibandingkan dengan mesin PHP (diff_check.php)
const E = require('../assets/engine.js');
let seed = 12345; const rnd = () => (seed = (seed * 1103515245 + 12345) % 2147483648) / 2147483648;
const g = () => { let u = 1 - rnd(), v = rnd(); return Math.sqrt(-2 * Math.log(u)) * Math.cos(2 * Math.PI * v); };
const out = [];
for (let t = 0; t < 3000; t++) {
  const maxL = 1 + Math.floor(rnd() * 3), nAct = 1 + Math.floor(rnd() * maxL);
  const levels = []; for (let i = 0; i < maxL; i++) levels.push({ name: 'L' + i, mean: 50 + i * 40, sd: 2 + i, active: i < nAct });
  if (rnd() < 0.03) levels[0].sd = 0;
  const runs = []; const nr = 5 + Math.floor(rnd() * 40); let day = 1, month = 9;
  const shift = rnd() < 0.5 ? (rnd() * 3 - 1.5) : 0, scale = 0.6 + rnd() * 1.6;
  for (let r = 0; r < nr; r++) {
    day += 1 + (rnd() < 0.2 ? Math.floor(rnd() * 4) : 0); if (day > 28) { day = 1; month++; }
    const vals = levels.map((l, i) => { if (!l.active) return null; if (rnd() < 0.04) return null; let z = g() * scale + (r > nr / 2 ? shift : 0); if (rnd() < 0.05) z += (rnd() < 0.5 ? -1 : 1) * 3.5; return +(l.mean + z * (l.sd || 1)).toFixed(3); });
    runs.push({ id: 'R' + r, date: '2026-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0'), seq: rnd() < 0.1 ? 1 : 0, values: vals, excluded: rnd() < 0.05 });
  }
  const enabled = Object.assign({}, E.DEFAULT_SETTINGS.enabled); if (rnd() < 0.15) { const ks = Object.keys(enabled); enabled[ks[Math.floor(rnd() * ks.length)]] = false; }
  const sheet = { maxLevels: maxL, levels, runs };
  const ev = E.evaluate(sheet, { enabled });
  const st = E.stats(ev, levels, 2, 7.5);
  const pr = ev.filter(e => e.indication).map(e => E.priority(e.indication, { alat: Math.floor(rnd() * 26), bahan: 3, lingkungan: Math.floor(rnd() * 26), personel: 9 }));
  out.push({ sheet, enabled, ev: ev.map(e => ({ id: e.run.id, status: e.status, indication: e.indication, note: e.note, flags: e.flags, violations: e.violations, trend: e.trend, scope: e.scope, complete: e.complete, z: e.z, rulesNA: e.rulesNA })), st, prIn: ev.filter(e => e.indication).map((e, i) => ({ ind: e.indication })), pr });
}
require('fs').writeFileSync(__dirname + '/data/diff_cases.json', JSON.stringify(out));
console.log('kasus', out.length);
