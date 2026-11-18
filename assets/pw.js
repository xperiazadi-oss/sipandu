/* Si PANDU – tombol mata pada kolom kata sandi: sandi hanya terlihat selama tombol ditekan-tahan. */
(function () {
  'use strict';
  function inputOf(btn) { return btn && btn.parentNode ? btn.parentNode.querySelector('input') : null; }
  function show(btn) { var i = inputOf(btn); if (i) { i.type = 'text'; btn.classList.add('on'); } }
  function hide(btn) { var i = inputOf(btn); if (i) { i.type = 'password'; btn.classList.remove('on'); } }
  function hideAll() { Array.prototype.forEach.call(document.querySelectorAll('.pw-eye.on'), hide); }
  function eye(e) { return e.target && e.target.closest ? e.target.closest('.pw-eye') : null; }
  document.addEventListener('pointerdown', function (e) { var b = eye(e); if (!b) return; e.preventDefault(); show(b); if (b.setPointerCapture) try { b.setPointerCapture(e.pointerId); } catch (x) { } });
  ['pointerup', 'pointercancel', 'lostpointercapture'].forEach(function (ev) { document.addEventListener(ev, hideAll); });
  document.addEventListener('pointerout', function (e) { var b = eye(e); if (b && !b.hasPointerCapture) hide(b); });
  document.addEventListener('contextmenu', function (e) { if (eye(e)) e.preventDefault(); });
  document.addEventListener('click', function (e) { if (eye(e)) e.preventDefault(); });
  // papan ketik: tahan Spasi/Enter pada tombol
  document.addEventListener('keydown', function (e) { var b = eye(e); if (b && (e.key === ' ' || e.key === 'Enter')) { e.preventDefault(); show(b); } });
  document.addEventListener('keyup', function (e) { var b = eye(e); if (b) hide(b); });
  document.addEventListener('submit', hideAll, true);
  window.addEventListener('blur', hideAll);
  document.addEventListener('visibilitychange', hideAll);
})();
