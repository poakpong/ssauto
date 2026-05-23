<?php

declare(strict_types=1);

namespace Drupal\ssauto\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ssauto\Service\SsautoIndexService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Renders the search results page at /smart-search.
 */
final class SsautoResultsController extends ControllerBase {

  private const ITEMS_PER_PAGE = 10;

  public function __construct(
    private readonly SsautoIndexService $indexService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ssauto.index_service'),
    );
  }

  /**
   * Renders /smart-search?q={keyword}&page={n}.
   */
  public function results(Request $request): array {
    $keyword = trim((string) $request->query->get('q', ''));
    $page    = max(0, (int) $request->query->get('page', 0));
    $offset  = $page * self::ITEMS_PER_PAGE;

    $data = ['items' => [], 'total' => 0];
    if ($keyword !== '') {
      $data = $this->indexService->search($keyword, self::ITEMS_PER_PAGE, $offset);
    }

    return [
      '#theme'    => 'ssauto_results',
      '#keyword'  => $keyword,
      '#results'  => $data['items'],
      '#total'    => $data['total'],
      '#page'     => $page,
      '#limit'    => self::ITEMS_PER_PAGE,
      '#cache'    => [
        'tags'    => ['ssauto_index'],
        'contexts' => ['url.query_args'],
      ],
    ];
  }

}
