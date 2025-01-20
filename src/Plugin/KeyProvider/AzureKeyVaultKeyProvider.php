<?php

namespace Drupal\os2web_key\Plugin\KeyProvider;

use Drupal\Core\Form\FormStateInterface;
use Drupal\key\KeyInterface;
use Drupal\key\Plugin\KeyPluginFormInterface;
use Drupal\key\Plugin\KeyProviderBase;
use GuzzleHttp\Client;
use Http\Adapter\Guzzle7\Client as GuzzleAdapter;
use Http\Factory\Guzzle\RequestFactory;
use ItkDev\AzureKeyVault\Authorisation\VaultToken;
use ItkDev\AzureKeyVault\KeyVault\VaultSecret;
use ItkDev\Serviceplatformen\Certificate\AzureKeyVaultCertificateLocator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds a key provider for Azure Key Vault.
 *
 * @KeyProvider(
 *   id = "os2web_azure_key_vault",
 *   label = @Translation("Azure Key Vault"),
 *   description = @Translation("Azure Key Vault"),
 *   storage_method = "remote",
 *   key_value = {
 *     "accepted" = FALSE,
 *     "required" = FALSE
 *   }
 * )
 */
final class AzureKeyVaultKeyProvider extends KeyProviderBase implements KeyPluginFormInterface {

  private const TENANT_ID = 'tenant_id';
  private const APPLICATION_ID = 'application_id';
  private const CLIENT_SECRET = 'client_secret';
  private const NAME = 'name';
  private const SECRET = 'secret';
  private const VERSION = 'version';

  /**
   * Constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The unique ID of this plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger for this module.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    /** @var \Psr\Log\LoggerInterface $logger */
    $logger = $container->get('logger.channel.os2web_key');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $logger
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      self::TENANT_ID => '',
      self::APPLICATION_ID => '',
      self::CLIENT_SECRET => '',
      self::NAME => '',
      self::SECRET => '',
      self::VERSION => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $configuration = $this->getConfiguration();

    $settings = [
      self::TENANT_ID => ['title' => $this->t('Tenant id')],
      self::APPLICATION_ID => ['title' => $this->t('Application id')],
      self::CLIENT_SECRET => ['title' => $this->t('Client secret')],
      self::NAME => ['title' => $this->t('Name')],
      self::SECRET => ['title' => $this->t('Secret')],
      self::VERSION => ['title' => $this->t('Version')],
    ];

    foreach ($settings as $key => $info) {
      $form[$key] = [
        '#type' => 'textfield',
        '#title' => $info['title'],
        '#default_value' => $configuration[$key] ?? NULL,
        '#required' => TRUE,
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->getCertificate([
        self::TENANT_ID => $form_state->getValue(self::TENANT_ID),
        self::APPLICATION_ID => $form_state->getValue(self::APPLICATION_ID),
        self::CLIENT_SECRET => $form_state->getValue(self::CLIENT_SECRET),
        self::NAME => $form_state->getValue(self::NAME),
        self::SECRET => $form_state->getValue(self::SECRET),
        self::VERSION => $form_state->getValue(self::VERSION),
      ]);
    }
    catch (\Throwable $throwable) {
      $form_state->setError($form, $this->t('Error getting certificate: %message', ['%message' => $throwable->getMessage()]));
    }
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
  public function getKeyValue(KeyInterface $key): ?string {
    try {
      return $this->getCertificate();
    }
    catch (\Throwable $throwable) {
    }

    return NULL;
  }

  /**
   * Get certificate.
   *
   * @return string
   *   The certificate.
   *
   * @throws \ItkDev\AzureKeyVault\Exception\TokenException
   * @throws \ItkDev\Serviceplatformen\Certificate\Exception\AzureKeyVaultCertificateLocatorException
   */
  private function getCertificate(?array $configuration = NULL): string {
    try {
      $httpClient = new GuzzleAdapter(new Client());
      $requestFactory = new RequestFactory();

      $vaultToken = new VaultToken($httpClient, $requestFactory);

      $options = $configuration ?? $this->getConfiguration();

      $token = $vaultToken->getToken(
        $options[self::TENANT_ID],
        $options[self::APPLICATION_ID],
        $options[self::CLIENT_SECRET],
      );

      $vault = new VaultSecret(
        $httpClient,
        $requestFactory,
        $options[self::NAME],
        $token->getAccessToken()
      );

      $locator = new AzureKeyVaultCertificateLocator(
        $vault,
        $options[self::SECRET],
        $options[self::VERSION],
      );

      return $locator->getCertificate();
    }
    catch (\Exception $exception) {
      // Log the exception and re-throw it.
      $this->logger->error('Error getting certificate: @message', [
        '@message' => $exception->getMessage(),
        'throwable' => $exception,
      ]);
      throw $exception;
    }
  }

}
