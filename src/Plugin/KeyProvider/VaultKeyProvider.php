<?php

namespace Drupal\os2web_key\Plugin\KeyProvider;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\key\KeyInterface;
use Drupal\key\Plugin\KeyPluginFormInterface;
use Drupal\key\Plugin\KeyProviderBase;
use Drupal\os2web_key\KeyHelper;
use Drupal\os2web_key\Plugin\KeyType\CertificateKeyType;
use GuzzleHttp\Psr7\HttpFactory;
use ItkDev\Vault\Exception\UnknownErrorException;
use ItkDev\Vault\Exception\VaultException;
use ItkDev\Vault\Vault as VaultClient;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds a key provider that allows a key to be stored in HashiCorp Vault.
 *
 * @KeyProvider(
 *   id = "vault_key_provder",
 *   label = @Translation("HashiCorp Vault key provider"),
 *   description = @Translation("This provider stores the key in HashiCorp Vault."),
 *   storage_method = "vault_key_provider",
 *   key_value = {
 *     "accepted" = FALSE,
 *     "required" = FALSE
 *   }
 * )
 */
final class VaultKeyProvider extends KeyProviderBase implements KeyPluginFormInterface {
  use LoggerAwareTrait;

  /**
   * Constructs a VaultKeyValeKeyProvider object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   A logger service.
   * @param \Psr\Http\Client\ClientInterface $httpClient
   *   A http client.
   * @param \Psr\SimpleCache\CacheInterface $cache
   *   A PSR-16 Cache service.
   * @param \Drupal\os2web_key\KeyHelper $keyHelper
   *   The key helper.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    LoggerChannelInterface $logger,
    private readonly ClientInterface $httpClient,
    private readonly CacheInterface $cache,
    private readonly KeyHelper $keyHelper,
  ) {
    $this->setLogger($logger);
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.channel.default'),
      $container->get('http_client'),
      $container->get('os2web_key.psr16_cache'),
      $container->get('os2web_key.key_helper'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'vault_path' => 'secret',
      'vault_secret' => '',
      'vault_key' => '',
      'vault_version' => NULL,
      'vault_cache_duration' => 60 * 60,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getKeyValue(KeyInterface $key) {
    $roleId = Settings::get('itkdev_vault_role_id');
    $secretId = Settings::get('itkdev_vault_secret_id');

    $vault = $this->getVault();

    try {
      $token = $vault->login(
        $roleId,
        $secretId,
      );
    }
    catch (\DateMalformedStringException | \DateMalformedIntervalStringException | UnknownErrorException | VaultException | InvalidArgumentException $e) {
      // Log the exception and re-throw it.
      $this->logger->error('Error fetching login token for key (@key_id): @message', [
        '@key_id' => $key->id(),
        '@message' => $e->getMessage(),
        'throwable' => $e,
      ]);

      throw $e;
    }

    $config = $this->configuration;

    $vaultPath = $config['vault_path'];
    $vaultSecret = $config['vault_secret'];
    $vaultKey = $config['vault_key'];
    $vaultVersion = $config['vault_version'];
    $vaultCacheDuration = $config['vault_cache_duration'] ?? 0;
    $vaultUseCache = $vaultCacheDuration !== 0;

    try {
      $secret = $vault->getSecret(
        token: $token,
        path: $vaultPath,
        secret: $vaultSecret,
        key: $vaultKey,
        version: $vaultVersion,
        useCache: $vaultUseCache,
        expire: $vaultCacheDuration
      );
    }
    catch (\DateMalformedStringException | UnknownErrorException | VaultException | InvalidArgumentException $e) {
      // Log the exception and re-throw it.
      $this->logger->error('Error getting certificate for key (@key_id): @message', [
        '@key_id' => $key->id(),
        '@message' => $e->getMessage(),
        'throwable' => $e,
      ]);

      throw $e;
    }

    $type = $key->getKeyType();
    if (!($type instanceof CertificateKeyType)) {
      throw $this->keyHelper->createSslRuntimeException(sprintf('Invalid key type: %s', $type::class), $key);
    }

    return $secret->value;
  }

  /**
   * {@inheritDoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $providerConfig = $this->getConfiguration();

    $form['vault_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Vault path'),
      '#description' => $this->t('The Vault path'),
      '#required' => TRUE,
      '#default_value' => $providerConfig['vault_path'] ?? $this->defaultConfiguration()['vault_path'],
    ];

    $form['vault_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Vault secret'),
      '#description' => $this->t('The vault secret that should be used'),
      '#required' => TRUE,
      '#default_value' => $providerConfig['vault_secret'] ?? $this->defaultConfiguration()['vault_secret'],
    ];

    $form['vault_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Vault key'),
      '#description' => $this->t('The key that should be fetched'),
      '#required' => TRUE,
      '#default_value' => $providerConfig['vault_key'] ?? $this->defaultConfiguration()['vault_key'],
    ];

    $form['vault_version'] = [
      '#type' => 'number',
      '#title' => $this->t('Vault version'),
      '#description' => $this->t('The version of key that should be fetched'),
      '#default_value' => $providerConfig['vault_version'] ?? $this->defaultConfiguration()['vault_version'],
      '#required' => FALSE,
    ];

    $form['vault_cache_duration'] = [
      '#type' => 'number',
      '#title' => $this->t('Vault cache expiration duration'),
      '#description' => $this->t('The amount of seconds version of key that should be fetched. Set to 0 to disable caching.'),
      '#default_value' => $providerConfig['vault_cache_duration'] ?? $this->defaultConfiguration()['vault_cache_duration'],
      '#required' => FALSE,
      '#min' => 0,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    if (empty($form_state->getValue('vault_version'))) {
      $form_state->setValue('vault_version', NULL);
    }

    $vaultCacheDuration = $form_state->getValue('vault_cache_duration');
    if (empty($vaultCacheDuration) || $vaultCacheDuration < 0) {
      $form_state->setValue('vault_cache_duration', 0);
    }

  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->setConfiguration($form_state->getValues());
  }

  /**
   * Helper function to create vault client.
   *
   * @return \ItkDev\Vault\Vault
   *   The client
   */
  private function getVault(): VaultClient {
    static $vaultClient = NULL;

    $httpFactory = new HttpFactory();

    if (is_null($vaultClient)) {
      $vaultClient = new VaultClient(
        httpClient: $this->httpClient,
        requestFactory: $httpFactory,
        streamFactory: $httpFactory,
        cache: $this->cache,
        vaultUrl: Settings::get('itkdev_vault_url'),
      );
    }

    return $vaultClient;
  }

}
