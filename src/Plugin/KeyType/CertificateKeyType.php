<?php

namespace Drupal\os2web_key\Plugin\KeyType;

use Drupal\Component\Serialization\Json;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\key\Plugin\KeyPluginFormInterface;
use Drupal\key\Plugin\KeyTypeBase;
use Drupal\os2web_key\Exception\RuntimeException;
use Drupal\os2web_key\KeyHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a key that combines a certificate and an optional password.
 *
 * @KeyType(
 *   id = "os2web_key_certificate",
 *   label = @Translation("Certificate"),
 *   description = @Translation("A key type to store a certificate."),
 *   group = "authentication",
 *   key_value = {
 *     "plugin" = "textarea_field"
 *   },
 * )
 */
class CertificateKeyType extends KeyTypeBase implements KeyPluginFormInterface {
  use DependencySerializationTrait;

  public const FORMAT_PEM = 'pem';
  public const FORMAT_PFX = 'pfx';
  public const CERT = 'cert';
  public const PKEY = 'pkey';

  private const PASSPHRASE = 'passphrase';
  private const INPUT_FORMAT = 'input_format';
  private const OUTPUT_FORMAT = 'output_format';

  /**
   * Constructor.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly KeyHelper $certificateHelper,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('os2web_key.key_helper')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      self::PASSPHRASE => '',
      self::INPUT_FORMAT => self::FORMAT_PFX,
      self::OUTPUT_FORMAT => self::FORMAT_PEM,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form[self::PASSPHRASE] = [
      '#type' => 'textfield',
      '#title' => $this->t('Passphrase'),
      '#default_value' => $this->configuration[self::PASSPHRASE] ?? NULL,
    ];

    $formatOptions = [
      self::FORMAT_PEM => $this->t('PEM'),
      self::FORMAT_PFX => $this->t('pfx (p12)'),
    ];
    $form[self::INPUT_FORMAT] = [
      '#title' => $this->t('Input format'),
      '#type' => 'select',
      '#options' => $formatOptions,
      '#required' => TRUE,
      '#default_value' => $this->configuration[self::INPUT_FORMAT] ?? NULL,
    ];

    $form[self::OUTPUT_FORMAT] = [
      '#title' => $this->t('Output format'),
      '#type' => 'select',
      '#options' => $formatOptions,
      '#required' => TRUE,
      '#default_value' => $this->configuration[self::OUTPUT_FORMAT] ?? NULL,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    // This function is deliberately left empty.
    // Actual validation is performed by self::validateKeyValue(), which see.
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->setConfiguration($form_state->getValues());
  }

  /**
   * {@inheritdoc}
   */
  public static function generateKeyValue(array $configuration): string {
    return Json::encode($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function validateKeyValue(array $form, FormStateInterface $form_state, $key_value): void {
    $passphrase = $form_state->getValue(self::PASSPHRASE);
    $inputFormat = $form_state->getValue(self::INPUT_FORMAT);
    $outputFormat = $form_state->getValue(self::OUTPUT_FORMAT);

    try {
      $certificates = $this->certificateHelper->parseCertificates($key_value, $inputFormat, $passphrase, NULL);
    }
    catch (RuntimeException $exception) {
      $form_state->setError($form, $this->t('Error parsing certificates: @message', ['@message' => $exception->getMessage()]));
      return;
    }

    try {
      $this->certificateHelper->createPasswordlessCertificate($certificates, $outputFormat, NULL);
    }
    catch (RuntimeException $exception) {
      $form_state->setError($form, $this->t('Error creating passwordless certificate: @message', ['@message' => $exception->getMessage()]));
    }
  }

  /**
   * Get passphrase.
   */
  public function getPassphrase(): string {
    return $this->configuration[self::PASSPHRASE];
  }

  /**
   * Get input format.
   */
  public function getInputFormat(): string {
    return $this->configuration[self::INPUT_FORMAT];
  }

  /**
   * Get output format.
   */
  public function getOutputFormat(): string {
    return $this->configuration[self::OUTPUT_FORMAT];
  }

}
