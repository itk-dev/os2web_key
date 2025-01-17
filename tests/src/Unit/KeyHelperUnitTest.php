<?php

namespace Drupal\Tests\os2web_key\Unit;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\os2web_key\Exception\RuntimeException;
use Drupal\os2web_key\Plugin\KeyType\CertificateKeyType;
use Drupal\Tests\UnitTestCase;
use Drupal\os2web_key\KeyHelper;

/**
 * KeyHelper unit test class.
 */
class KeyHelperUnitTest extends UnitTestCase {

  /**
   * The key helper.
   *
   * @var \Drupal\os2web_key\KeyHelper|null
   */
  private ?KeyHelper $keyHelper;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|null
   */
  private ?LoggerChannelInterface $mockLogger;

  /**
   * {@inheritDoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->mockLogger = $this->createMock(LoggerChannelInterface::class);
    $this->keyHelper = new KeyHelper($this->mockLogger);

  }

  /**
   * Tests creation of KeyHelper.
   */
  public function testKeyHelperCreation() {
    $this->assertInstanceOf(KeyHelper::class, $this->keyHelper);
  }

  /**
   * Tests creation of RuntimeException no SSL message.
   */
  public function testCreateRuntimeExceptionNoSslMessage() {
    $this->mockLogger->expects($this->once())->method('error');
    $message = 'test';
    $result = $this->keyHelper->createRuntimeException($message, NULL);
    $this->assertEquals($message, $result->getMessage());
  }

  /**
   * Tests creation of RuntimeException no SSL message.
   */
  public function testCreateRuntimeExceptionWithSslMessage() {
    $this->mockLogger->expects($this->once())->method('error');
    $message = 'test';
    $expected = $message . ' (some SSL error)';
    $result = $this->keyHelper->createRuntimeException($message, NULL, 'some SSL error');
    $this->assertEquals($expected, $result->getMessage());
  }

  /**
   * Tests creation of RuntimeException no SSL message.
   */
  public function testCreateRuntimeExceptionWithKeyAndSslMessage() {
    $mockMessage = 'test';
    $expectedMessage = $mockMessage . ' (some SSL error)';
    $mockKeyId = 'some_key_id';
    $this->mockLogger->expects($this->once())->method('error')->with('@key: @message', [
      '@key' => $mockKeyId,
      '@message' => $expectedMessage,
    ]);
    $mockKey = $this->createMock('Drupal\key\KeyInterface');
    $mockKey->expects($this->once())->method('id')->willReturn($mockKeyId);
    $result = $this->keyHelper->createRuntimeException($mockMessage, $mockKey, 'some SSL error');
    $this->assertEquals($expectedMessage, $result->getMessage());
  }

  /**
   * Data provider for parse certificates test.
   */
  public static function parseCertificatesDataProvider(): \Generator {
    yield [
      'test_without_passphrase.p12',
      CertificateKeyType::FORMAT_PFX,
      '',
      [
        CertificateKeyType::CERT => 'p12_without_passphrase_cert.txt',
        CertificateKeyType::PKEY => 'p12_without_passphrase_pkey.txt',
      ],
    ];

    yield [
      'test_with_passphrase.p12',
      CertificateKeyType::FORMAT_PFX,
      'test',
      [
        CertificateKeyType::CERT => 'p12_with_passphrase_cert.txt',
        CertificateKeyType::PKEY => 'p12_with_passphrase_pkey.txt',
      ],
    ];

    yield [
      'test_without_passphrase.pem',
      CertificateKeyType::FORMAT_PEM,
      '',
      [
        CertificateKeyType::CERT => 'pem_without_passphrase_cert.txt',
        CertificateKeyType::PKEY => 'pem_without_passphrase_pkey.txt',
      ],
    ];

    yield [
      'test_with_passphrase.pem',
      CertificateKeyType::FORMAT_PEM,
      'test',
      [
        CertificateKeyType::CERT => 'pem_with_passphrase_cert.txt',
        CertificateKeyType::PKEY => 'pem_with_passphrase_pkey.txt',
      ],
    ];

    yield [
      'test_with_passphrase.pem',
      CertificateKeyType::FORMAT_PEM,
      'wrong_passphrase',
      // @command openssl pkey -in test_with_passphrase.pem -passin pass:wrong_passphrase
      new RuntimeException('Error reading private key (error:11800074:PKCS12 routines::pkcs12 cipherfinal error)'),
    ];

    yield [
      'test_with_passphrase.pem',
      CertificateKeyType::FORMAT_PFX,
      'some_non_important_passphrase',
      // @command openssl pkcs12 -in test_with_passphrase.pem -passin pass:some_non_important_passphrase
      new RuntimeException('Error reading certificate (error:0688010A:asn1 encoding routines::nested asn1 error)'),
    ];
  }

  /**
   * Tests parse certificates.
   *
   * @dataProvider parseCertificatesDataProvider
   */
  public function testParseCertificates(string $certificate, string $format, string $passphrase, array|RuntimeException $expected) {

    if ($expected instanceof RuntimeException) {
      $this->expectException($expected::class);
      $this->expectExceptionMessage($expected->getMessage());
    }

    $certificates = $this->keyHelper->parseCertificates(file_get_contents(__DIR__ . '/certificates/' . $certificate), $format, $passphrase, NULL);

    // Assert certificate.
    $this->assertEquals(file_get_contents(__DIR__ . '/certificates/' . $expected[CertificateKeyType::CERT]), $certificates[CertificateKeyType::CERT]);
    // Assert private key.
    $this->assertEquals(file_get_contents(__DIR__ . '/certificates/' . $expected[CertificateKeyType::PKEY]), $certificates[CertificateKeyType::PKEY]);
  }

  /**
   * Data provider for parse certificates test.
   */
  public static function parsedAndConvertedCertificatesAreEqualProvider(): \Generator {
    yield [
      'test_with_passphrase.pem',
      CertificateKeyType::FORMAT_PEM,
      CertificateKeyType::FORMAT_PEM,
      'test',
    ];

    yield [
      'test_with_passphrase.pem',
      CertificateKeyType::FORMAT_PEM,
      CertificateKeyType::FORMAT_PFX,
      'test',
    ];

    yield [
      'test_without_passphrase.pem',
      CertificateKeyType::FORMAT_PEM,
      CertificateKeyType::FORMAT_PEM,
      '',
    ];

    yield [
      'test_without_passphrase.pem',
      CertificateKeyType::FORMAT_PEM,
      CertificateKeyType::FORMAT_PFX,
      '',
    ];

    yield [
      'test_with_passphrase.p12',
      CertificateKeyType::FORMAT_PFX,
      CertificateKeyType::FORMAT_PFX,
      'test',
    ];

    yield [
      'test_with_passphrase.p12',
      CertificateKeyType::FORMAT_PFX,
      CertificateKeyType::FORMAT_PEM,
      'test',
    ];

    yield [
      'test_without_passphrase.p12',
      CertificateKeyType::FORMAT_PFX,
      CertificateKeyType::FORMAT_PFX,
      '',
    ];

    yield [
      'test_without_passphrase.p12',
      CertificateKeyType::FORMAT_PFX,
      CertificateKeyType::FORMAT_PEM,
      '',
    ];
  }

  /**
   * Tests conversion of certificates.
   *
   * @dataProvider parsedAndConvertedCertificatesAreEqualProvider
   */
  public function testParsedAndConvertedCertificatesAreEqual(string $certificate, string $inputFormat, string $outputFormat, string $passphrase) {
    $certificates = $this->keyHelper->parseCertificates(file_get_contents(__DIR__ . '/certificates/' . $certificate), $inputFormat, $passphrase, NULL);
    $converted = $this->keyHelper->convertCertificates($certificates, $outputFormat, NULL);

    // Test that converted (now passwordless) certificate yields same result as
    // original parsed certificate.
    $convertedParsedCertificates = $this->keyHelper->parseCertificates($converted, $outputFormat, '', NULL);

    $this->assertEquals($certificates, $convertedParsedCertificates);
  }

}
