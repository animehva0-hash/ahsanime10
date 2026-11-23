<?php
/** Watch page. Expects $media, $episode, $total, $pageTitle. */
$media = $media ?? [];
$id = (int) ($media['id'] ?? 0);
$ep = (int) ($episode ?? 1);
$isMovie = ($media['format'] ?? null) === 'MOVIE';
$title = displayTitle($media['title']);
$color = $media['coverImage']['color'] ?? '#ff8a00';
$cover = $media['coverImage']['extraLarge'] ?? $media['coverImage']['large'] ?? null;
$pageTitle = $title . ' — Episode ' . $ep . ' — ' . SITE_NAME;
$activeNav = '';
$pageScripts = [
    'https://cdn.jsdelivr.net/npm/hls.js@latest/dist/hls.min.js',
    '/public/js/player.js?v=4',
];
include __DIR__ . '/partials/header.php';

// Progressive chapter chunks for the episode rail.
$chunkSize = 100;
$ranges = [];
for ($s = 1; $s <= $total; $s += $chunkSize) {
    $ranges[] = ['label' => $s . ' – ' . min($total, $s + $chunkSize - 1), 'start' => $s, 'end' => min($total, $s + $chunkSize - 1)];
}
$curRange = 0;
foreach ($ranges as $i => $r) { if ($ep >= $r['start'] && $ep <= $r['end']) { $curRange = $i; break; } }
?>
<div class="shell py-6 animate-fade-in">
  <div class="mb-5 flex flex-wrap items-center gap-4">
    <a href="/anime/<?= $id ?>" class="group flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-zinc-300 transition-all hover:border-[#ff8a00]/50 hover:text-white">
      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg> Back
    </a>
    <div class="min-w-0">
      <h1 class="font-display truncate text-lg font-bold tracking-tight text-white sm:text-xl"><?= e($title) ?></h1>
      <p class="mt-0.5 flex items-center gap-2 text-[11px] uppercase tracking-wider text-zinc-500">
        <?= e(formatLabel($media['format'] ?? null)) ?> • <?= $isMovie ? 'Movie' : 'Episode ' . $ep ?><?= ($total > 0 && !$isMovie) ? ' of ' . $total : '' ?>
      </p>
    </div>
  </div>

  <div class="flex flex-col gap-4 lg:grid lg:grid-cols-[1fr_300px] lg:gap-6 xl:grid-cols-[1fr_340px]">
    <div class="min-w-0">
      <!-- Mode switch -->
      <div class="mb-3 flex items-center gap-2" id="mode-switch">
        <?php foreach (PLAYER_MODES as $mode): ?>
          <button data-mode="<?= e($mode['id']) ?>" class="mode-btn rounded-full px-4 py-1.5 text-xs font-semibold transition-all <?= $mode['id'] === 'sub' ? 'bg-gradient-to-r from-[#ff8a00] to-[#ff3d5a] text-white' : 'border border-white/10 bg-white/5 text-zinc-400' ?>">
            <?= e($mode['label']) ?>
          </button>
        <?php endforeach; ?>
      </div>

      <!-- Native HLS player (custom control bars, adapted from uiCookies .vp) -->
      <div id="hls-player"
           class="vp vp-overlay"
           data-vp="cb"
           data-title="<?= e($title) ?>"
           data-anime-id="<?= $id ?>"
           data-ep="<?= $ep ?>"
           data-autohide
           role="group"
           aria-label="<?= e($title) ?> video player"
           tabindex="-1">
        <video id="player-video" preload="metadata" playsinline crossorigin="anonymous"></video>

        <!-- Custom caption overlay (DOM text driven by video.currentTime — renders
             reliably over HLS/MSE/mp4, styled like YouTube). Translated cues land here. -->
        <div class="vp-captions" data-captions hidden aria-live="off"></div>

        <!-- Caption appearance settings (style + position), persisted per browser -->
        <div class="vp-capset" data-capset hidden>
          <div class="vp-capset-head">
            <span class="vp-capset-title">Caption Style</span>
            <div class="vp-capset-headbtns">
              <button type="button" class="vp-capset-ico" data-capreset title="Reset to defaults" aria-label="Reset caption style">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
              </button>
              <button type="button" class="vp-capset-ico" data-capclose title="Close" aria-label="Close caption settings">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
              </button>
            </div>
          </div>
          <div class="vp-capset-tabs" data-captabs>
            <button type="button" class="vp-capset-tab is-on" data-captab="style">Style</button>
            <button type="button" class="vp-capset-tab" data-captab="position">Position</button>
          </div>
          <div class="vp-capset-body">
            <!-- Style tab -->
            <div class="vp-capset-pane" data-cap-pane="style">
              <div class="vp-capset-field">
                <div class="vp-capset-label"><span>Font Size</span><b data-cap-sizeval>100%</b></div>
                <input type="range" class="vp-capset-range" data-cap-size min="0.6" max="2.2" step="0.05" aria-label="Caption font size" />
              </div>
              <div class="vp-capset-field">
                <label class="vp-capset-label">Text Colour</label>
                <div class="vp-capset-colors" data-cap-colors></div>
              </div>
              <div class="vp-capset-field">
                <label class="vp-capset-label">Background</label>
                <div class="vp-capset-seg" data-cap-bg>
                  <button type="button" data-bg="shadow">Outline</button>
                  <button type="button" data-bg="box">Box</button>
                  <button type="button" data-bg="none">None</button>
                </div>
              </div>
              <div class="vp-capset-field vp-capset-row2">
                <button type="button" class="vp-capset-toggle" data-cap-bold>B</button>
                <button type="button" class="vp-capset-toggle is-italic" data-cap-italic>I</button>
              </div>
            </div>
            <!-- Position tab -->
            <div class="vp-capset-pane" data-cap-pane="position" hidden>
              <div class="vp-capset-field">
                <div class="vp-capset-label"><span>Vertical Position</span><b data-cap-posval>84%</b></div>
                <input type="range" class="vp-capset-range" data-cap-pos min="20" max="96" step="1" aria-label="Caption vertical position" />
                <p class="vp-capset-hint">Lower % moves captions up, higher % toward the bottom.</p>
              </div>
              <div class="vp-capset-field">
                <label class="vp-capset-label">Quick Presets</label>
                <div class="vp-capset-seg vp-capset-presets" data-cap-presets>
                  <button type="button" data-pos="22">Top</button>
                  <button type="button" data-pos="55">Middle</button>
                  <button type="button" data-pos="78">Lower</button>
                  <button type="button" data-pos="84">Bottom</button>
                </div>
              </div>
              <div class="vp-capset-preview">
                <span class="vp-capset-label">Preview</span>
                <p class="vp-capset-previewtext" data-cap-preview>Yaar, ye subtitle aisa dikhega!</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Loading / status -->
        <div class="vp-status" data-status>
          <div class="vp-spin"></div>
          <p class="vp-msg" data-status-msg>Loading episode…</p>
        </div>

        <!-- Top bar: server (quality) + translate language selectors -->
        <div class="vp-top" data-top>
          <select class="vp-server" data-server aria-label="Server" title="Server"></select>
          <select class="vp-lang" data-lang aria-label="Subtitle language" title="Subtitle language">
            <option value="">Original (English)</option>
          </select>
          <p class="vp-note" data-lang-note hidden></p>
        </div>

        <!-- Overlay control bar -->
        <div class="vp-controls">
          <input type="range" class="vp-seek" data-seek min="0" max="16" step="0.1" value="0"
                 aria-label="Seek" aria-valuetext="0:00 of 0:16" />
          <div class="vp-row">
            <button type="button" class="vp-btn" data-play aria-label="Play <?= e($title) ?>"><svg class="i-play" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="currentColor"><path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.5-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5z"/></svg><svg class="i-pause" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="currentColor"><rect x="6.5" y="5" width="4" height="14" rx="1.2"/><rect x="13.5" y="5" width="4" height="14" rx="1.2"/></svg></button>
            <button type="button" class="vp-btn vp-skip" data-skip="-10" aria-label="Back 10 seconds"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1"/><path d="M3.5 4v4.5H8"/><text x="12.6" y="15.4" fill="currentColor" stroke="none" font-size="7" font-weight="700" text-anchor="middle" font-family="system-ui,sans-serif">10</text></svg></button>
            <button type="button" class="vp-btn vp-skip" data-skip="10" aria-label="Forward 10 seconds"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 12a8.5 8.5 0 1 1-2.6-6.1"/><path d="M20.5 4v4.5H16"/><text x="11.4" y="15.4" fill="currentColor" stroke="none" font-size="7" font-weight="700" text-anchor="middle" font-family="system-ui,sans-serif">10</text></svg></button>
            <button type="button" class="vp-btn vp-mute" data-mute aria-label="Mute" aria-pressed="false"><svg class="i-vol" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H3v6h3l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"/></svg><svg class="i-muted" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H3v6h3l5 4z"/><path d="m16 9 6 6m0-6-6 6"/></svg></button>
            <input type="range" class="vp-vol" data-vol min="0" max="1" step="0.05" value="1" aria-label="Volume" aria-valuetext="100%">
            <span class="vp-time"><span data-cur>0:00</span> / <span data-dur>0:16</span></span>
            <span class="vp-spacer"></span>
            <button type="button" class="vp-btn vp-speed" data-speed><span class="vp-sr">Playback speed </span><span data-speed-val>1×</span></button>
            <button type="button" class="vp-btn vp-cc" data-cc aria-label="Captions" aria-pressed="true"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="M10.5 10.2a2.3 2.3 0 1 0 0 3.6M16.5 10.2a2.3 2.3 0 1 0 0 3.6"/></svg></button>
            <button type="button" class="vp-btn vp-capset-btn" data-capset-open aria-label="Caption settings" title="Caption style & position"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15.5A3.5 3.5 0 1 0 12 8.5a3.5 3.5 0 0 0 0 7z"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></button>
            <button type="button" class="vp-btn vp-fs" data-fs aria-label="Full screen"><svg class="i-expand" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg><svg class="i-shrink" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5"/></svg></button>
          </div>
        </div>
      </div>
      <p class="mt-2 text-center text-[10px] text-zinc-600">Native HLS · Translated subtitles powered by Gemini · No media hosted on this site.</p>

      <div class="mt-4 flex flex-wrap gap-2">
        <a href="/anime/<?= $id ?>" class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-zinc-300 hover:border-[#ff8a00]/50 hover:text-white">Details</a>
        <a href="/search?q=<?= e(urlencode($title)) ?>" class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-zinc-300 hover:border-[#ff8a00]/50 hover:text-white">More like this</a>
      </div>
    </div>

    <!-- Episode rail -->
    <aside class="min-w-0">
      <div class="rounded-2xl border border-white/5 bg-white/[0.02] p-4">
        <div class="mb-4 flex items-center gap-3">
          <?php if ($cover): ?><img src="<?= e($cover) ?>" alt="" class="h-16 w-12 rounded-lg object-cover" style="box-shadow: 0 8px 24px -8px <?= e($color) ?>66" /><?php endif; ?>
          <div>
            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-[#ffb86b]">Now Playing</p>
            <p class="mt-1 text-sm font-bold text-white"><?= $isMovie ? 'Movie' : 'EP ' . $ep ?></p>
          </div>
        </div>
        <?php if ($total > 1): ?>
          <div class="mb-3" id="episode-rail" data-id="<?= $id ?>" data-ep="<?= $ep ?>">
            <div class="ep-grid max-h-[500px] grid grid-cols-2 gap-1.5 overflow-y-auto pr-1 sm:grid-cols-4">
              <?php $range = $ranges[$curRange]; for ($e = $range['start']; $e <= $range['end']; $e++):
                $isCurrent = $e === $ep; $isLatest = $total === $e; ?>
                <a href="/watch/<?= $id ?>/<?= $e ?>" class="relative flex h-10 w-full items-center justify-center rounded-xl border text-[11px] font-bold transition-all duration-300 <?= $isCurrent ? 'border-[#ff8a00] bg-gradient-to-br from-[#ff8a00] to-[#ff3d5a] text-white shadow-lg' : 'border-white/10 bg-white/[0.04] text-zinc-400 hover:-translate-y-0.5 hover:border-[#ff8a00]/50 hover:text-white' ?>">
                  <?= $e ?>
                  <?php if ($isLatest && $total > 1): ?><span class="absolute -right-1 -top-1 grid h-4 w-4 place-items-center rounded-full bg-[#ff3d5a] text-[8px] text-white">●</span><?php endif; ?>
                </a>
              <?php endfor; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </aside>
  </div>
  <div class="h-10"></div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>