<?php

namespace Drupal\twilio;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\webform\WebformSubmissionInterface;
use GuzzleHttp\ClientInterface;

/**
 * Sends optional WhatsApp notifications using Twilio.
 */
class WhatsAppHelper {

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * Logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Builds the helper.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config factory.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   Logger factory.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Sends a Twilio WhatsApp notification for a webform submission.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The webform submission.
   */
  public function sendSubmissionNotification(WebformSubmissionInterface $submission): void {
    $config = $this->configFactory->get('twilio.settings');

    if (!$config->get('whatsapp_enabled')) {
      return;
    }

    $target_webform_id = $config->get('whatsapp_webform_id') ?: 'constancia';
    if ($submission->getWebform()->id() !== $target_webform_id || !$submission->isCompleted()) {
      return;
    }

    $account_sid = trim((string) $config->get('twilio_account_sid'));
    $auth_token = trim((string) $config->get('twilio_auth_token'));
    $from = trim((string) $config->get('twilio_whatsapp_from'));
    $content_sid = trim((string) $config->get('twilio_content_template_sid'));
    $recipient_field = $config->get('whatsapp_recipient_field') ?: 'whatsapp_phone';

    if (!$account_sid || !$auth_token || !$from || !$content_sid) {
      $this->loggerFactory->get('twilio')->warning('WhatsApp/Twilio is enabled but the connector configuration is incomplete.');
      return;
    }

    $data = $submission->getData();
    $recipient = $this->normalizeWhatsappAddress($data[$recipient_field] ?? '');
    if (!$recipient) {
      return;
    }

    $form_params = [
      'From' => $from,
      'To' => $recipient,
      'ContentSid' => $content_sid,
    ];

    $content_variables = $this->buildContentVariables((array) $config->get('whatsapp_mappings'), $data);
    if ($content_variables) {
      $form_params['ContentVariables'] = json_encode($content_variables, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    try {
      $this->httpClient->request('POST', "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/Messages.json", [
        'auth' => [$account_sid, $auth_token],
        'form_params' => $form_params,
        'timeout' => 20,
      ]);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('twilio')->error('Twilio WhatsApp send failed for submission @sid: @message', [
        '@sid' => $submission->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Builds Twilio template variables from the configured mappings.
   *
   * @param array $mappings
   *   Mapping rows.
   * @param array $data
   *   Submission data.
   *
   * @return array
   *   Twilio content variables.
   */
  protected function buildContentVariables(array $mappings, array $data): array {
    $variables = [];

    foreach ($mappings as $mapping) {
      $field = $mapping['field'] ?? '';
      $variable = $this->normalizeTemplateVariable($mapping['variable'] ?? '');

      if (!$field || $variable === '') {
        continue;
      }

      $value = $this->normalizeVariableValue($data[$field] ?? NULL);
      if ($value === '') {
        continue;
      }

      $variables[$variable] = $value;
    }

    return $variables;
  }

  /**
   * Normalizes a single template variable key.
   *
   * @param string $variable
   *   Raw variable value from config.
   *
   * @return string
   *   Normalized variable key.
   */
  protected function normalizeTemplateVariable(string $variable): string {
    $variable = trim($variable);
    if ($variable === '') {
      return '';
    }

    if (preg_match('/^\{\{\s*(.+?)\s*\}\}$/', $variable, $matches)) {
      $variable = $matches[1];
    }

    return trim($variable);
  }

  /**
   * Converts a submission value into a scalar string for Twilio.
   *
   * @param mixed $value
   *   Submission value.
   *
   * @return string
   *   String value.
   */
  protected function normalizeVariableValue(mixed $value): string {
    if ($value === NULL) {
      return '';
    }

    if (is_scalar($value)) {
      return trim((string) $value);
    }

    if (is_array($value)) {
      $items = [];
      array_walk_recursive($value, function ($item) use (&$items) {
        if (is_scalar($item) && trim((string) $item) !== '') {
          $items[] = trim((string) $item);
        }
      });

      return implode(', ', $items);
    }

    return trim((string) $value);
  }

  /**
   * Normalizes a phone number into Twilio WhatsApp format.
   *
   * @param mixed $value
   *   Raw field value.
   *
   * @return string|null
   *   Formatted address or NULL when empty/invalid.
   */
  protected function normalizeWhatsappAddress(mixed $value): ?string {
    $value = trim($this->normalizeVariableValue($value));
    if ($value === '') {
      return NULL;
    }

    if (str_starts_with($value, 'whatsapp:')) {
      $value = substr($value, 9);
    }

    $value = preg_replace('/(?!^\+)[^0-9]/', '', $value);
    if ($value === '') {
      return NULL;
    }

    if (!str_starts_with($value, '+')) {
      $value = '+' . ltrim($value, '+');
    }

    return 'whatsapp:' . $value;
  }

}
