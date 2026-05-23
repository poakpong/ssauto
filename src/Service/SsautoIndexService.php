<?php

declare(strict_types=1);

namespace Drupal\ssauto\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides indexing, autocomplete, and search capabilities for ssauto.
 */
final class SsautoIndexService {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheBackendInterface $cache,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Builds (or rebuilds) the search index for the given node IDs.
   *
   * If $nids is empty, indexes all published nodes.
   */
  public function buildIndex(array $nids = []): int {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $aliasManager = \Drupal::service('path_alias.manager');

    if (empty($nids)) {
      $nids = $nodeStorage->getQuery()
        ->condition('status', 1)
        ->accessCheck(FALSE)
        ->execute();
      $nids = array_values($nids);
    }

    $count = 0;
    $chunks = array_chunk($nids, 500);

    foreach ($chunks as $chunk) {
      $nodes = $nodeStorage->loadMultiple($chunk);

      foreach ($nodes as $node) {
        if (!$node->isPublished()) {
          continue;
        }

        $nid = (int) $node->id();

        // Build plain-text summary from body field.
        $summary = '';
        if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
          $bodyValue = $node->get('body')->value ?? '';
          $summary = mb_substr(strip_tags($bodyValue), 0, 300);
        }

        // Collect taxonomy tag names from field_tags.
        $tags = '';
        if ($node->hasField('field_tags') && !$node->get('field_tags')->isEmpty()) {
          $termNames = [];
          foreach ($node->get('field_tags')->referencedEntities() as $term) {
            $termNames[] = $term->label();
          }
          $tags = implode(' ', $termNames);
        }

        // Resolve URL alias.
        $internalPath = '/node/' . $nid;
        try {
          $alias = $aliasManager->getAliasByPath($internalPath);
        }
        catch (\Exception) {
          $alias = $internalPath;
        }
        $url = $alias ?: $internalPath;

        $this->database->merge('ssauto_index')
          ->key('nid')
          ->fields([
            'nid'     => $nid,
            'title'   => mb_substr((string) $node->label(), 0, 512),
            'summary' => $summary,
            'tags'    => mb_substr($tags, 0, 1024),
            'url'     => mb_substr($url, 0, 512),
            'changed' => (int) $node->getChangedTime(),
          ])
          ->execute();

        $count++;
      }

      // Release entity cache every 500 nodes to prevent memory exhaustion.
      $nodeStorage->resetCache($chunk);
    }

    $this->invalidateCache();

    return $count;
  }

  /**
   * Returns autocomplete suggestions for a keyword (title FULLTEXT only).
   *
   * @return array<int, array{nid: int, title: string, url: string}>
   */
  public function autocomplete(string $keyword, int $limit = 0): array {
    $config  = $this->configFactory->get('ssauto.settings');
    $limit   = $limit > 0 ? $limit : ((int) $config->get('autocomplete_limit') ?: 8);
    $minLen  = (int) $config->get('min_keyword_length') ?: 2;

    $keyword = trim($keyword);
    if (mb_strlen($keyword) < $minLen) {
      return [];
    }

    $cacheKey = 'ssauto:ac:' . md5(mb_substr(strtolower($keyword), 0, 9));
    $cacheTtl = mb_strlen($keyword) <= 4 ? 3600 : 300;

    $cached = $this->cache->get($cacheKey);
    if ($cached !== FALSE) {
      return $cached->data;
    }

    $results = $this->runAutocompleteFulltext($keyword, $limit);

    // Fallback: LIKE on title when FULLTEXT returns nothing.
    if (empty($results)) {
      $results = $this->runAutocompleteLike($keyword, $limit);
    }

    $this->cache->set($cacheKey, $results, time() + $cacheTtl, ['ssauto_index']);

    return $results;
  }

  /**
   * Full-text search across title, summary, and tags with relevance scoring.
   *
   * @return array{items: list<array{nid: int, title: string, url: string, summary: string, tags: string, score: float}>, total: int}
   */
  public function search(string $keyword, int $limit = 10, int $offset = 0): array {
    $keyword = trim($keyword);
    if ($keyword === '') {
      return ['items' => [], 'total' => 0];
    }

    $cacheKey = 'ssauto:search:' . md5($keyword . ':' . $limit . ':' . $offset);
    $cached = $this->cache->get($cacheKey);
    if ($cached !== FALSE) {
      return $cached->data;
    }

    $result = $this->runSearch($keyword, $limit, $offset);

    $this->cache->set($cacheKey, $result, time() + 300, ['ssauto_index']);

    return $result;
  }

  /**
   * Removes a single node from the index and invalidates cache.
   */
  public function removeFromIndex(int $nid): void {
    $this->database->delete('ssauto_index')
      ->condition('nid', $nid)
      ->execute();

    $this->invalidateCache();
  }

  /**
   * Invalidates all ssauto cache entries via cache tag.
   */
  public function invalidateCache(): void {
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['ssauto_index']);
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  /**
   * Runs a FULLTEXT search on the title column only (autocomplete fast-path).
   *
   * @return array<int, array{nid: int, title: string, url: string}>
   */
  private function runAutocompleteFulltext(string $keyword, int $limit): array {
    try {
      $rows = $this->database->query(
        "SELECT nid, title, url
         FROM {ssauto_index}
         WHERE MATCH(title) AGAINST (:kw IN BOOLEAN MODE)
         LIMIT :limit",
        [':kw' => $keyword . '*', ':limit' => $limit]
      )->fetchAll();

      return array_map(
        fn($row) => ['nid' => (int) $row->nid, 'title' => $row->title, 'url' => $row->url],
        $rows
      );
    }
    catch (\Exception) {
      return [];
    }
  }

  /**
   * Fallback LIKE search on title when FULLTEXT returns no results.
   *
   * @return array<int, array{nid: int, title: string, url: string}>
   */
  private function runAutocompleteLike(string $keyword, int $limit): array {
    try {
      $rows = $this->database->select('ssauto_index', 's')
        ->fields('s', ['nid', 'title', 'url'])
        ->condition('title', '%' . $this->database->escapeLike($keyword) . '%', 'LIKE')
        ->range(0, $limit)
        ->execute()
        ->fetchAll();

      return array_map(
        fn($row) => ['nid' => (int) $row->nid, 'title' => $row->title, 'url' => $row->url],
        $rows
      );
    }
    catch (\Exception) {
      return [];
    }
  }

  /**
   * Runs the full FULLTEXT search with title-boost scoring.
   *
   * @return array{items: list<array{nid: int, title: string, url: string, summary: string, tags: string, score: float}>, total: int}
   */
  private function runSearch(string $keyword, int $limit, int $offset): array {
    try {
      $boolKeyword = $keyword . '*';

      // Total count query.
      $total = (int) $this->database->query(
        "SELECT COUNT(*) FROM {ssauto_index}
         WHERE MATCH(title, summary, tags) AGAINST (:kw IN BOOLEAN MODE)",
        [':kw' => $boolKeyword]
      )->fetchField();

      if ($total === 0) {
        return ['items' => [], 'total' => 0];
      }

      // Scored results: title match weighted 3× for relevance boost.
      $rows = $this->database->query(
        "SELECT nid, title, url, summary, tags,
                (MATCH(title) AGAINST (:kw IN BOOLEAN MODE) * 3
                 + MATCH(title, summary, tags) AGAINST (:kw2 IN BOOLEAN MODE)) AS score
         FROM {ssauto_index}
         WHERE MATCH(title, summary, tags) AGAINST (:kw3 IN BOOLEAN MODE)
         ORDER BY score DESC
         LIMIT :limit OFFSET :offset",
        [
          ':kw'     => $boolKeyword,
          ':kw2'    => $boolKeyword,
          ':kw3'    => $boolKeyword,
          ':limit'  => $limit,
          ':offset' => $offset,
        ]
      )->fetchAll();

      $items = array_map(
        fn($row) => [
          'nid'     => (int) $row->nid,
          'title'   => $row->title,
          'url'     => $row->url,
          'summary' => $row->summary,
          'tags'    => $row->tags,
          'score'   => (float) $row->score,
        ],
        $rows
      );

      return ['items' => $items, 'total' => $total];
    }
    catch (\Exception $e) {
      \Drupal::logger('ssauto')->error('Search query failed: @msg', ['@msg' => $e->getMessage()]);
      return ['items' => [], 'total' => 0];
    }
  }

}
