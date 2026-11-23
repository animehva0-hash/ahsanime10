<?php
$pageTitle = 'Error — ' . SITE_NAME;
$activeNav = '';
include __DIR__ . '/partials/header.php';
?>
<div class="shell flex min-h-[60vh] flex-col items-center justify-center py-20 text-center">
  <p class="font-display text-4xl font-bold text-white">Something went wrong</p>
  <p class="mt-3 max-w-lg text-sm text-zinc-400">The upstream anime source is rate-limited or temporarily unavailable. Please try again in a moment.</p>
  <?php if (!empty($error) && getenv('APP_DEBUG')): ?><pre class="mt-4 max-w-lg overflow-auto rounded-lg bg-black/40 p-3 text-left text-[11px] text-zinc-500"><?= e($error) ?></pre><?php endif; ?>
  <a href="/" class="mt-6 rounded-full bg-gradient-to-r from-[#ff8a00] to-[#ff3d5a] px-6 py-3 text-sm font-bold text-white">Back to Home</a>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>