<?php

declare(strict_types=1);

namespace Drupal\ssauto\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ssauto\Service\SsautoIndexService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns JSON autocomplete suggestions from the ssauto index.
 */
final class SsautoAutocompleteController extends ControllerBase {

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
   * Handles GET /api/ssauto/autocomplete?q={keyword}.
   *
   * Returns: [{ value, label, url }, ...]
   */
  public function autocomplete(Request $request): JsonResponse {
    $keyword = (string) $request->query->get('q', '');
    $suggestions = $this->indexService->autocomplete($keyword);

    $dateFormatter = \Drupal::service('date.formatter');
    $data = array_map(
      fn(array $item) => [
        'value' => $item['title'],
        'label' => $item['title'],
        'url'   => $item['url'],
        'date'  => !empty($item['created'])
          ? $dateFormatter->format((int) $item['created'], 'custom', 'j M Y')
          : '',
      ],
      $suggestions
    );

    $response = new JsonResponse($data);
    $response->setPrivate();
    $response->setMaxAge(0);

    return $response;
  }

}
