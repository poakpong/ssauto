<?php

declare(strict_types=1);

namespace Drupal\ssauto\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ssauto\Service\SsautoIndexService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the Smart Search Autocomplete module.
 */
class SsautoCommands extends DrushCommands {

  public function __construct(
    private readonly SsautoIndexService $indexService,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Rebuilds the ssauto search index for all published nodes.
   *
   * @command ssauto:rebuild
   * @aliases ssr
   * @usage drush ssauto:rebuild
   *   Rebuilds the full index in chunks of 500.
   */
  public function rebuild(): void {
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $nids = $nodeStorage->getQuery()
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->execute();

    $nids   = array_values($nids);
    $total  = count($nids);
    $chunks = array_chunk($nids, 500);
    $done   = 0;
    $batch  = 0;
    $numBatches = count($chunks);

    if ($total === 0) {
      $this->output()->writeln('<comment>No published nodes found.</comment>');
      return;
    }

    $this->output()->writeln(sprintf('<info>Rebuilding ssauto index for %d nodes in %d batches...</info>', $total, $numBatches));

    foreach ($chunks as $chunk) {
      $batch++;
      $this->indexService->buildIndex($chunk);
      $done += count($chunk);

      // Release entity storage cache after every chunk.
      $nodeStorage->resetCache($chunk);

      $percent = (int) round(($done / $total) * 100);
      $this->output()->writeln(sprintf('  Batch %d/%d (%d%%)', $batch, $numBatches, $percent));
    }

    $this->output()->writeln(sprintf('<info>Done. Indexed %d nodes.</info>', $done));
  }

}
