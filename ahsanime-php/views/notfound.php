<?php
$pageTitle = 'Not Found — ' . SITE_NAME;
$activeNav = '';
include __DIR__ . '/partials/header.php';
?>
<div class="shell flex min-h-[60vh] flex-col items-center justify-center py-20 text-center">
  <p class="font-display text-6xl font-bold text-white">404</p>
  <p class="mt-3 text-sm text-zinc-400">This page could not be found.</p>
  <a href="/" class="mt-6 rounded-full bg-gradient-to-r from-[#ff8a00] to-[#ff3d5a] px-6 py-3 text-sm font-bold text-white">Back to Home</a>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>