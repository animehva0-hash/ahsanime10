# AHSANIME — PHP Edition

A plain-PHP (no framework, no build step) port of the AHSANIME anime
streaming site. It talks to the **AniList GraphQL API** for catalogue data and
embeds streams through **MegaPlay** (`sub` / `hardsub` / `dub`).

It is a faithful conversion of the original Next.js app's core: home page with
spotlight carousel + rows, anime detail pages, episode grids, watch pages with a
player-mode switch, search, discover, genre/browse listings, navbar and footer —
all server-rendered in PHP.

## Requirements

- PHP 8.1+ (tested on 8.5)
- No Composer dependencies.
- `curl` **or** the stream fallback (works without any extension thanks to a
  built-in `file_get_contents` fallback). `mbstring` is optional (shimmed).
- Outbound HTTPS access to `graphql.anilist.co` and `megaplay.buzz`.

## Run locally

From this `ahsanime-php/` directory:

```bash
php -S localhost:8080 router.php
```

Then open <http://localhost:8080>.

`router.php` is the dev-server front controller (serves real files under
`/public`, routes everything else through `index.php`).

## Run on Apache

The included `.htaccess` rewrites all non-file requests to `index.php`. Point
the vhost `DocumentRoot` at this directory (with `AllowOverride All`) and the
site works as-is. `mod_expires` is used for static-asset caching when present.

## Run on nginx

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
```

## Project layout

```
ahsanime-php/
├── index.php                 # Front controller + router + API endpoints
├── router.php                # PHP built-in server router
├── config.php                # Constants (AniList URL, MegaPlay base, TTLs)
├── .htaccess                 # Apache rewrite rules
├── app/
│   ├── Anilist.php           # GraphQL client: file cache, pacing, stale fallback
│   ├── queries.php           # GraphQL queries + data access (home/search/detail/suggest)
│   ├── sources.php           # MegaPlay embed URL builder + player modes
│   ├── constants.php         # GENRES, FORMATS, discover config
│   ├── helpers.php           # scoreLabel, cleanDescription, episodeTotal, mb shims
│   └── controllers/
│       └── pages.php         # render_home / search / discover / browse / anime / watch
├── views/                    # Server-rendered templates
│   ├── home.php              # Spotlight carousel + rows
│   ├── anime.php             # Anime detail + episode grid + relations + characters
│   ├── watch.php             # Player (MegaPlay) + episode rail + mode switch
│   ├── browse.php            # Listing grid + pagination
│   ├── notfound.php / error.php
│   └── partials/             # header, footer, card, row
├── public/
│   ├── css/style.css         # Bespoke classes (shell, glass, animations)
│   ├── js/main.js            # Spotlight carousel + player-mode switch
│   └── logo1.png
└── cache/                    # Generated AniList response cache (writable)
```

## Routes

| Path                          | Purpose                              |
| ----------------------------- | ------------------------------------ |
| `/`                           | Home (trending, seasonal, etc.)      |
| `/anime/{id}`                 | Anime detail                         |
| `/watch/{id}/{episode}`       | Watch page (MegaPlay embed)          |
| `/search?q=`                  | Search                               |
| `/discover/{kind}`            | trending/updated/added/popular/upcoming/ongoing/complete |
| `/browse`                     | Filterable listing (genre/format/status/sort) |
| `/genre/{name}`               | Genre listing                        |
| `/random`                     | Redirect handled via API             |
| `/api/suggest?q=`             | Navbar autocomplete JSON             |
| `/api/random`                 | Random anime id JSON                 |
| `/api/health`                 | Health check JSON                    |

## Notes

- **Caching**: AniList responses are cached under `cache/` with a 10–20 min
  TTL and a 7-day stale fallback, so the site stays up during AniList rate
  limits (90/min, often degraded). Make sure `cache/` is writable by the web
  server user.
- **Styling** uses the Tailwind Play CDN plus a small custom stylesheet, to
  mirror the original Tailwind-based UI without a build pipeline. For
  production, compile Tailwind and self-host the assets.
- **Subtitles**: the watch page relies on MegaPlay's own SUB / Hard Sub / DUB
  tracks. The Next.js version's Gemini subtitle-translation overlay is not
  portable to the cross-origin iframe player, so it is intentionally omitted
  here.
- **Logo**: served from `public/logo1.png` (copied from the original `public/`).