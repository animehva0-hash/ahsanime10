<?php
/** Anime detail page. Expects $media (MediaDetail array), $pageTitle. */
$media = $media ?? [];
$id = (int) ($media['id'] ?? 0);
$total = episodeTotal($media);
$score = scoreLabel($media['averageScore'] ?? null);
$color = $media['coverImage']['color'] ?? '#ff8a00';
$coverArt = $media['coverImage']['extraLarge'] ?? $media['coverImage']['large'] ?? null;
$watchHref = ($media['status'] === 'NOT_YET_RELEASED' && $total === 0) ? null : "/watch/{$id}/1";
$title = displayTitle($media['title']);
$pageTitle = $title . ' — ' . SITE_NAME;
$activeNav = '';
include __DIR__ . '/partials/header.php';

$recs = [];
foreach (($media['recommendations']['nodes'] ?? []) as $n) {
    if (!empty($n['mediaRecommendation'])) $recs[] = $n['mediaRecommendation'];
}
?>
<div class="animate-fade-in">
  <div class="relative h-[38svh] min-h-[220px] overflow-hidden sm:h-[320px] lg:h-[400px]">
    <?php if ($coverArt): ?>
      <img src="<?= e($coverArt) ?>" alt="" class="h-full w-full scale-110 object-cover object-top blur-[2px] sm:blur-0" />
      <div class="absolute inset-0 bg-gradient-to-t from-[#07070d] via-[#07070d]/55 to-[#07070d]/25"></div>
    <?php else: ?>
      <div class="h-full w-full" style="background: linear-gradient(135deg, <?= e($color) ?>44, #0d0d18 60%)"></div>
    <?php endif; ?>
    <div class="absolute inset-0 bg-gradient-to-t from-[#07070d] via-[#07070d]/40 to-transparent"></div>
    <div class="absolute inset-0 opacity-40" style="background: radial-gradient(800px 300px at 20% 100%, <?= e($color) ?>33, transparent 60%)"></div>
  </div>

  <div class="shell">
    <div class="-mt-20 flex flex-col gap-5 sm:-mt-24 md:-mt-28 md:flex-row md:gap-8">
      <div class="shrink-0">
        <?php if ($coverArt): ?>
          <img src="<?= e($coverArt) ?>" alt="<?= e($title) ?>" class="w-40 rounded-2xl border border-white/15 object-cover shadow-2xl sm:w-52 md:w-56" style="box-shadow: 0 25px 60px -15px <?= e($color) ?>55" />
        <?php endif; ?>
      </div>

      <div class="min-w-0 flex-1 md:pt-28">
        <?php if (!empty($media['title']['native'])): ?>
          <p class="mb-1 text-xs tracking-[0.25em] text-zinc-500"><?= e($media['title']['native']) ?></p>
        <?php endif; ?>
        <h1 class="font-display text-3xl font-bold leading-tight tracking-tight text-white sm:text-4xl"><?= e($title) ?></h1>

        <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
          <?php if ($score): ?>
            <span class="flex items-center gap-1.5 rounded-full bg-amber-400/10 px-3 py-1.5 font-bold text-amber-300">
              <svg class="h-3.5 w-3.5 fill-amber-300" viewBox="0 0 24 24"><path d="M12 2l3 7 7 .5-5.5 4.5 2 7L12 17l-6.5 4 2-7L2 9.5 9 9z"/></svg> <?= e($score) ?>
            </span>
          <?php endif; ?>
          <?php
            $stCls = $media['status'] === 'RELEASING' ? 'bg-emerald-400/10 text-emerald-300'
                  : ($media['status'] === 'NOT_YET_RELEASED' ? 'bg-sky-400/10 text-sky-300' : 'bg-white/10 text-zinc-300');
          ?>
          <span class="rounded-full px-3 py-1.5 font-semibold <?= $stCls ?>"><?= e(statusLabel($media['status'] ?? null)) ?></span>
          <span class="rounded-full bg-white/10 px-3 py-1.5 font-semibold text-zinc-300"><?= e(formatLabel($media['format'] ?? null)) ?></span>
          <span class="flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1.5 text-zinc-300">
            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 2 2 7 7 3-7 3-2 7-2-7-7-3 7-3z"/></svg>
            <?php if ($media['status'] === 'RELEASING' && !empty($media['nextAiringEpisode'])): ?>
              EP <?= (int) $media['nextAiringEpisode']['episode'] - 1 ?> aired
            <?php elseif ($total): ?>
              <?= $total ?> episodes
            <?php else: ?>
              Episodes TBA
            <?php endif; ?>
          </span>
          <?php if (!empty($media['duration'])): ?>
            <span class="flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1.5 text-zinc-300"><?= (int) $media['duration'] ?> min</span>
          <?php endif; ?>
        </div>

        <?php if (!empty($media['genres'])): ?>
          <div class="mt-3 flex flex-wrap gap-1.5">
            <?php foreach ($media['genres'] as $g): ?>
              <a href="/genre/<?= e(urlencode($g)) ?>" class="rounded-full border border-white/10 px-3 py-1 text-[11px] text-zinc-400 transition-colors hover:border-[#ff8a00]/50 hover:text-[#ffb86b]"><?= e($g) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="mt-6 flex flex-wrap items-center gap-3">
          <?php if ($watchHref): ?>
            <a href="<?= e($watchHref) ?>" class="group flex items-center gap-2.5 rounded-full bg-gradient-to-r from-[#ff8a00] to-[#ff3d5a] px-7 py-3.5 text-sm font-bold text-white shadow-xl shadow-[#ff3d5a]/30 transition-transform hover:scale-[1.03] active:scale-95">
              <svg class="h-4 w-4 fill-white" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
              <?= $media['format'] === 'MOVIE' ? 'Watch Movie' : 'Start Watching' ?>
            </a>
          <?php endif; ?>
          <?php if (($media['trailer']['site'] ?? null) === 'youtube' && !empty($media['trailer']['id'])): ?>
            <a href="https://www.youtube.com/watch?v=<?= e($media['trailer']['id']) ?>" target="_blank" rel="noreferrer" class="glass flex items-center gap-2 rounded-full border border-white/15 px-5 py-3.5 text-sm font-semibold text-zinc-200 transition-all hover:border-red-400/50 hover:text-red-300">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m10 9 5 3-5 3z"/></svg> Trailer
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="mt-8 grid gap-8 sm:mt-12 lg:grid-cols-[1fr_300px]">
      <div class="min-w-0">
        <h2 class="font-display mb-4 text-xl font-bold tracking-tight text-white">Synopsis</h2>
        <p class="whitespace-pre-line text-sm leading-relaxed text-zinc-400"><?= e(cleanDescription($media['description'] ?? null)) ?></p>

        <?php if ($total > 0 && $media['status'] !== 'NOT_YET_RELEASED'): ?>
          <div class="mt-12">
            <div class="mb-4 flex items-center justify-between">
              <h2 class="font-display text-xl font-bold tracking-tight text-white">Episodes</h2>
              <span class="text-xs text-zinc-500"><?= $total ?> available<?= ($media['status'] === 'RELEASING' && !empty($media['episodes'])) ? ' of ' . (int) $media['episodes'] : '' ?></span>
            </div>
            <div class="grid grid-cols-3 gap-1.5 sm:grid-cols-4 sm:gap-2 md:grid-cols-6 lg:grid-cols-8">
              <?php for ($ep = 1; $ep <= $total; $ep++): ?>
                <a href="/watch/<?= $id ?>/<?= $ep ?>" class="flex h-10 w-full items-center justify-center rounded-xl border border-white/10 bg-white/[0.04] text-[11px] font-bold text-zinc-400 transition-all duration-300 hover:-translate-y-0.5 hover:border-[#ff8a00]/50 hover:text-white"><?= $ep ?></a>
              <?php endfor; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if (!empty($media['relations']['edges'])): ?>
          <div class="mt-12">
            <h2 class="font-display mb-4 text-xl font-bold tracking-tight text-white">Relations</h2>
            <div class="row-track no-scrollbar flex gap-3 overflow-x-auto pb-2">
              <?php foreach ($media['relations']['edges'] as $edge): $rm = $edge['node']; ?>
                <a href="/anime/<?= (int) $rm['id'] ?>" class="group flex w-[300px] shrink-0 items-center gap-3 overflow-hidden rounded-2xl border border-white/10 bg-white/[0.03] p-2 transition-all hover:border-[#ff8a00]/40 hover:bg-white/[0.06]">
                  <img src="<?= e($rm['coverImage']['extraLarge'] ?? $rm['coverImage']['large'] ?? '') ?>" alt="" class="h-16 w-12 shrink-0 rounded-lg object-cover" />
                  <div class="min-w-0">
                    <p class="text-[9px] font-bold uppercase tracking-[0.2em] text-[#ffb86b]"><?= e(str_replace('_', ' ', $edge['relationType'] ?? 'Related')) ?></p>
                    <p class="mt-1 line-clamp-2 block text-xs font-semibold text-zinc-200 group-hover:text-white"><?= e(displayTitle($rm['title'])) ?></p>
                    <p class="mt-1 text-[10px] uppercase tracking-wider text-zinc-500"><?= e(formatLabel($rm['format'] ?? null)) ?> • <?= e($rm['seasonYear'] ?? 'TBA') ?></p>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if (!empty($media['characters']['edges'])): ?>
          <div class="mt-12">
            <h2 class="font-display mb-4 text-xl font-bold tracking-tight text-white">Characters</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
              <?php foreach (array_slice($media['characters']['edges'], 0, 8) as $edge): $node = $edge['node']; ?>
                <div class="flex items-center gap-3 rounded-2xl border border-white/5 bg-white/[0.03] p-2.5">
                  <img src="<?= e($node['image']['medium'] ?? '') ?>" alt="" class="h-11 w-11 shrink-0 rounded-full border border-white/10 object-cover" />
                  <div class="min-w-0">
                    <p class="truncate text-xs font-semibold text-zinc-200"><?= e($node['name']['full'] ?? '') ?></p>
                    <p class="truncate text-[10px] text-zinc-500"><?= ($edge['role'] ?? '') === 'MAIN' ? 'Main' : 'Supporting' ?><?= !empty($edge['voiceActors'][0]['name']['full']) ? ' • ' . e($edge['voiceActors'][0]['name']['full']) : '' ?></p>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <aside class="space-y-3">
        <h3 class="font-display mb-4 text-lg font-bold tracking-tight text-white">Details</h3>
        <?php
        $stats = [];
        if (!empty($media['popularity'])) $stats[] = ['Popularity', '#' . compactNumber($media['popularity']) . ' users'];
        if (!empty($media['favourites'])) $stats[] = ['Favourites', compactNumber($media['favourites'])];
        $stats[] = ['Aired', dateLabel($media['startDate'] ?? null) . ' → ' . (($media['status'] === 'RELEASING') ? '…' : dateLabel($media['endDate'] ?? null))];
        if (!empty($media['season'])) $stats[] = ['Season', seasonLabel($media['season'], $media['seasonYear'] ?? null)];
        if (!empty($media['countryOfOrigin'])) $stats[] = ['Origin', $media['countryOfOrigin']];
        if (!empty($media['source'])) $stats[] = ['Source', formatLabel($media['source'])];
        if (!empty($media['studios']['nodes'][0]['name'])) $stats[] = ['Studio', $media['studios']['nodes'][0]['name']];
        foreach ($stats as [$label, $value]):
        ?>
          <div class="rounded-xl border border-white/5 bg-white/[0.03] p-3">
            <p class="text-[9px] font-bold uppercase tracking-[0.2em] text-zinc-500"><?= e($label) ?></p>
            <p class="mt-1.5 truncate text-sm font-semibold text-zinc-200"><?= e($value) ?></p>
          </div>
        <?php endforeach; ?>

        <?php if (!empty($media['synonyms'])): ?>
          <div class="rounded-xl border border-white/5 bg-white/[0.03] p-3">
            <p class="text-[9px] font-bold uppercase tracking-[0.2em] text-zinc-500">Synonyms</p>
            <p class="mt-1.5 text-xs leading-relaxed text-zinc-400"><?= e(implode(' • ', array_slice($media['synonyms'], 0, 4))) ?></p>
          </div>
        <?php endif; ?>

        <?php if (!empty($media['tags'])): ?>
          <div class="flex flex-wrap gap-1.5 pt-1">
            <?php foreach (array_slice($media['tags'], 0, 10) as $t): ?>
              <span class="rounded-full bg-white/5 px-2.5 py-1 text-[10px] text-zinc-500"><?= e($t['name']) ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </aside>
    </div>

    <?php if ($recs): $title = 'You Might Also Like'; $items = array_slice($recs, 0, 12); $href = null; include __DIR__ . '/partials/row.php'; endif; ?>
    <div class="h-12"></div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>