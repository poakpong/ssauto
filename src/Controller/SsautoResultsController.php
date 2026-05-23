<?php

declare(strict_types=1);

namespace Drupal\ssauto\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\ssauto\Service\SsautoIndexService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Renders the search results page at /smart-search.
 */
final class SsautoResultsController extends ControllerBase {

  public function __construct(
    private readonly SsautoIndexService $indexService,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ssauto.index_service'),
      $container->get('config.factory'),
    );
  }

  /**
   * Renders /smart-search?q={keyword}&page={n}.
   */
  public function results(Request $request): array {
    $limit   = (int) $this->configFactory->get('ssauto.settings')->get('results_per_page') ?: 10;
    $keyword = trim((string) $request->query->get('q', ''));
    $page    = max(0, (int) $request->query->get('page', 0));
    $offset  = $page * $limit;

    $data = ['items' => [], 'total' => 0];
    if ($keyword !== '') {
      $data = $this->indexService->search($keyword, $limit, $offset);
    }

    return [
      '#theme'    => 'ssauto_results',
      '#keyword'  => $keyword,
      '#results'  => $data['items'],
      '#total'    => $data['total'],
      '#page'     => $page,
      '#limit'    => $limit,
      '#cache'    => [
        'tags'    => ['ssauto_index'],
        'contexts' => ['url.query_args'],
      ],
    ];
  }

}
