<?php

declare(strict_types=1);

namespace Drupal\ssauto\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;

/**
 * Provides the Smart Search Autocomplete overlay block.
 *
 * @Block(
 *   id = "ssauto_overlay",
 *   admin_label = @Translation("Smart Search Autocomplete Overlay"),
 *   category = @Translation("Smart Search"),
 * )
 */
final class SsautoOverlayBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      '#theme'          => 'ssauto_block',
      '#attached'       => [
        'library'       => ['ssauto/ssauto-overlay'],
        'drupalSettings' => [
          'ssauto' => [
            'autocompleteUrl' => '/api/ssauto/autocomplete',
            'searchPageUrl'   => '/smart-search',
          ],
        ],
      ],
      '#cache' => [
        'max-age' => Cache::PERMANENT,
      ],
    ];
  }

}
