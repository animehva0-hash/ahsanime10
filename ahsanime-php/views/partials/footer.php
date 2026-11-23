</main>
<footer class="mt-10 border-t border-white/5 bg-[#08080f] pb-20 lg:mt-20 lg:pb-0">
  <div class="shell grid gap-8 py-8 sm:py-14 md:grid-cols-[1.6fr_1fr_1fr] md:gap-10">
    <div>
      <div class="flex items-center gap-2.5">
        <img src="/public/logo1.png" alt="AHSANIME" class="h-[36px] rounded-xl sm:h-[40px] lg:w-[40%]" />
      </div>
      <p class="mt-4 max-w-sm text-xs leading-relaxed text-zinc-500">
        Discover and watch anime with a clean, ad-free experience. Titles switch between English and Romaji.
      </p>
      <p class="mt-6 flex items-center gap-1 text-[11px] text-zinc-600">
        Built with ♥ · Data &amp; images courtesy of AniList.co
      </p>
    </div>
    <div>
      <h4 class="mb-4 text-[11px] font-bold uppercase tracking-[0.25em] text-zinc-500">Browse</h4>
      <ul class="space-y-2 text-xs text-zinc-400">
        <li><a class="hover:text-[#ffb86b]" href="/discover/trending">Trending</a></li>
        <li><a class="hover:text-[#ffb86b]" href="/discover/popular">Popular</a></li>
        <li><a class="hover:text-[#ffb86b]" href="/discover/ongoing">Currently Airing</a></li>
        <li><a class="hover:text-[#ffb86b]" href="/discover/complete">Completed</a></li>
      </ul>
    </div>
    <div>
      <h4 class="mb-4 text-[11px] font-bold uppercase tracking-[0.25em] text-zinc-500">Genres</h4>
      <div class="flex flex-wrap gap-1.5">
        <?php foreach (array_slice(GENRES, 0, 12) as $g): ?>
          <a href="/genre/<?= e(urlencode($g)) ?>" class="rounded-full border border-white/10 px-3 py-1 text-[10px] text-zinc-400 transition-colors hover:border-[#ff8a00]/50 hover:text-[#ffb86b]"><?= e($g) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="border-t border-white/5 py-4 text-center text-[10px] tracking-wide text-zinc-600 sm:py-5">
    © <?= date('Y') ?> <?= SITE_NAME ?> — No media is hosted on this site.
  </div>
</footer>
<script src="/public/js/main.js" defer></script>
<?php foreach (($pageScripts ?? []) as $ps): ?>
<script src="<?= e($ps) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>