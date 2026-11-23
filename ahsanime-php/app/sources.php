<?php

declare(strict_types=1);

/** Streaming provider: MegaPlay embed. */

const PLAYER_MODES = [
    ['id' => 'sub',     'label' => 'SUB',      'hint' => 'Soft subtitles'],
    ['id' => 'hardsub', 'label' => 'Hard Sub', 'hint' => 'Burnt-in subtitles'],
    ['id' => 'dub',     'label' => 'DUB',      'hint' => 'English voiceover'],
];

function megaPlayUrl(int $anilistId, int $episode, string $mode): string
{
    return MEGAPLAY_BASE . "/{$anilistId}/{$episode}/{$mode}";
}

function isSubtitleMode(string $mode): bool
{
    return $mode === 'sub';
}