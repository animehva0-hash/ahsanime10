<?php
/** Horizontal row of cards. Expects $title (string), $items (array), optional $href (see-all link). */
$items = $items ?? [];
if (empty($items)) return;
?>
<section class="mb-8">
  <div class="mb-3 flex items-end justify-between gap-3">
    <h2 class="font-display text-lg font-bold tracking-tight text-white sm:text-xl"><?= e($title ?? '') ?></h2>
    <?php if (!empty($href)): ?>
      <a href="<?= e($href) ?>" class="shrink-0 text-[11px] font-semibold uppercase tracking-wider text-[#ffb86b] hover:underline">View all</a>
    <?php endif; ?>
  </div>
  <div class="row-track no-scrollbar flex gap-3 overflow-x-auto pb-2">
    <?php foreach ($items as $m): ?>
      <div class="w-[150px] shrink-0 sm:w-[168px]">
        <?php $m = $m; include __DIR__ . '/card.php'; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>