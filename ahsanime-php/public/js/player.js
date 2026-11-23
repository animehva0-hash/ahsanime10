/* AHSANIME native HLS player.
 *
 * Control-bar core adapted from uiCookies "Custom Video Control Bars"
 * (https://uicookies.com/snippets/custom-video-control-bars/), licence free for
 * personal/commercial use (attribution optional). Extended with:
 *   - hls.js HLS source loading (our streams are .m3u8, served via /api/stream/proxy)
 *   - server / quality selector
 *   - Gemini subtitle translation: a language dropdown translates the English
 *     captions in-place (progressive windowed requests), captions ON by default.
 *
 * Each [data-vp="cb"] wraps one native <video>; controls are found by data
 * attributes (data-play, data-seek, data-skip, data-mute, data-vol, data-cc,
 * data-speed, data-fs, data-server, data-lang, data-captions, data-status). Without
 * JS the video keeps native controls. Keyboard shortcuts work while focus is
 * inside the player: Space/K play, ←/→ 5 s, M mute, C captions, F full screen.
 */
(function () {
  "use strict";

  var CHUNK = 60; // seconds per translation window (matches the PHP translate route)

  function vpFmt(s) {
    s = Math.max(0, Math.floor(s || 0));
    return Math.floor(s / 60) + ":" + String(s % 60).padStart(2, "0");
  }

  // Minimal WebVTT parser → [{ id, start, end, text }].
  function parseVtt(src) {
    var cues = [];
    var lines = String(src).replace(/\r\n?/g, "\n").split("\n");
    var i = 0;
    while (i < lines.length) {
      var line = lines[i];
      if (line.indexOf("-->") !== -1 && /(\d{2}:)?\d{2}:\d{2}[.,]\d{3}/.test(line)) {
        var times = line.split("-->");
        var start = toSec(times[0].trim());
        var end = toSec(times[1].trim().split(/\s+/)[0]);
        i++;
        var buf = [];
        while (i < lines.length && lines[i].trim() !== "" && lines[i].indexOf("-->") === -1) {
          buf.push(lines[i]);
          i++;
        }
        cues.push({ id: cues.length + 1, start: start, end: end, text: buf.join("\n") });
      } else {
        i++;
      }
    }
    return cues;
  }

  function toSec(ts) {
    ts = ts.replace(",", ".");
    var parts = ts.split(":");
    var s = 0;
    if (parts.length === 3) s = parseInt(parts[0], 10) * 3600 + parseInt(parts[1], 10) * 60 + parseFloat(parts[2]);
    else if (parts.length === 2) s = parseInt(parts[0], 10) * 60 + parseFloat(parts[1]);
    else s = parseFloat(parts[0]);
    return isFinite(s) ? s : 0;
  }

  function proxyUrl(u, ref) {
    var p = new URLSearchParams({ url: u });
    if (ref) p.set("ref", ref);
    return "/api/stream/proxy?" + p.toString();
  }

  function vpPlayer(root) {
    var $ = function (s) { return root.querySelector(s); };
    var video = $("video");
    var plays = [].slice.call(root.querySelectorAll("[data-play]"));
    var seek = $("[data-seek]"), vol = $("[data-vol]"), mute = $("[data-mute]"), cc = $("[data-cc]"), ccBox = $("[data-captions]");
    var speed = $("[data-speed]"), fs = $("[data-fs]"), cur = $("[data-cur]"), dur = $("[data-dur]");
    var serverSel = $("[data-server]"), langSel = $("[data-lang]"), langNote = $("[data-lang-note]");
    var statusEl = $("[data-status]"), statusMsg = $("[data-status-msg]");
    var capSetPanel = $("[data-capset]"), capSetOpen = $("[data-capset-open]"), capSetClose = $("[data-capclose]"), capSetReset = $("[data-capreset]");
    var RATES = [1, 1.25, 1.5, 2, 0.5, 0.75], listeners = [];
    var raf = 0, idle = 0, pointer = "mouse", hls = null;

    var animeId = root.getAttribute("data-anime-id");
    var ep = root.getAttribute("data-ep");

    video.controls = false;
    root.setAttribute("data-ready", "");

    // ---------- source / server loading ----------

    var servers = [];
    var currentServer = 0;
    var sessionId = newSessionId();
    var translateToken = 0;
    var translating = false;

    // Translation state (per server).
    var srcCues = [];          // English source cues (parsed once per server)
    var byStart = new Map();   // translated cues keyed by rounded start
    var tNext = 0;             // next source cue index to translate
    var tDone = 0;

    function newSessionId() {
      if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
      return "s-" + Date.now().toString(36) + "-" + Math.random().toString(36).slice(2, 10);
    }

    function setStatus(msg) {
      if (!statusEl) return;
      if (msg) {
        if (statusMsg) statusMsg.textContent = msg;
        statusEl.hidden = false;
      } else {
        statusEl.hidden = true;
      }
    }

    function currentReferer() {
      var s = servers[currentServer];
      return (s && s.referer) || "";
    }

    function currentEnglishUrl() {
      var s = servers[currentServer];
      if (s && s.vtt && s.vtt.length) return s.vtt[0].url;
      return null;
    }

    function loadSources() {
      setStatus("Loading episode…");
      if (langNote) langNote.hidden = true;
      fetch("/api/stream/sources?animeId=" + encodeURIComponent(animeId) + "&ep=" + encodeURIComponent(ep))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          var all = (data && data.servers) || [];
          // Filter by current mode (sub/dub) set on the active mode button.
          var modeBtn = document.querySelector("#mode-switch .mode-btn.bg-gradient-to-r");
          var mode = modeBtn ? modeBtn.getAttribute("data-mode") : "sub";
          var filtered = all.filter(function (s) { return (s.type || "sub") === mode; });
          servers = filtered.length ? filtered : all;
          if (!servers.length) { setStatus("No playable source found for this episode."); return; }
          renderServerSelect();
          loadServer(0);
        })
        .catch(function () { setStatus("Could not load sources."); });
    }

    function renderServerSelect() {
      if (!serverSel) return;
      serverSel.innerHTML = "";
      servers.forEach(function (s, i) {
        var opt = document.createElement("option");
        opt.value = String(i);
        opt.textContent = s.name + (s.vtt && s.vtt.length ? " ▸ CC" : "");
        serverSel.appendChild(opt);
      });
      serverSel.value = String(currentServer);
    }

    function loadServer(idx) {
      if (!servers[idx]) return;
      currentServer = idx;
      var server = servers[idx];
      if (serverSel) serverSel.value = String(idx);

      // Reset per-server state.
      translating = false;
      translateToken++;
      sessionId = newSessionId();
      srcCues = [];
      byStart = new Map();
      tNext = 0;
      tDone = 0;

      // Reset captions.
      currentCues = [];
      if (ccBox) { ccBox.textContent = ""; ccBox.hidden = true; }

      setStatus("Loading episode…");
      var src = proxyUrl(server.hls, server.referer || "");

      if (hls) { hls.destroy(); hls = null; }

      var isMp4 = /\.mp4($|\?)/i.test(server.hls);
      if (window.Hls && window.Hls.isSupported() && !isMp4) {
        hls = new window.Hls({ enableWorker: true, lowLatencyMode: true });
        hls.loadSource(src);
        hls.attachMedia(video);
        hls.on(window.Hls.Events.MANIFEST_PARSED, function () {
          setStatus(null);
          var pr = video.play();
          if (pr && pr.catch) pr.catch(function () {});
        });
        hls.on(window.Hls.Events.ERROR, function (evt, data) {
          if (data && data.fatal) {
            if (data.type === window.Hls.ErrorTypes.NETWORK_ERROR) setStatus("Network error loading stream…");
            else if (data.type === window.Hls.ErrorTypes.MEDIA_ERROR) setStatus("Media error, trying next server…");
            else setStatus("Playback error.");
          }
        });
      } else {
        // Native HLS (Safari) or direct MP4.
        video.src = src;
        var onMeta = function () { video.removeEventListener("loadedmetadata", onMeta); setStatus(null); };
        video.addEventListener("loadedmetadata", onMeta);
        var pr2 = video.play();
        if (pr2 && pr2.catch) pr2.catch(function () {});
      }

      // Preload English captions so CC shows even before a language is chosen.
      if (server.vtt && server.vtt.length && langSel && langSel.value === "") {
        fetchEnglishCues().then(function (cues) {
          if (currentServer !== idx) return; // server changed meanwhile
          srcCues = cues;
          byStart = new Map();
          tNext = 0;
          tDone = 0;
          applyCaptionSource();
        }).catch(function () {});
      }
    }

    // ---------- transport sync ----------

    var length = function () { return (isFinite(video.duration) && video.duration) || (seek ? +seek.max : 0); };
    var sync = function () {
      var t = video.currentTime, d = length();
      root.style.setProperty("--p", d ? Math.min(t / d, 1) : 0);
      if (cur) cur.textContent = vpFmt(t);
      if (seek) {
        seek.value = t;
        var text = vpFmt(t) + " of " + vpFmt(d);
        if (seek.getAttribute("aria-valuetext") !== text) seek.setAttribute("aria-valuetext", text);
      }
      listeners.forEach(function (fn) { fn(t, d); });
    };
    var buffered = function () {
      var b = video.buffered, d = length();
      var end = 0;
      for (var i = 0; i < b.length; i++) if (b.start(i) <= video.currentTime + 0.5) end = Math.max(end, b.end(i));
      root.style.setProperty("--b", d ? Math.min(end / d, 1) : 0);
    };
    var tick = function () { sync(); raf = requestAnimationFrame(tick); };
    var meta = function () { if (seek) seek.max = length(); if (dur) dur.textContent = vpFmt(length()); sync(); buffered(); };
    var jump = function (t) { video.currentTime = Math.min(Math.max(t, 0), length()); sync(); wake(); };
    var start = function () {
      document.querySelectorAll("video, audio").forEach(function (m) { if (m !== video && !m.hasAttribute("data-bg")) m.pause(); });
      return video.play().catch(function () {});
    };
    var toggle = function () { return (video.paused || video.ended ? start() : video.pause()); };
    var name = function () { return root.dataset.title || "video"; };
    var state = function () {
      var playing = !video.paused;
      root.toggleAttribute("data-playing", playing);
      plays.forEach(function (b) { b.setAttribute("aria-label", (playing ? "Pause " : "Play ") + name()); });
    };

    // ---------- captions (custom DOM overlay, driven by video.currentTime) ----------
    // Adapted from the reference player's CaptionOverlay: cues are rendered as
    // plain DOM text on top of the <video>, positioned by the current playback
    // time. This is far more reliable than a native <track> over HLS.js/MSE
    // (which browsers render inconsistently) and works for HLS, MP4 and Safari
    // native HLS alike. The CC button toggles visibility.

    var ccOn = function () { return !!cc && cc.getAttribute("aria-pressed") === "true"; };
    var currentCues = [];   // [{ id, start, end, text }] — the merged set
    var capRaf = 0;         // RAF handle for caption painting

    // ---- caption appearance (ported from the reference CaptionSettings) ----
    var CAP_KEY = "vp_caption_settings";
    var CAP_COLORS = ["#ffffff", "#ffe08a", "#ff8a8a", "#8affc1", "#8ad4ff", "#c58aff", "#ff9ecb", "#cccccc"];
    var CAP_DEFAULT = { size: 1, position: 84, color: "#ffffff", background: "shadow", bold: true, italic: false };
    var capSet = loadCapSettings();

    function clampNum(v, lo, hi) { v = Number(v); return isFinite(v) ? Math.min(hi, Math.max(lo, v)) : lo; }
    function loadCapSettings() {
      try {
        var raw = localStorage.getItem(CAP_KEY);
        if (!raw) return Object.assign({}, CAP_DEFAULT);
        var p = JSON.parse(raw);
        return {
          size: clampNum(p.size != null ? p.size : 1, 0.6, 2.2),
          position: clampNum(p.position != null ? p.position : 84, 20, 96),
          color: typeof p.color === "string" ? p.color : "#ffffff",
          background: (p.background === "box" || p.background === "none") ? p.background : "shadow",
          bold: p.bold !== false,
          italic: p.italic === true,
        };
      } catch (e) { return Object.assign({}, CAP_DEFAULT); }
    }
    function saveCapSettings() { try { localStorage.setItem(CAP_KEY, JSON.stringify(capSet)); } catch (e) {} }

    // Build the inline style object for a caption line from capSet.
    function capTextStyle() {
      var box = capSet.background === "box";
      var shadow = capSet.background === "none" ? "none"
        : "-1.5px 0 0 #000,1.5px 0 0 #000,0 -1.5px 0 #000,0 1.5px 0 #000,.75px .75px 0 #000,-.75px -.75px 0 #000,.75px -.75px 0 #000,-.75px .75px 0 #000,0 3px 8px rgba(0,0,0,.85)";
      return {
        color: capSet.color,
        fontWeight: capSet.bold ? 800 : 500,
        fontStyle: capSet.italic ? "italic" : "normal",
        fontSize: "calc(clamp(0.95rem, 3.4vw, 1.25rem) * " + capSet.size + ")",
        background: box ? "rgba(0,0,0,.62)" : "transparent",
        padding: box ? "0.15em 0.45em" : "0",
        borderRadius: box ? "0.25em" : "0",
        textShadow: shadow,
      };
    }

    // Reflect capSet into the panel controls + preview.
    function syncCapPanel() {
      var sizeEl = root.querySelector("[data-cap-size]"), sizeVal = root.querySelector("[data-cap-sizeval]");
      if (sizeEl) sizeEl.value = capSet.size;
      if (sizeVal) sizeVal.textContent = Math.round(capSet.size * 100) + "%";
      var posEl = root.querySelector("[data-cap-pos]"), posVal = root.querySelector("[data-cap-posval]");
      if (posEl) posEl.value = capSet.position;
      if (posVal) posVal.textContent = Math.round(capSet.position) + "%";
      root.querySelectorAll("[data-color]").forEach(function (b) {
        b.classList.toggle("is-on", b.getAttribute("data-color").toLowerCase() === capSet.color.toLowerCase());
      });
      root.querySelectorAll("[data-bg]").forEach(function (b) { b.classList.toggle("is-on", b.getAttribute("data-bg") === capSet.background); });
      root.querySelectorAll("[data-pos]").forEach(function (b) { b.classList.toggle("is-on", +b.getAttribute("data-pos") === Math.round(capSet.position)); });
      var bold = root.querySelector("[data-cap-bold]"), ital = root.querySelector("[data-cap-italic]");
      if (bold) bold.classList.toggle("is-on", capSet.bold);
      if (ital) ital.classList.toggle("is-on", capSet.italic);
      // Live preview text.
      var pv = root.querySelector("[data-cap-preview]");
      if (pv) {
        var box = capSet.background === "box";
        pv.style.color = capSet.color;
        pv.style.fontWeight = capSet.bold ? "800" : "500";
        pv.style.fontStyle = capSet.italic ? "italic" : "normal";
        pv.style.fontSize = "calc(0.95rem * " + capSet.size + ")";
        pv.style.background = box ? "rgba(0,0,0,.62)" : "transparent";
        pv.style.padding = box ? "0.15em 0.45em" : "0";
        pv.style.borderRadius = box ? "0.25em" : "0";
        pv.style.textShadow = capSet.background === "none" ? "none"
          : "-1.5px 0 0 #000,1.5px 0 0 #000,0 -1.5px 0 #000,0 1.5px 0 #000,0 3px 8px rgba(0,0,0,.85)";
      }
    }
    // Apply capSet to the currently painted cues.
    function applyCapSettings() { paintCaptions(); }

    // (Re)store the merged cue list and repaint.
    function setTrack(cues) {
      currentCues = cues || [];
      paintCaptions();
    }

    // Paint the cues active at video.currentTime into the overlay div.
    function paintCaptions() {
      if (!ccBox) return;
      if (!ccOn() || !currentCues.length) {
        if (ccBox.childNodes.length) ccBox.textContent = "";
        ccBox.hidden = true;
        return;
      }
      var t = video.currentTime;
      var lines = [];
      for (var i = 0; i < currentCues.length; i++) {
        var c = currentCues[i];
        if (c.start <= t && t < c.end) {
          lines.push(c.text);
          if (lines.length >= 2) break; // safety: max 2 stacked cues
        }
      }
      if (!lines.length) {
        if (!ccBox.hidden) { ccBox.textContent = ""; ccBox.hidden = true; }
        return;
      }
      ccBox.hidden = false;
      // Vertical position: top-based %, mapped to distance from the bottom.
      // Lower position% => captions higher up (bigger bottom offset).
      var bottomPct = Math.max(0, Math.min(100, 100 - capSet.position));
      ccBox.style.bottom = "calc(" + bottomPct + "% + 14px)";
      ccBox.textContent = lines.join("\n");
      // Apply per-line appearance.
      var st = capTextStyle();
      var spans = ccBox.querySelectorAll(".vp-caption");
      if (!spans.length) {
        ccBox.textContent = "";
        for (var j = 0; j < lines.length; j++) {
          var sp = document.createElement("span");
          sp.className = "vp-caption";
          sp.textContent = lines[j];
          ccBox.appendChild(sp);
        }
        spans = ccBox.querySelectorAll(".vp-caption");
      } else {
        for (var k = 0; k < spans.length; k++) spans[k].textContent = lines[k] != null ? lines[k] : "";
      }
      for (var m = 0; m < spans.length; m++) {
        spans[m].style.color = st.color;
        spans[m].style.fontWeight = st.fontWeight;
        spans[m].style.fontStyle = st.fontStyle;
        spans[m].style.fontSize = st.fontSize;
        spans[m].style.background = st.background;
        spans[m].style.padding = st.padding;
        spans[m].style.borderRadius = st.borderRadius;
        spans[m].style.textShadow = st.textShadow;
      }
    }

    // Continuous repaint while playing (RAF), cheap and in sync with the video.
    function capLoop() {
      paintCaptions();
      capRaf = requestAnimationFrame(capLoop);
    }

    // ---------- Gemini subtitle translation ----------

    function setLangNote(text) {
      if (!langNote) return;
      if (text) { langNote.textContent = text; langNote.hidden = false; }
      else { langNote.textContent = ""; langNote.hidden = true; }
    }

    function fetchEnglishCues() {
      var url = currentEnglishUrl();
      if (!url) return Promise.resolve([]);
      var p = new URLSearchParams({ url: url });
      var ref = currentReferer();
      if (ref) p.set("ref", ref);
      return fetch("/api/stream/subtitle?" + p.toString())
        .then(function (r) { if (!r.ok) throw new Error("Could not load English captions"); return r.text(); })
        .then(parseVtt);
    }

    function doTranslate(langCode, langLabel) {
      var englishUrl = currentEnglishUrl();
      if (!englishUrl) { setLangNote("This server has no captions to translate."); return; }
      if (!langCode || langCode === "en") {
        // Show original English.
        fetchEnglishCues().then(function (cues) {
          srcCues = cues; byStart = new Map(); tNext = 0; tDone = 0;
          applyCaptionSource();
          setLangNote("English subtitles");
        });
        return;
      }

      var myToken = ++translateToken;
      translating = true;
      setLangNote("Translating to " + langLabel + "…");

      fetchEnglishCues()
        .then(function (cues) {
          if (!cues.length) {
            translating = false;
            setLangNote("No captions available to translate.");
            return;
          }
          srcCues = cues;
          byStart = new Map();
          tNext = 0;
          tDone = 0;
          applyCaptionSource(); // show English immediately

          return worker(myToken, langCode, langLabel).then(function () {
            if (myToken !== translateToken) return;
            translating = false;
            if (tDone >= srcCues.length) setLangNote(langLabel + " subtitles ready");
          });
        })
        .catch(function (err) {
          if (myToken !== translateToken) return;
          translating = false;
          setLangNote(err && err.message ? err.message : "Translation failed.");
        });
    }

    function worker(myToken, langCode, langLabel) {
      if (myToken !== translateToken) return Promise.resolve();
      if (tNext >= srcCues.length) return Promise.resolve();

      var chunk = srcCues.slice(tNext, tNext + CHUNK);
      tNext += CHUNK;
      var t0 = Math.max(0, chunk[0].start - 0.05);
      var t1 = chunk[chunk.length - 1].end + 0.05;
      var englishUrl = currentEnglishUrl();
      var params = new URLSearchParams({
        url: englishUrl, lang: langCode, animeId: animeId, ep: ep,
        sessionId: sessionId, t0: String(t0), t1: String(t1),
      });
      var ref = currentReferer();
      if (ref) params.set("ref", ref);

      return requestWithRetry(params, myToken)
        .then(function (text) {
          if (myToken !== translateToken) return;
          parseVtt(text).forEach(function (c) { byStart.set(Number(c.start.toFixed(3)), c); });
          tDone += chunk.length;
          applyCaptionSource();
          setLangNote("Translating to " + langLabel + "… " + tDone + "/" + srcCues.length + " cues");
          return worker(myToken, langCode, langLabel);
        })
        .catch(function (err) {
          if (myToken !== translateToken) return;
          setLangNote(err && err.message ? err.message : "Translation request failed.");
        });
    }

    function requestWithRetry(params, myToken) {
      var attempt = 0;
      function attemptOnce() {
        if (myToken !== translateToken) return Promise.reject(new Error("cancelled"));
        return fetch("/api/stream/subtitle/translate?" + params.toString())
          .then(function (res) {
            if (res.ok) return res.text();
            return res.clone().json().catch(function () { return {}; }).then(function (body) {
              var err = new Error((body && (body.error || body.code)) || "Translation failed (" + res.status + ")");
              err.status = res.status;
              err.retryAfter = res.headers.get("retry-after");
              throw err;
            });
          })
          .catch(function (err) {
            attempt++;
            if (err && err.status === 429 && attempt <= 3) {
              var ra = err.retryAfter ? Number(err.retryAfter) * 1000 : 0;
              var wait = ra || Math.min(15000, 2000 * attempt);
              setLangNote("Rate limited, retrying… (" + attempt + "/3)");
              return new Promise(function (r) { setTimeout(r, wait); }).then(attemptOnce);
            }
            if ((!err || !err.status || err.status >= 500) && attempt <= 2 && err.message !== "cancelled") {
              return new Promise(function (r) { setTimeout(r, 1200 * attempt); }).then(attemptOnce);
            }
            throw err;
          });
      }
      return attemptOnce();
    }

    // Build the merged cue list (English fallback + any translated cues in
    // byStart) and hand it to setTrack() to render via the caption overlay.
    function applyCaptionSource() {
      var merged = [];
      for (var i = 0; i < srcCues.length; i++) {
        var c = srcCues[i];
        var hit = findHit(c.start);
        merged.push({ id: c.id, start: c.start, end: c.end, text: hit ? hit.text : c.text });
      }
      setTrack(merged);
    }

    function findHit(start) {
      var k = Number(start.toFixed(3));
      if (byStart.has(k)) return byStart.get(k);
      var best = null, bestDiff = 0.06;
      byStart.forEach(function (v, tk) {
        var d = Math.abs(tk - k);
        if (d <= bestDiff) { bestDiff = d; best = v; }
      });
      return best;
    }

    // ---------- autohide ----------

    var keyboardFocus = function () {
      var a = document.activeElement;
      return !!a && a !== root && root.contains(a) && a.matches(":focus-visible");
    };
    function wake() {
      root.removeAttribute("data-idle");
      clearTimeout(idle);
      if (!root.hasAttribute("data-autohide") || video.paused) return;
      idle = setTimeout(function () {
        if (!video.paused && !keyboardFocus()) root.setAttribute("data-idle", "");
      }, 2500);
    }

    // ---------- fullscreen ----------

    var fsElement = function () { return document.fullscreenElement || document.webkitFullscreenElement; };
    var fullscreen = function () {
      if (fsElement() === root) (document.exitFullscreen || document.webkitExitFullscreen).call(document);
      else if (root.requestFullscreen) root.requestFullscreen().catch(function () {});
      else if (root.webkitRequestFullscreen) root.webkitRequestFullscreen();
      else if (video.webkitEnterFullscreen) video.webkitEnterFullscreen();
    };
    var fsSync = function () {
      var on = fsElement() === root;
      root.toggleAttribute("data-fullscreen", on);
      if (fs) fs.setAttribute("aria-label", on ? "Exit full screen" : "Full screen");
    };

    // ---------- wiring ----------

    plays.forEach(function (b) { b.addEventListener("click", toggle); });
    root.querySelectorAll("[data-skip]").forEach(function (b) {
      b.addEventListener("click", function () { jump(video.currentTime + +b.dataset.skip); });
    });
    root.addEventListener("pointerdown", function (e) { pointer = e.pointerType; }, true);
    video.addEventListener("click", function () {
      if (pointer !== "mouse" && root.hasAttribute("data-idle")) return wake();
      toggle();
      if (!root.contains(document.activeElement)) root.focus({ preventScroll: true });
    });
    video.addEventListener("play", function () { state(); tick(); cancelAnimationFrame(capRaf); capLoop(); wake(); });
    video.addEventListener("pause", function () { state(); cancelAnimationFrame(raf); cancelAnimationFrame(capRaf); sync(); paintCaptions(); wake(); });
    video.addEventListener("loadedmetadata", function () { meta(); });
    video.addEventListener("progress", buffered);
    video.addEventListener("seeked", function () { sync(); buffered(); paintCaptions(); });
    video.addEventListener("volumechange", function () {
      var v = video.muted ? 0 : video.volume;
      root.style.setProperty("--v", v);
      if (vol) { vol.value = v; vol.setAttribute("aria-valuetext", Math.round(v * 100) + "%"); }
      if (mute) mute.setAttribute("aria-pressed", String(v === 0));
    });
    video.addEventListener("ratechange", function () {
      if (speed) (speed.querySelector("[data-speed-val]") || speed).textContent = video.playbackRate + "×";
    });
    if (seek) seek.addEventListener("input", function () { jump(+seek.value); });
    if (vol) vol.addEventListener("input", function () { video.volume = +vol.value; video.muted = +vol.value === 0; });
    if (mute) mute.addEventListener("click", function () {
      if (video.muted || video.volume === 0) { video.muted = false; if (video.volume === 0) video.volume = 0.6; }
      else video.muted = true;
    });
    if (cc) cc.addEventListener("click", function () {
      var on = !ccOn();
      cc.setAttribute("aria-pressed", String(on));
      // Show/hide the custom caption overlay; cues are painted from currentCues.
      paintCaptions();
    });
    if (capSetOpen) capSetOpen.addEventListener("click", function () {
      if (capSetPanel) { capSetPanel.hidden = !capSetPanel.hidden; if (!capSetPanel.hidden) syncCapPanel(); }
    });
    if (capSetClose) capSetClose.addEventListener("click", function () { if (capSetPanel) capSetPanel.hidden = true; });
    if (capSetReset) capSetReset.addEventListener("click", function () {
      capSet = Object.assign({}, CAP_DEFAULT); saveCapSettings(); syncCapPanel(); applyCapSettings();
    });
    // Build colour swatches once.
    (function buildColors() {
      var wrap = root.querySelector("[data-cap-colors]");
      if (!wrap) return;
      wrap.innerHTML = "";
      CAP_COLORS.forEach(function (c) {
        var b = document.createElement("button");
        b.type = "button"; b.className = "vp-capset-swatch"; b.style.background = c;
        b.setAttribute("data-color", c); b.setAttribute("aria-label", "Colour " + c);
        b.addEventListener("click", function () { capSet.color = c; saveCapSettings(); syncCapPanel(); applyCapSettings(); });
        wrap.appendChild(b);
      });
      var lab = document.createElement("label");
      lab.className = "vp-capset-swatch vp-capset-swatch-custom";
      lab.innerHTML = '<input type="color" aria-label="Custom colour">';
      var inp = lab.querySelector("input");
      inp.value = capSet.color;
      inp.addEventListener("input", function () { capSet.color = inp.value; saveCapSettings(); syncCapPanel(); applyCapSettings(); });
      wrap.appendChild(lab);
    })();
    // Tabs.
    root.querySelectorAll("[data-captab]").forEach(function (tab) {
      tab.addEventListener("click", function () {
        root.querySelectorAll("[data-captab]").forEach(function (t) { t.classList.toggle("is-on", t === tab); });
        root.querySelectorAll("[data-cap-pane]").forEach(function (p) { p.hidden = p.getAttribute("data-cap-pane") !== tab.getAttribute("data-captab"); });
      });
    });
    var capSize = $("[data-cap-size]"), capPos = $("[data-cap-pos]");
    if (capSize) capSize.addEventListener("input", function () { capSet.size = +capSize.value; saveCapSettings(); syncCapPanel(); applyCapSettings(); });
    if (capPos) capPos.addEventListener("input", function () { capSet.position = +capPos.value; saveCapSettings(); syncCapPanel(); applyCapSettings(); });
    root.querySelectorAll("[data-bg]").forEach(function (b) {
      b.addEventListener("click", function () { capSet.background = b.getAttribute("data-bg"); saveCapSettings(); syncCapPanel(); applyCapSettings(); });
    });
    root.querySelectorAll("[data-pos]").forEach(function (b) {
      b.addEventListener("click", function () { capSet.position = +b.getAttribute("data-pos"); saveCapSettings(); syncCapPanel(); applyCapSettings(); });
    });
    var capBold = $("[data-cap-bold]"), capItalic = $("[data-cap-italic]");
    if (capBold) capBold.addEventListener("click", function () { capSet.bold = !capSet.bold; saveCapSettings(); syncCapPanel(); applyCapSettings(); });
    if (capItalic) capItalic.addEventListener("click", function () { capSet.italic = !capSet.italic; saveCapSettings(); syncCapPanel(); applyCapSettings(); });
    if (speed) speed.addEventListener("click", function () {
      var rate = RATES[(RATES.indexOf(video.playbackRate) + 1) % RATES.length];
      video.playbackRate = video.defaultPlaybackRate = rate;
    });
    if (fs) {
      fs.hidden = !(root.requestFullscreen || root.webkitRequestFullscreen || video.webkitEnterFullscreen);
      fs.addEventListener("click", fullscreen);
      document.addEventListener("fullscreenchange", fsSync);
      document.addEventListener("webkitfullscreenchange", fsSync);
    }
    if (serverSel) serverSel.addEventListener("change", function () { loadServer(parseInt(serverSel.value, 10) || 0); });
    if (langSel) langSel.addEventListener("change", function () {
      var code = langSel.value;
      var label = langSel.options[langSel.selectedIndex] ? langSel.options[langSel.selectedIndex].text : code;
      doTranslate(code, label);
    });

    ["pointermove", "focusin", "focusout"].forEach(function (t) { root.addEventListener(t, wake); });
    root.addEventListener("pointerleave", function () {
      if (!root.hasAttribute("data-autohide") || video.paused || keyboardFocus()) return;
      clearTimeout(idle);
      idle = setTimeout(function () { root.setAttribute("data-idle", ""); }, 600);
    });
    root.addEventListener("keydown", function (e) {
      if (e.altKey || e.ctrlKey || e.metaKey || e.defaultPrevented) return;
      var t = e.target, k = e.key.length === 1 ? e.key.toLowerCase() : e.key;
      var control = t.matches('button, a[href], select, textarea, input:not([type="range"])');
      wake();
      if (k === "k" || (k === " " && !control)) toggle();
      else if (k === "m") video.muted = !video.muted;
      else if (k === "f" && fs && !fs.hidden) fullscreen();
      else if (k === "c" && cc && !cc.hidden) cc.click();
      else if ((k === "ArrowLeft" || k === "ArrowRight") && (t === seek || !t.matches('input[type="range"]')))
        jump(video.currentTime + (k === "ArrowLeft" ? -5 : 5));
      else return;
      e.preventDefault();
    });

    if (video.readyState > 0) meta();
    state(); fsSync();
    video.dispatchEvent(new Event("volumechange"));
    syncCapPanel();

    // ---------- boot: languages + sources ----------
    fetch("/api/stream/subtitle/languages")
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (langSel && data.languages) {
          data.languages.forEach(function (l) {
            var opt = document.createElement("option");
            opt.value = l.code;
            opt.textContent = l.label;
            langSel.appendChild(opt);
          });
        }
        if (data.configured === false) {
          setLangNote("Translation not configured (GOOGLE_TRANSLATE_API_KEY missing). Original captions still available.");
        }
      })
      .catch(function () {})
      .then(function () { loadSources(); });

    return { root: root, video: video, jump: jump, start: start, toggle: toggle, onTime: function (fn) { listeners.push(fn); } };
  }

  document.querySelectorAll('[data-vp="cb"]').forEach(vpPlayer);
})();