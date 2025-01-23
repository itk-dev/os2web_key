<?php

namespace Drupal\os2web_key;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\key\KeyInterface;
use Drupal\os2web_key\Exception\RuntimeException;
use Drupal\os2web_key\Plugin\KeyType\CertificateKeyType;
use Drupal\os2web_key\Plugin\KeyType\OidcKeyType;
use Psr\Log\LoggerAwareTrait;

/**
 * Key helper.
 */
class KeyHelper {
  use DependencySerializationTrait;
  use LoggerAwareTrait;

  public function __construct(
    LoggerChannelInterface $logger,
  ) {
    $this->setLogger($logger);
  }

  /**
   * Get certificates from a key.
   *
   * @param \Drupal\key\KeyInterface $key
   *   The key.
   *
   * @return array{cert: string, pkey: string}
   *   The certificates.
   */
  public function getCertificates(KeyInterface $key): array {
    $type = $key->getKeyType();
    if (!($type instanceof CertificateKeyType)) {
      throw $this->createSslRuntimeException(sprintf('Invalid key type: %s', $type::class), $key);
    }

    // Check whether conversion is needed.
    if ($type->getInputFormat() === $type->getOutputFormat()) {
      return $this->parseCertificates($key->getKeyValue(), $type->getInputFormat(), $type->getPassphrase(), $key);
    }

    $parsedCertificates = $this->parseCertificates($key->getKeyValue(), $type->getInputFormat(), $type->getPassphrase(), $key);
    $convertedCertificates = $this->convertCertificates($parsedCertificates, $type->getOutputFormat(), $key);

    return $this->parseCertificates($convertedCertificates, $type->getOutputFormat(), $type->getPassphrase(), $key);
  }

  /**
   * Get OIDC values from a key.
   *
   * @param \Drupal\key\KeyInterface $key
   *   The key.
   *
   * @return array{discovery_url: string, client_id: string, client_secret: string}
   *   The OIDC values.
   */
  public function getOidcValues(KeyInterface $key): array {
    $type = $key->getKeyType();
    if (!($type instanceof OidcKeyType)) {
      throw $this->createSslRuntimeException(sprintf('Invalid key type: %s', $type::class), $key);
    }
    $contents = $key->getKeyValue();

    try {
      $values = json_decode($contents, TRUE, 512, JSON_THROW_ON_ERROR);
      foreach ([
        OidcKeyType::DISCOVERY_URL,
        OidcKeyType::CLIENT_ID,
        OidcKeyType::CLIENT_SECRET,
      ] as $name) {
        if (!isset($values[$name])) {
          throw $this->createRuntimeException(sprintf("Missing OIDC value: %s", $name), $key);
        }
      }
      return $values;
    }
    catch (\JsonException $e) {
      throw $this->createRuntimeException(sprintf("Cannot get OIDC values: %s", $e->getMessage()), $key);
    }
  }

  /**
   * Parse certificates.
   *
   * @return array{cert: string, pkey: string}
   *   The certificates, note that the certificate has no passphrase.
   */
  public function parseCertificates(
    string $contents,
    string $format,
    ?string $passphrase,
    ?KeyInterface $key,
  ): array {
    $certificates = [
      CertificateKeyType::CERT => NULL,
      CertificateKeyType::PKEY => NULL,
    ];

    switch ($format) {
      case CertificateKeyType::FORMAT_PFX:
        if (!openssl_pkcs12_read($contents, $certificates, $passphrase)) {
          throw $this->createSslRuntimeException('Error reading certificate', $key);
        }
        break;

      case CertificateKeyType::FORMAT_PEM:
        $certificate = @openssl_x509_read($contents);
        if (FALSE === $certificate) {
          throw $this->createSslRuntimeException('Error reading certificate', $key);
        }
        if (!@openssl_x509_export($certificate, $certificates[CertificateKeyType::CERT])) {
          throw $this->createSslRuntimeException('Error exporting x509 certificate', $key);
        }
        $pkey = @openssl_pkey_get_private($contents, $passphrase);
        if (FALSE === $pkey) {
          throw $this->createSslRuntimeException('Error reading private key', $key);
        }
        if (!@openssl_pkey_export($pkey, $certificates[CertificateKeyType::PKEY])) {
          throw $this->createSslRuntimeException('Error exporting private key', $key);
        }
        break;
    }

    if (!isset($certificates[CertificateKeyType::CERT], $certificates[CertificateKeyType::PKEY])) {
      throw $this->createRuntimeException("Cannot read certificate parts CertificateKeyType::CERT and CertificateKeyType::PKEY", $key);
    }

    return $certificates;
  }

  /**
   * Converts certificates to format.
   *
   * @param array $certificates
   *   Output from parseCertificates.
   * @param string $format
   *   Format to convert into.
   * @param ?KeyInterface $key
   *   The key, for debugging purposes.
   *
   * @return string
   *   The converted certificate.
   *
   * @see self::parseCertificates()
   */
  public function convertCertificates(array $certificates, string $format, ?KeyInterface $key): string {
    $cert = $certificates[CertificateKeyType::CERT] ?? NULL;
    if (!isset($cert)) {
      throw $this->createRuntimeException('Certificate part "cert" not found', $key);
    }

    $pkey = $certificates[CertificateKeyType::PKEY] ?? NULL;
    if (!isset($pkey)) {
      throw $this->createRuntimeException('Certificate part "pkey" not found', $key);
    }

    $output = '';
    switch ($format) {
      case CertificateKeyType::FORMAT_PEM:
        $parts = ['', ''];
        if (!@openssl_x509_export($cert, $parts[0])) {
          throw $this->createSslRuntimeException('Cannot export certificate', $key);
        }
        if (!@openssl_pkey_export($pkey, $parts[1])) {
          throw $this->createSslRuntimeException('Cannot export private key', $key);
        }
        $output = implode('', $parts);
        break;

      case CertificateKeyType::FORMAT_PFX:
        if (!@openssl_pkcs12_export($cert, $output, $pkey, '')) {
          throw $this->createSslRuntimeException('Cannot export certificate', $key);
        }
        break;

      default:
        throw $this->createSslRuntimeException(sprintf('Invalid format: %s', $format), $key);
    }

    return $output;
  }

  /**
   * Create a runtime exception.
   */
  public function createRuntimeException(string $message, ?KeyInterface $key, ?string $sslError = NULL): RuntimeException {
    if (NULL !== $sslError) {
      $message .= ' (' . $sslError . ')';
    }
    // @fixme Error: Typed property …::$logger must not be accessed before initialization.
    if (isset($this->logger)) {
      $this->logger->error('@key: @message', [
        '@key' => $key?->id(),
        '@message' => $message,
      ]);
    }

    return new RuntimeException($message);
  }

  /**
   * Create an SSL runtime exception.
   */
  public function createSslRuntimeException(string $message, ?KeyInterface $key): RuntimeException {
    // @see https://www.php.net/manual/en/function.openssl-error-string.php.
    $sslError = NULL;

    while ($errorMessage = openssl_error_string()) {
      $sslError = $errorMessage;
    }

    return $this->createRuntimeException($message, $key, $sslError);
  }

}
