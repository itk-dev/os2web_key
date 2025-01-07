<?php

namespace Drupal\os2web_key\Services;

use Drupal\Core\File\FileSystem;
use Drupal\os2web_key\Exception\FileException;

/**
 * Helper for fetching certificates from vault.
 */
class VaultCertificateHelper {

  /**
   * Path to certificate.
   */
  private string $certificatePath;

  /**
   * Setup temporary certificate file.
   *
   * @throws \Drupal\os2web_key\Exception\FileException
   *   File exception.
   */
  public function __construct(
    private readonly FileSystem $fileSystem,
    string $certificate,
  ) {
    $this->certificatePath = $this->createTempCertificateFile($certificate);
  }

  /**
   * Ensure temporary certificate file is removed.
   */
  public function __destruct() {
    // Remove the certificate from disk.
    if (file_exists($this->certificatePath)) {
      unlink($this->certificatePath);
    }
  }

  /**
   * Gets path to temporary certificate file.
   */
  public function getCertificatePath(): string {
    return $this->certificatePath;
  }

  /**
   * Creates a temporary file with certificate.
   *
   * @return string
   *   The temporary file path.
   *
   * @throws \Drupal\os2web_key\Exception\FileException
   *   File exception.
   */
  private function createTempCertificateFile(string $certificate): string {

    $localCertFilename = tempnam($this->fileSystem->getTempDirectory(), 'vault_certificate');

    if (!$localCertFilename) {
      throw new FileException('Could not generate temporary certificate file.');
    }

    file_put_contents($localCertFilename, $certificate);

    return $localCertFilename;
  }

}
