<?php

declare(strict_types=1);

/** AniList GraphQL queries + typed data-access helpers. */

const CARD_FIELDS = <<<GQL
  id
  idMal
  title { romaji english native }
  coverImage { extraLarge large color }
  bannerImage
  format
  status
  episodes
  duration
  averageScore
  popularity
  seasonYear
  season
  genres
  description(asHtml: false)
  nextAiringEpisode { airingAt episode timeUntilAiring }
  trending
  startDate { year month day }
GQL;

function gqlFragment(): string
{
    return "fragment Card on Media {\n" . CARD_FIELDS . "\n}";
}

function getHomeData(): array
{
    $season = seasonFor();
    $query = <<<GQL
    query Home(\$season: MediaSeason, \$seasonYear: Int) {
      trending: Page(page: 1, perPage: 12) {
        media(type: ANIME, sort: TRENDING_DESC, isAdult: false) { ...Card }
      }
      latestUpdated: Page(page: 1, perPage: 12) {
        media(type: ANIME, status: RELEASING, sort: UPDATED_AT_DESC, isAdult: false) { ...Card }
      }
      seasonNow: Page(page: 1, perPage: 12) {
        media(type: ANIME, season: \$season, seasonYear: \$seasonYear, sort: POPULARITY_DESC, isAdult: false) { ...Card }
      }
      upcoming: Page(page: 1, perPage: 12) {
        media(type: ANIME, status: NOT_YET_RELEASED, sort: POPULARITY_DESC, isAdult: false) { ...Card }
      }
      topAllTime: Page(page: 1, perPage: 10) {
        media(type: ANIME, sort: SCORE_DESC, isAdult: false) { ...Card }
      }
    }
    GQL;
    $query .= gqlFragment();

    $data = Anilist::query($query, [
        'season' => $season['season'],
        'seasonYear' => $season['year'],
    ], 60 * 20);

    return [
        'trending'      => $data['trending']['media'] ?? [],
        'latestUpdated' => $data['latestUpdated']['media'] ?? [],
        'seasonNow'     => $data['seasonNow']['media'] ?? [],
        'upcoming'      => $data['upcoming']['media'] ?? [],
        'topAllTime'    => $data['topAllTime']['media'] ?? [],
        'season'        => $season['season'],
        'seasonYear'    => $season['year'],
    ];
}

function searchMedia(array $params): array
{
    $ttl = $params['ttl'] ?? 600;
    unset($params['ttl']);

    $vars = array_merge(['page' => 1, 'perPage' => PER_PAGE], $params);

    $query = <<<GQL
    query Search(
      \$page: Int
      \$perPage: Int
      \$search: String
      \$genre: String
      \$format: MediaFormat
      \$status: MediaStatus
      \$sort: [MediaSort]
      \$season: MediaSeason
      \$seasonYear: Int
    ) {
      Page(page: \$page, perPage: \$perPage) {
        pageInfo { total currentPage lastPage hasNextPage perPage }
        media(
          type: ANIME
          search: \$search
          genre: \$genre
          format: \$format
          status: \$status
          sort: \$sort
          season: \$season
          seasonYear: \$seasonYear
          isAdult: false
        ) { ...Card }
      }
    }
    GQL;
    $query .= gqlFragment();

    $data = Anilist::query($query, $vars, $ttl);

    return [
        'pageInfo' => $data['Page']['pageInfo'] ?? null,
        'media'    => $data['Page']['media'] ?? [],
    ];
}

function getMediaDetail(int $id): ?array
{
    $query = <<<GQL
    query Detail(\$id: Int) {
      Media(id: \$id, type: ANIME) {
        ...Card
        synonyms
        source(version: 3)
        countryOfOrigin
        favourites
        endDate { year month day }
        tags { name rank }
        trailer { id site thumbnail }
        studios(isMain: true) { nodes { name } }
        externalLinks { site url color }
        relations { edges { relationType(version: 2) node { ...Card } } }
        recommendations(page: 1, perPage: 12, sort: RATING_DESC) {
          nodes { mediaRecommendation { ...Card } }
        }
        characters(page: 1, perPage: 10, sort: [ROLE, RELEVANCE, ID]) {
          edges {
            role
            node { name { full } image { medium } }
            voiceActors(language: JAPANESE, sort: [RELEVANCE, ID]) {
              name { full }
              image { medium }
            }
          }
        }
      }
    }
    GQL;
    $query .= gqlFragment();

    $data = Anilist::query($query, ['id' => $id], 60 * 40);
    return $data['Media'] ?? null;
}

function getSuggestions(string $q): array
{
    $query = <<<GQL
    query Suggest(\$search: String) {
      Page(page: 1, perPage: 8) {
        media(type: ANIME, search: \$search, isAdult: false, sort: [SEARCH_MATCH, POPULARITY_DESC]) {
          id
          idMal
          title { romaji english native }
          coverImage { large color }
          format
          seasonYear
          averageScore
        }
      }
    }
    GQL;

    $data = Anilist::query($query, ['search' => $q], 60 * 5);
    return $data['Page']['media'] ?? [];
}

function getRandomAnimeId(): ?int
{
    $sorts = [
        ['POPULARITY_DESC', 160],
        ['TRENDING_DESC', 40],
        ['SCORE_DESC', 250],
        ['FAVOURITES_DESC', 250],
        ['UPDATED_AT_DESC', 120],
    ];
    $picked = $sorts[array_rand($sorts)];
    $page = 1 + random_int(0, $picked[1] - 1);

    try {
        $res = searchMedia(['page' => $page, 'perPage' => 50, 'sort' => [$picked[0]]]);
        if (!empty($res['media'])) {
            return (int) $res['media'][array_rand($res['media'])]['id'];
        }
    } catch (Throwable $e) {
        // fall through
    }

    $fallback = searchMedia(['page' => 1, 'perPage' => 50, 'sort' => ['TRENDING_DESC']]);
    if (!empty($fallback['media'])) {
        return (int) $fallback['media'][array_rand($fallback['media'])]['id'];
    }
    return null;
}