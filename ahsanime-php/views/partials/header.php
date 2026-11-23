<?php /** Shared layout header + opening shell. Expects $pageTitle, $activeNav (optional). */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= e($pageTitle ?? SITE_NAME) ?></title>
  <meta name="description" content="<?= e($pageDesc ?? 'Discover and watch anime with a clean, ad-free experience.') ?>" />
  <link rel="icon" href="/public/logo1.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="/public/css/style.css?v=4" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            ink: '#0a0515',
            panel: '#160b24',
            accent: '#8b5cf6',
          },
          fontFamily: {
            display: ['"Space Grotesk"', 'ui-sans-serif', 'sans-serif'],
            sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
          },
          screens: {
            xs: '400px',
          },
        },
      },
    };
  </script>
</head>
<body>
<header class="glass sticky top-0 z-40 border-b border-white/5">
  <div class="shell flex h-14 items-center gap-2 sm:h-16 sm:gap-3">
    <a href="/" class="group flex items-center gap-2.5">
      <img src="/public/logo1.png" alt="AHSANIME" class="lg:w-[80%] h-[40px] relative rounded-xl transition-transform duration-300 group-hover:scale-105" />
    </a>

    <nav class="ml-4 hidden items-center gap-0.5 lg:flex">
      <?php
      $navLinks = [
        '/' => 'Home',
        '/discover/updated' => 'Updated',
        '/discover/added' => 'Added',
        '/discover/popular' => 'Popular',
        '/discover/upcoming' => 'Upcoming',
        '/discover/ongoing' => 'Ongoing',
        '/discover/complete' => 'Complete',
      ];
      $cur = $activeNav ?? '';
      foreach ($navLinks as $href => $label):
        $cls = $cur === $href ? 'rounded-full px-3 py-1.5 text-[13px] font-medium bg-[#ff8a00]/15 text-[#ffb86b]'
                              : 'rounded-full px-3 py-1.5 text-[13px] font-medium text-zinc-400 hover:text-white';
      ?>
        <a href="<?= e($href) ?>" class="<?= $cls ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="ml-auto flex items-center gap-1.5 sm:gap-2">
      <form action="/search" method="get" class="relative hidden lg:block w-56 xl:w-64">
        <svg class="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-zinc-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <input name="q" placeholder="Search anime..." class="w-full rounded-full border border-white/10 bg-white/5 py-2 pl-9 pr-4 text-xs text-zinc-200 placeholder:text-zinc-600 outline-none transition-all duration-300 focus:border-[#ff8a00]/60 focus:bg-white/10" />
      </form>
      <a href="/random" title="Random anime" class="grid h-9 w-9 shrink-0 place-items-center rounded-full border border-white/10 bg-white/5 text-zinc-300 transition-all hover:border-[#ff8a00]/50 hover:text-[#ffb86b]">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 3h5v5"/><path d="M4 20 21 3"/><path d="M21 16v5h-5"/><path d="M15 15l6 6"/><path d="M4 4l5 5"/></svg>
      </a>
      <a href="/search" class="lg:hidden grid h-9 w-9 shrink-0 place-items-center rounded-full border border-white/10 bg-white/5 text-zinc-300" aria-label="Search">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
      </a>
    </div>
  </div>
</header>
<main>