<?php

declare(strict_types=1);

namespace Drupal\ssauto\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ssauto\Service\SsautoIndexService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin settings form for Smart Search Autocomplete.
 */
final class SsautoSettingsForm extends ConfigFormBase {

  /**
   * Config object name.
   */
  const CONFIG_NAME = 'ssauto.settings';

  /**
   * {@inheritdoc}
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly Connection $database,
    private readonly SsautoIndexService $indexService,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('database'),
      $container->get('ssauto.index_service'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ssauto_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::CONFIG_NAME);

    // -------------------------------------------------------------------------
    // Index status section
    // -------------------------------------------------------------------------
    $indexed = $this->getIndexedCount();
    $total   = $this->getTotalPublishedCount();
    $percent = $total > 0 ? (int) round(($indexed / $total) * 100) : 0;
    $percent = min($percent, 100);

    if ($indexed === 0) {
      $barColor    = '#d32f2f';
      $statusLabel = $this->t('Empty — no nodes indexed yet.');
      $statusType  = 'error';
    }
    elseif ($percent < 100) {
      $barColor    = '#f57c00';
      $statusLabel = $this->t('Partial — some nodes are not yet indexed.');
      $statusType  = 'warning';
    }
    else {
      $barColor    = '#388e3c';
      $statusLabel = $this->t('Complete — all published nodes are indexed.');
      $statusType  = 'status';
    }

    $rebuildNote = $percent < 100
      ? '<p style="margin:8px 0 0;">' . $this->t('Run <code>drush ssauto:rebuild</code> (alias <code>drush ssr</code>) to index all nodes.') . '</p>'
      : '';

    $form['index_status'] = [
      '#type'  => 'details',
      '#title' => $this->t('Index status'),
      '#open'  => TRUE,
    ];

    $form['index_status']['status_message'] = [
      '#type'   => 'item',
      '#markup' => '<div class="messages messages--' . $statusType . '" style="margin:0 0 12px;">'
        . $statusLabel
        . '</div>',
    ];

    $form['index_status']['status_bar'] = [
      '#type'   => 'item',
      '#markup' => '<div style="margin-bottom:6px;">'
        . '<strong>' . $indexed . '</strong> / <strong>' . $total . '</strong> '
        . $this->t('published nodes indexed')
        . ' &nbsp;—&nbsp; <strong>' . $percent . '%</strong>'
        . '</div>'
        . '<div style="background:#e0e0e0;border-radius:6px;height:20px;width:100%;overflow:hidden;" role="progressbar" aria-valuenow="' . $percent . '" aria-valuemin="0" aria-valuemax="100">'
        .   '<div style="background:' . $barColor . ';width:' . $percent . '%;height:100%;display:flex;align-items:center;justify-content:center;transition:width .4s ease;">'
        .     ($percent >= 10 ? '<span style="color:#fff;font-size:11px;font-weight:bold;">' . $percent . '%</span>' : '')
        .   '</div>'
        . '</div>'
        . $rebuildNote,
    ];

    // Pending nodes count.
    $pending = $total - $indexed;
    if ($pending > 0) {
      $form['index_status']['pending_note'] = [
        '#type'   => 'item',
        '#markup' => '<p style="margin:8px 0 0;color:#555;">'
          . $this->t('<strong>@count</strong> node(s) pending — cron will index them automatically.', ['@count' => $pending])
          . '</p>',
      ];
    }

    // Clear-index action — separate from the main Save button so it never
    // triggers settings-field validation.
    $form['index_status']['clear_index'] = [
      '#type'                    => 'submit',
      '#value'                   => $this->t('Clear index'),
      '#submit'                  => ['::clearIndexSubmit'],
      '#limit_validation_errors' => [],
      '#button_type'             => 'danger',
      '#attributes'              => [
        'style'   => 'margin-top:12px;',
        'onclick' => "return confirm('" . $this->t('Delete all indexed data? After clearing you can rebuild with drush ssauto:rebuild or let cron re-index gradually.') . "');",
      ],
    ];

    // -------------------------------------------------------------------------
    // Settings fields
    // -------------------------------------------------------------------------
    $form['settings'] = [
      '#type'  => 'details',
      '#title' => $this->t('Search settings'),
      '#open'  => TRUE,
    ];

    $form['settings']['autocomplete_limit'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Autocomplete suggestions'),
      '#description'   => $this->t('Maximum number of suggestions shown in the autocomplete dropdown.'),
      '#default_value' => $config->get('autocomplete_limit') ?? 8,
      '#min'           => 1,
      '#max'           => 20,
      '#required'      => TRUE,
    ];

    $form['settings']['results_per_page'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Results per page'),
      '#description'   => $this->t('Number of search results shown per page on /smart-search.'),
      '#default_value' => $config->get('results_per_page') ?? 10,
      '#min'           => 1,
      '#max'           => 50,
      '#required'      => TRUE,
    ];

    $form['settings']['min_keyword_length'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Minimum keyword length'),
      '#description'   => $this->t('Minimum number of characters required to trigger autocomplete.'),
      '#default_value' => $config->get('min_keyword_length') ?? 2,
      '#min'           => 1,
      '#max'           => 10,
      '#required'      => TRUE,
    ];

    $form['settings']['cron_batch_size'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Cron batch size'),
      '#description'   => $this->t('Number of nodes indexed per cron run. Lower values reduce server load; higher values catch up faster.'),
      '#default_value' => $config->get('cron_batch_size') ?? 50,
      '#min'           => 10,
      '#max'           => 500,
      '#required'      => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::CONFIG_NAME)
      ->set('autocomplete_limit', (int) $form_state->getValue('autocomplete_limit'))
      ->set('results_per_page', (int) $form_state->getValue('results_per_page'))
      ->set('min_keyword_length', (int) $form_state->getValue('min_keyword_length'))
      ->set('cron_batch_size', (int) $form_state->getValue('cron_batch_size'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Submit handler for the "Clear index" button.
   *
   * Truncates ssauto_index entirely. The user can then rebuild via
   * `drush ssauto:rebuild` for an immediate full rebuild, or simply wait
   * for cron which will re-index nodes in batches automatically.
   */
  public function clearIndexSubmit(array &$form, FormStateInterface $form_state): void {
    $this->indexService->clearIndex();
    $this->messenger()->addWarning(
      $this->t('Index cleared. Run <code>drush ssauto:rebuild</code> to rebuild immediately, or leave cron to re-index all content automatically.')
    );
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  /**
   * Returns the number of rows in the ssauto_index table.
   */
  private function getIndexedCount(): int {
    try {
      return (int) $this->database
        ->select('ssauto_index', 's')
        ->countQuery()
        ->execute()
        ->fetchField();
    }
    catch (\Exception) {
      return 0;
    }
  }

  /**
   * Returns the total number of published nodes on the site.
   */
  private function getTotalPublishedCount(): int {
    try {
      return (int) $this->entityTypeManager
        ->getStorage('node')
        ->getQuery()
        ->condition('status', 1)
        ->accessCheck(FALSE)
        ->count()
        ->execute();
    }
    catch (\Exception) {
      return 0;
    }
  }

}
