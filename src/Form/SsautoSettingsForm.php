<?php

declare(strict_types=1);

namespace Drupal\ssauto\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

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

    $form['autocomplete_limit'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Autocomplete suggestions'),
      '#description'   => $this->t('Maximum number of suggestions shown in the autocomplete dropdown.'),
      '#default_value' => $config->get('autocomplete_limit'),
      '#min'           => 1,
      '#max'           => 20,
      '#required'      => TRUE,
    ];

    $form['results_per_page'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Results per page'),
      '#description'   => $this->t('Number of search results shown per page on /smart-search.'),
      '#default_value' => $config->get('results_per_page'),
      '#min'           => 1,
      '#max'           => 50,
      '#required'      => TRUE,
    ];

    $form['min_keyword_length'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Minimum keyword length'),
      '#description'   => $this->t('Minimum number of characters required to trigger autocomplete.'),
      '#default_value' => $config->get('min_keyword_length'),
      '#min'           => 1,
      '#max'           => 10,
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
      ->save();

    parent::submitForm($form, $form_state);
  }

}
