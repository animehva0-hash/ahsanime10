<?php
/** Home page. Expects $data (home data), $pageTitle, $activeNav. */
$data = $data ?? [];
$spotlight = array_slice(array_values(array_filter(
    array_merge($data['trending'] ?? [], $data['seasonNow'] ?? []),
    static fn($m) => !empty($m['bannerImage']) || !empty($m['coverImage']['extraLarge'])
)), 0, 6);

$pageTitle = SITE_NAME . ' — Watch Anime Online';
$activeNav = '/';
include __DIR__ . '/partials/header.php';
?>
<div class="shell py-5">
  <?php if ($spotlight): ?>
    <section id="spotlight" class="relative -mx-[var(--gutter)] overflow-hidden sm:mx-0 sm:rounded-[2rem] sm:border sm:border-white/10" data-slides='<?= e(json_encode(array_map(static fn($m) => [
        "id" => $m["id"],
        "banner" => $m["bannerImage"] ?? $m["coverImage"]["extraLarge"] ?? $m["coverImage"]["large"] ?? null,
        "cover" => $m["coverImage"]["extraLarge"] ?? $m["coverImage"]["large"] ?? null,
        "color" => $m["coverImage"]["color"] ?? "#ff8a00",
        "title" => displayTitle($m["title"]),
        "romaji" => $m["title"]["romaji"] ?? null,
        "english" => $m["title"]["english"] ?? null,
        "score" => scoreLabel($m["averageScore"] ?? null),
        "format" => formatLabel($m["format"] ?? null),
        "year" => $m["seasonYear"] ?? "TBA",
        "episodes" => $m["episodes"] ?? null,
        "desc" => cleanDescription($m["description"] ?? null, 280),
    ], $spotlight))) ?>'>
      <?php foreach ($spotlight as $i => $m):
        $img = $m['bannerImage'] ?? $m['coverImage']['extraLarge'] ?? $m['coverImage']['large'] ?? null;
        $color = $m['coverImage']['color'] ?? '#ff8a00';
        $title = displayTitle($m['title']);
        $score = scoreLabel($m['averageScore'] ?? null);
      ?>
        <div class="absolute inset-0 transition-opacity duration-[1200ms] ease-out <?= $i === 0 ? 'opacity-100' : 'pointer-events-none opacity-0' ?>" data-slide="<?= $i ?>">
          <?php if ($img): ?>
            <img src="<?= e($img) ?>" alt="" class="h-full w-full object-cover <?= $i === 0 ? 'animate-ken-burns' : '' ?>" />
          <?php endif; ?>
          <div class="absolute inset-0 bg-gradient-to-r from-[#07070d] via-[#07070d]/70 to-[#07070d]/10"></div>
          <div class="absolute inset-0 bg-gradient-to-t from-[#07070d] via-transparent to-[#07070d]/40"></div>
          <div class="absolute inset-0 opacity-30 mix-blend-overlay" style="background: radial-gradient(900px 400px at 70% 20%, <?= e($color) ?>55, transparent 60%)"></div>
        </div>
        <div class="absolute inset-0 mx-auto flex max-w-[1440px] items-end px-4 pb-14 sm:items-center sm:px-6 sm:pb-0 lg:px-8 <?= $i === 0 ? '' : 'hidden' ?>" data-slide-content="<?= $i ?>">
          <div class="max-w-2xl animate-fade-up">
            <div class="mb-2 flex items-center gap-2 text-[10px] font-bold uppercase tracking-[0.3em] text-[#ffb86b] sm:mb-3">
              <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2c1 4-2 5-2 8a2 2 0 0 0 4 0c0-1 0-2 1-3 1 2 3 4 3 7a6 6 0 1 1-12 0c0-4 3-7 6-12z"/></svg>
              #<?= $i + 1 ?> Trending
            </div>
            <h1 class="font-display line-clamp-2 font-bold leading-[1.02] tracking-tight text-white drop-shadow-xl sm:line-clamp-none" style="font-size: var(--fs-hero)"><?= e($title) ?></h1>
            <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[11px] text-zinc-300 sm:mt-4 sm:gap-x-4 sm:gap-y-2 sm:text-xs">
              <?php if ($score): ?><span class="flex items-center gap-1 font-semibold text-amber-300"><svg class="h-3.5 w-3.5 fill-amber-300" viewBox="0 0 24 24"><path d="M12 2l3 7 7 .5-5.5 4.5 2 7L12 17l-6.5 4 2-7L2 9.5 9 9z"/></svg> <?= e($score) ?></span><?php endif; ?>
              <span class="flex items-center gap-1.5"><?= e(formatLabel($m['format'] ?? null)) ?></span>
              <span class="flex items-center gap-1.5"><?= e($m['seasonYear'] ?? 'TBA') ?></span>
              <?php if (!empty($m['episodes'])): ?><span class="rounded-full border border-white/15 px-2 py-0.5 text-[10px] uppercase tracking-wider text-zinc-300"><?= (int) $m['episodes'] ?> ep</span><?php endif; ?>
            </div>
            <p class="mt-3 line-clamp-2 max-w-xl text-[13px] leading-relaxed text-zinc-400 sm:mt-4 sm:line-clamp-3 sm:text-sm"><?= e(cleanDescription($m['description'] ?? null, 280)) ?></p>
            <div class="mt-4 flex items-center gap-2.5 sm:mt-7 sm:gap-3">
              <a href="/watch/<?= (int) $m['id'] ?>/1" class="pulse-ring group flex items-center gap-2 rounded-full bg-gradient-to-r from-[#ff8a00] to-[#ff3d5a] px-5 py-3 text-sm font-bold text-white shadow-xl shadow-[#ff3d5a]/30 transition-transform duration-300 hover:scale-[1.03] active:scale-95 sm:gap-2.5 sm:px-7 sm:py-3.5">
                <svg class="h-4 w-4 fill-white" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg> Watch
              </a>
              <a href="/anime/<?= (int) $m['id'] ?>" class="glass flex items-center gap-2 rounded-full border border-white/15 px-4 py-3 text-sm font-semibold text-zinc-200 transition-all hover:border-white/40 hover:bg-white/10 sm:px-6 sm:py-3.5">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg> Info
              </a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="absolute bottom-6 right-4 flex items-center gap-2 sm:right-8 lg:right-12">
        <button id="spot-prev" class="grid h-8 w-8 place-items-center rounded-full border border-white/15 bg-black/40 text-zinc-300 backdrop-blur hover:border-[#ff8a00]/60 hover:text-[#ffb86b]" aria-label="Previous">‹</button>
        <?php foreach ($spotlight as $i => $m): ?>
          <button data-go="<?= $i ?>" aria-label="Slide <?= $i + 1 ?>" class="hidden sm:block h-11 w-8 overflow-hidden rounded-md border transition-all duration-300 <?= $i === 0 ? 'scale-110 border-[#ff8a00] shadow-lg' : 'border-white/20 opacity-50 hover:opacity-90' ?>">
            <img src="<?= e($m['coverImage']['extraLarge'] ?? $m['coverImage']['large'] ?? $m['bannerImage']) ?>" alt="" class="h-full w-full object-cover" />
          </button>
        <?php endforeach; ?>
        <button id="spot-next" class="grid h-8 w-8 place-items-center rounded-full border border-white/15 bg-black/40 text-zinc-300 backdrop-blur hover:border-[#ff8a00]/60 hover:text-[#ffb86b]" aria-label="Next">›</button>
      </div>
      <div class="absolute bottom-7 left-4 flex gap-1.5 sm:hidden" id="spot-dots">
        <?php foreach ($spotlight as $i => $m): ?>
          <span class="h-1 rounded-full transition-all duration-500 <?= $i === 0 ? 'w-6 bg-[#ff8a00]' : 'w-1.5 bg-white/30' ?>"></span>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <div class="mt-8">
    <?php
      $title = 'Trending Now'; $items = $data['trending'] ?? []; $href = '/discover/trending'; include __DIR__ . '/partials/row.php';
      $title = 'Recently Updated'; $items = $data['latestUpdated'] ?? []; $href = '/discover/updated'; include __DIR__ . '/partials/row.php';
      $title = 'This Season'; $items = $data['seasonNow'] ?? []; $href = '/discover/ongoing'; include __DIR__ . '/partials/row.php';
      $title = 'Top Rated'; $items = $data['topAllTime'] ?? []; $href = '/discover/popular'; include __DIR__ . '/partials/row.php';
      $title = 'Upcoming'; $items = $data['upcoming'] ?? []; $href = '/discover/upcoming'; include __DIR__ . '/partials/row.php';
    ?>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>