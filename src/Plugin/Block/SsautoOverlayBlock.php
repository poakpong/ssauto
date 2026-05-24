<?php

declare(strict_types=1);

namespace Drupal\ssauto\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Smart Search Autocomplete overlay block.
 *
 * @Block(
 *   id = "ssauto_overlay",
 *   admin_label = @Translation("Smart Search Autocomplete Overlay"),
 *   category = @Translation("Smart Search"),
 * )
 */
final class SsautoOverlayBlock extends BlockBase implements ContainerFactoryPluginInterface {

  private ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->configFactory = $container->get('config.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $theme = $this->configFactory->get('ssauto.settings')->get('theme') ?? 'dark';

    return [
      '#theme'          => 'ssauto_block',
      '#attached'       => [
        'library'       => ['ssauto/ssauto-overlay'],
        'drupalSettings' => [
          'ssauto' => [
            'autocompleteUrl' => '/api/ssauto/autocomplete',
            'searchPageUrl'   => '/smart-search',
            'theme'           => $theme,
          ],
        ],
      ],
      '#cache' => [
        'max-age' => Cache::PERMANENT,
        'tags'    => ['config:ssauto.settings'],
      ],
    ];
  }

}
