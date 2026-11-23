<?php
/** Browse / listing page. Expects $heading, $blurb, $items, $pageInfo, $query (array for pagination), $activeNav. */
$items = $items ?? [];
$pageInfo = $pageInfo ?? null;
$curPage = (int) ($pageInfo['currentPage'] ?? 1);
$lastPage = (int) ($pageInfo['lastPage'] ?? 1);
$total = $pageInfo['total'] ?? null;

// Build pagination base query string
$q = $query ?? [];
$pageTitle = ($heading ?? 'Browse') . ' — ' . SITE_NAME;
include __DIR__ . '/partials/header.php';

$baseQs = http_build_query(array_filter($q, static fn($v) => $v !== '' && $v !== null));
?>
<div class="shell py-6 animate-fade-in">
  <div class="mb-6">
    <h1 class="font-display text-2xl font-bold tracking-tight text-white sm:text-3xl"><?= e($heading ?? 'Browse') ?></h1>
    <?php if (!empty($blurb)): ?><p class="mt-2 max-w-2xl text-sm text-zinc-400"><?= e($blurb) ?></p><?php endif; ?>
    <?php if ($total !== null): ?><p class="mt-2 text-xs text-zinc-500"><?= number_format((int) $total) ?> results</p><?php endif; ?>
  </div>

  <?php if (empty($items)): ?>
    <div class="rounded-2xl border border-white/5 bg-white/[0.02] p-12 text-center text-sm text-zinc-500">No results found.</div>
  <?php else: ?>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
      <?php foreach ($items as $m): ?>
        <?php include __DIR__ . '/partials/card.php'; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($lastPage > 1): ?>
    <div class="mt-8 flex items-center justify-center gap-2">
      <?php if ($curPage > 1): ?>
        <a href="?<?= e($baseQs ? $baseQs . '&' : '') ?>page=<?= $curPage - 1 ?>" class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-zinc-300 hover:border-[#ff8a00]/50 hover:text-white">Prev</a>
      <?php endif; ?>
      <span class="px-3 text-xs text-zinc-400">Page <?= $curPage ?> / <?= $lastPage ?></span>
      <?php if ($curPage < $lastPage): ?>
        <a href="?<?= e($baseQs ? $baseQs . '&' : '') ?>page=<?= $curPage + 1 ?>" class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-zinc-300 hover:border-[#ff8a00]/50 hover:text-white">Next</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <div class="h-10"></div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>