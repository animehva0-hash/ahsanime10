<?php
/** Anime card partial. Expects $m (MediaCard array). */
$m = $m ?? [];
$id = $m['id'] ?? 0;
$title = $m['title']['english'] ?? $m['title']['romaji'] ?? 'Unknown';
$romaji = $m['title']['romaji'] ?? null;
$english = $m['title']['english'] ?? null;
$img = $m['coverImage']['extraLarge'] ?? $m['coverImage']['large'] ?? null;
$score = scoreLabel($m['averageScore'] ?? null);
$fmt = formatLabel($m['format'] ?? null);
$year = $m['seasonYear'] ?? 'TBA';
?>
<a href="/anime/<?= (int) $id ?>" class="group relative block overflow-hidden rounded-xl bg-zinc-900 ring-1 ring-white/5 transition-all duration-300 hover:-translate-y-1 hover:ring-[#ff8a00]/50">
  <div class="relative aspect-[2/3] overflow-hidden">
    <?php if ($img): ?>
      <img src="<?= e($img) ?>" alt="<?= e($title) ?>" loading="lazy" class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105" />
    <?php else: ?>
      <div class="h-full w-full bg-gradient-to-br from-zinc-800 to-zinc-900"></div>
    <?php endif; ?>
    <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/10 to-transparent"></div>
    <?php if ($score): ?>
      <span class="absolute left-2 top-2 flex items-center gap-0.5 rounded-md bg-black/60 px-1.5 py-0.5 text-[10px] font-bold text-amber-300 backdrop-blur">
        <svg class="h-2.5 w-2.5 fill-amber-300" viewBox="0 0 24 24"><path d="M12 2l3 7 7 .5-5.5 4.5 2 7L12 17l-6.5 4 2-7L2 9.5 9 9z"/></svg>
        <?= e($score) ?>
      </span>
    <?php endif; ?>
    <span class="absolute right-2 top-2 rounded-md bg-black/60 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wider text-zinc-300 backdrop-blur"><?= e($fmt) ?></span>
    <div class="absolute inset-x-0 bottom-0 p-2.5">
      <p class="line-clamp-2 text-xs font-semibold leading-snug text-white"><?= e($title) ?></p>
      <?php if ($romaji && $english && $romaji !== $english): ?>
        <p class="mt-0.5 line-clamp-1 text-[10px] text-zinc-400"><?= e($romaji) ?></p>
      <?php endif; ?>
    </div>
  </div>
</a>