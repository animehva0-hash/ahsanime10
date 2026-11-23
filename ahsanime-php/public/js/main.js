/* AHSANIME — minimal front-end interactions */
(function () {
  'use strict';

  /* -------------------------- Spotlight carousel -------------------------- */
  var spot = document.getElementById('spotlight');
  if (spot) {
    var slides = Array.prototype.slice.call(spot.querySelectorAll('[data-slide]'));
    var contents = Array.prototype.slice.call(spot.querySelectorAll('[data-slide-content]'));
    var thumbs = Array.prototype.slice.call(spot.querySelectorAll('[data-go]'));
    var dots = spot.querySelectorAll('#spot-dots span');
    var prev = spot.querySelector('#spot-prev');
    var next = spot.querySelector('#spot-next');
    var idx = 0;
    var timer = null;
    var paused = false;
    var n = slides.length;
    if (n < 2) return;

    function show(i) {
      idx = ((i % n) + n) % n;
      slides.forEach(function (s, k) {
        s.classList.toggle('opacity-100', k === idx);
        s.classList.toggle('pointer-events-none', k !== idx);
        s.classList.toggle('opacity-0', k !== idx);
      });
      contents.forEach(function (c, k) { c.classList.toggle('hidden', k !== idx); });
      thumbs.forEach(function (t, k) {
        t.classList.toggle('scale-110', k === idx);
        t.classList.toggle('border-[#ff8a00]', k === idx);
        t.classList.toggle('opacity-50', k !== idx);
      });
      dots.forEach(function (d, k) {
        d.classList.toggle('w-6', k === idx);
        d.classList.toggle('bg-[#ff8a00]', k === idx);
        d.classList.toggle('w-1.5', k !== idx);
        d.classList.toggle('bg-white/30', k !== idx);
      });
    }
    function go(i) { show(i); restart(); }
    function restart() {
      if (timer) clearInterval(timer);
      if (!paused) timer = setInterval(function () { show(idx + 1); }, 7000);
    }
    if (prev) prev.addEventListener('click', function () { go(idx - 1); });
    if (next) next.addEventListener('click', function () { go(idx + 1); });
    thumbs.forEach(function (t) { t.addEventListener('click', function () { go(parseInt(t.getAttribute('data-go'), 10)); }); });
    spot.addEventListener('mouseenter', function () { paused = true; if (timer) clearInterval(timer); });
    spot.addEventListener('mouseleave', function () { paused = false; restart(); });
    show(0);
    restart();
  }

  /* ----------------------------- Mode switch ----------------------------- */
  var modeSwitch = document.getElementById('mode-switch');
  if (modeSwitch) {
    var btns = modeSwitch.querySelectorAll('.mode-btn');
    var frames = document.querySelectorAll('[data-frame]');
    btns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var mode = btn.getAttribute('data-mode');
        btns.forEach(function (b) {
          var active = b === btn;
          b.classList.toggle('bg-gradient-to-r', active);
          b.classList.toggle('from-[#ff8a00]', active);
          b.classList.toggle('to-[#ff3d5a]', active);
          b.classList.toggle('text-white', active);
          b.classList.toggle('border', !active);
          b.classList.toggle('border-white/10', !active);
          b.classList.toggle('bg-white/5', !active);
          b.classList.toggle('text-zinc-400', !active);
        });
        frames.forEach(function (f) {
          f.classList.toggle('hidden', f.getAttribute('data-frame') !== mode);
        });
      });
    });
  }
})();