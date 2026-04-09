<?php

namespace Drupal\twilio\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Entity\Webform;

/**
 * Configuration form for Twilio WhatsApp integration.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'twilio_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['twilio.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('twilio.settings');
    $webform_options = $this->getWebformOptions();
    $selected_webform = $form_state->getValue('webform_id') ?? $config->get('whatsapp_webform_id') ?? 'constancia';
    if (!isset($webform_options[$selected_webform]) && $webform_options) {
      $selected_webform = array_key_first($webform_options);
    }

    $field_options = $this->getWebformFieldOptions($selected_webform);
    $field_options = ['whatsapp_phone' => $this->t('Campo adicional: WhatsApp del destinatario')] + $field_options;
    $recipient_field = $form_state->getValue('recipient_field') ?? $config->get('whatsapp_recipient_field') ?? 'whatsapp_phone';

    $form['intro'] = [
      '#type' => 'item',
      '#title' => $this->t('Ajustes del conector'),
      '#description' => $this->t('Configura el envio opcional de notificaciones por WhatsApp a traves de Twilio.'),
    ];

    $form['whatsapp_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Activar notificaciones por WhatsApp'),
      '#default_value' => (bool) $config->get('whatsapp_enabled'),
    ];

    $form['webform_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Webform objetivo'),
      '#options' => $webform_options,
      '#default_value' => $selected_webform,
      '#ajax' => [
        'callback' => '::ajaxRefreshMappings',
        'wrapper' => 'twilio-mappings-wrapper',
      ],
    ];

    $form['recipient_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Campo del numero destinatario'),
      '#options' => $field_options,
      '#default_value' => $recipient_field,
      '#description' => $this->t('Si eliges el campo adicional, el modulo mostrara un nuevo campo opcional en el webform.'),
    ];

    $form['twilio_account_sid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Account SID (Twilio)'),
      '#default_value' => $config->get('twilio_account_sid') ?? '',
    ];

    $form['twilio_auth_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Auth Token (Twilio)'),
      '#default_value' => $config->get('twilio_auth_token') ?? '',
    ];

    $form['twilio_whatsapp_from'] = [
      '#type' => 'textfield',
      '#title' => $this->t('WhatsApp remitente'),
      '#default_value' => $config->get('twilio_whatsapp_from') ?? '',
      '#description' => $this->t('Ejemplo: whatsapp:+14155238886'),
    ];

    $form['twilio_content_template_sid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Template SID (Twilio Content Template)'),
      '#default_value' => $config->get('twilio_content_template_sid') ?? '',
    ];

    $stored_mappings = $config->get('whatsapp_mappings') ?: [];
    $submitted_mappings = $form_state->getValue('mappings');
    $mapping_rows = $form_state->get('whatsapp_mapping_rows');
    if ($mapping_rows === NULL) {
      $mapping_rows = max(count($submitted_mappings ?: $stored_mappings), 1);
      $form_state->set('whatsapp_mapping_rows', $mapping_rows);
    }

    $form['mappings_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'twilio-mappings-wrapper'],
    ];

    $form['mappings_wrapper']['mapping_help'] = [
      '#type' => 'item',
      '#title' => $this->t('Mapeo de variables dinamicas'),
      '#description' => $this->t('Relaciona campos del webform con variables del template de Twilio. Puedes usar valores como {{1}}, {{2}} o cualquier clave definida en tu template.'),
    ];

    $form['mappings_wrapper']['mappings'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Campo del sistema'),
        $this->t('Variable del template'),
      ],
      '#empty' => $this->t('No hay variables configuradas.'),
    ];

    for ($i = 0; $i < $mapping_rows; $i++) {
      $default_mapping = $submitted_mappings[$i] ?? $stored_mappings[$i] ?? [];
      $form['mappings_wrapper']['mappings'][$i]['field'] = [
        '#type' => 'select',
        '#options' => $field_options,
        '#empty_option' => $this->t('- Selecciona -'),
        '#default_value' => $default_mapping['field'] ?? '',
      ];
      $form['mappings_wrapper']['mappings'][$i]['variable'] = [
        '#type' => 'textfield',
        '#default_value' => $default_mapping['variable'] ?? '',
        '#placeholder' => '{{1}}',
      ];
    }

    $form['mappings_wrapper']['add_mapping'] = [
      '#type' => 'submit',
      '#value' => $this->t('Agregar variable'),
      '#submit' => ['::addWhatsappMappingRow'],
      '#ajax' => [
        'callback' => '::ajaxRefreshMappings',
        'wrapper' => 'twilio-mappings-wrapper',
      ],
      '#limit_validation_errors' => [['webform_id'], ['recipient_field'], ['mappings']],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getValue('whatsapp_enabled')) {
      foreach ([
        'twilio_account_sid' => $this->t('Account SID (Twilio)'),
        'twilio_auth_token' => $this->t('Auth Token (Twilio)'),
        'twilio_whatsapp_from' => $this->t('WhatsApp remitente'),
        'twilio_content_template_sid' => $this->t('Template SID (Twilio Content Template)'),
      ] as $key => $label) {
        if (!$form_state->getValue($key)) {
          $form_state->setErrorByName($key, $this->t('El campo %field es obligatorio cuando WhatsApp esta activado.', ['%field' => $label]));
        }
      }

      if ($form_state->getValue('twilio_whatsapp_from') && strpos((string) $form_state->getValue('twilio_whatsapp_from'), 'whatsapp:') !== 0) {
        $form_state->setErrorByName('twilio_whatsapp_from', $this->t('El remitente debe iniciar con whatsapp:.'));
      }
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $mappings = [];
    foreach (($form_state->getValue('mappings') ?? []) as $mapping) {
      $field = trim((string) ($mapping['field'] ?? ''));
      $variable = trim((string) ($mapping['variable'] ?? ''));
      if ($field && $variable) {
        $mappings[] = [
          'field' => $field,
          'variable' => $variable,
        ];
      }
    }

    $this->config('twilio.settings')
      ->set('whatsapp_enabled', (bool) $form_state->getValue('whatsapp_enabled'))
      ->set('whatsapp_webform_id', $form_state->getValue('webform_id') ?? 'constancia')
      ->set('whatsapp_recipient_field', $form_state->getValue('recipient_field') ?? 'whatsapp_phone')
      ->set('twilio_account_sid', trim((string) $form_state->getValue('twilio_account_sid')))
      ->set('twilio_auth_token', trim((string) $form_state->getValue('twilio_auth_token')))
      ->set('twilio_whatsapp_from', trim((string) $form_state->getValue('twilio_whatsapp_from')))
      ->set('twilio_content_template_sid', trim((string) $form_state->getValue('twilio_content_template_sid')))
      ->set('whatsapp_mappings', $mappings)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Adds a new mapping row.
   */
  public function addWhatsappMappingRow(array &$form, FormStateInterface $form_state) {
    $rows = (int) $form_state->get('whatsapp_mapping_rows');
    $form_state->set('whatsapp_mapping_rows', $rows + 1);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Ajax callback for rebuilding the mapping section.
   */
  public function ajaxRefreshMappings(array &$form, FormStateInterface $form_state) {
    return $form['mappings_wrapper'];
  }

  /**
   * Gets all available webforms.
   */
  protected function getWebformOptions(): array {
    $options = [];
    foreach (Webform::loadMultiple() as $webform) {
      $options[$webform->id()] = $webform->label();
    }

    return $options;
  }

  /**
   * Gets mappable fields for a webform.
   */
  protected function getWebformFieldOptions(?string $webform_id): array {
    if (!$webform_id || !($webform = Webform::load($webform_id))) {
      return [];
    }

    $options = [];
    $ignored_types = [
      'actions',
      'hidden',
      'label',
      'processed_text',
      'submit',
      'webform_actions',
      'webform_card',
      'webform_flexbox',
    ];

    foreach ($webform->getElementsDecodedAndFlattened() as $key => $element) {
      $type = $element['#type'] ?? '';
      if (in_array($type, $ignored_types, TRUE)) {
        continue;
      }

      $label = $element['#title'] ?? $key;
      $options[$key] = "{$label} ({$key})";
    }

    return $options;
  }

}
