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
   * Clears all rows from the ssauto search index.
   *
   * After clearing, rebuild immediately with ssauto:rebuild or let cron
   * re-index nodes in batches over successive runs.
   *
   * @command ssauto:clear
   * @aliases ssc
   * @usage drush ssauto:clear
   *   Truncates the ssauto_index table and invalidates caches.
   */
  public function clear(): void {
    $this->indexService->clearIndex();
    $this->output()->writeln('<info>ssauto index cleared.</info>');
    $this->output()->writeln('<comment>Run "drush ssauto:rebuild" to rebuild immediately, or wait for cron.</comment>');
  }

  /**
   * Rebuilds the ssauto search index for all published nodes.
   *
   * Drush runs as CLI so there is no HTTP timeout, but very large sites
   * (10 000+ nodes) can exhaust PHP memory with large batches. Use a
   * smaller --batch-size to trade speed for lower memory usage, or a
   * larger one to finish faster on servers with plenty of RAM.
   *
   * @command ssauto:rebuild
   * @aliases ssr
   * @option batch-size Number of nodes loaded and indexed per batch. Lower
   *   values use less memory; higher values are faster. Default: 500.
   * @usage drush ssauto:rebuild
   *   Rebuild the full index using the default batch size of 500.
   * @usage drush ssauto:rebuild --batch-size=200
   *   Rebuild using smaller batches to reduce memory pressure.
   * @usage drush ssauto:rebuild --batch-size=2000
   *   Rebuild using larger batches for faster throughput on high-RAM servers.
   */
  public function rebuild(array $options = ['batch-size' => 500]): void {
    $batchSize  = max(1, (int) $options['batch-size']);
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $nids = $nodeStorage->getQuery()
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->execute();

    $nids       = array_values($nids);
    $total      = count($nids);
    $chunks     = array_chunk($nids, $batchSize);
    $numBatches = count($chunks);
    $done       = 0;
    $batch      = 0;

    if ($total === 0) {
      $this->output()->writeln('<comment>No published nodes found.</comment>');
      return;
    }

    $this->output()->writeln(sprintf(
      '<info>Rebuilding ssauto index for %d nodes in %d batches (batch size: %d)...</info>',
      $total, $numBatches, $batchSize,
    ));

    foreach ($chunks as $chunk) {
      $batch++;
      $this->indexService->buildIndex($chunk);
      $done += count($chunk);

      // Release entity storage cache after every batch to keep memory flat.
      $nodeStorage->resetCache($chunk);

      $percent = (int) round(($done / $total) * 100);
      $this->output()->writeln(sprintf(
        '  Batch %d/%d — %d/%d nodes (%d%%)',
        $batch, $numBatches, $done, $total, $percent,
      ));
    }

    $this->output()->writeln(sprintf('<info>Done. Indexed %d nodes.</info>', $done));
  }

}
