<?php

declare(strict_types=1);

/** Client-safe constants shared by the UI. */

const GENRES = [
    'Action', 'Adventure', 'Comedy', 'Drama', 'Ecchi', 'Fantasy', 'Horror',
    'Mahou Shoujo', 'Mecha', 'Music', 'Mystery', 'Psychological', 'Romance',
    'Sci-Fi', 'Slice of Life', 'Sports', 'Supernatural', 'Thriller',
];

const FORMATS = [
    ['value' => 'TV', 'label' => 'TV'],
    ['value' => 'MOVIE', 'label' => 'Movie'],
    ['value' => 'OVA', 'label' => 'OVA'],
    ['value' => 'ONA', 'label' => 'ONA'],
    ['value' => 'SPECIAL', 'label' => 'Special'],
    ['value' => 'MUSIC', 'label' => 'Music'],
];

function discoverConfig(string $kind): ?array
{
    $map = [
        'trending' => ['title' => 'Trending Anime', 'sort' => ['TRENDING_DESC'], 'blurb' => 'What the community is watching right now — trending across all of AniList.'],
        'updated'  => ['title' => 'Recently Updated', 'status' => 'RELEASING', 'sort' => ['UPDATED_AT_DESC'], 'blurb' => 'Anime with the latest airing activity.'],
        'added'    => ['title' => 'Recently Added', 'sort' => ['ID_DESC'], 'blurb' => 'Fresh entries added to the AniList database.'],
        'popular'  => ['title' => 'All Time Popular', 'sort' => ['POPULARITY_DESC'], 'blurb' => 'The most tracked anime across the community.'],
        'upcoming' => ['title' => 'Upcoming', 'status' => 'NOT_YET_RELEASED', 'sort' => ['POPULARITY_DESC'], 'blurb' => 'Announced anime arriving soon.'],
        'ongoing'  => ['title' => 'Currently Airing', 'status' => 'RELEASING', 'sort' => ['POPULARITY_DESC'], 'blurb' => 'Ongoing series airing right now.'],
        'complete' => ['title' => 'Completed', 'status' => 'FINISHED', 'sort' => ['POPULARITY_DESC'], 'blurb' => 'Finished series, ready for a full binge.'],
    ];
    return $map[$kind] ?? null;
}