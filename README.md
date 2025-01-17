# OS2Web key

Key types and providers for OS2Web built on the [Key module](https://www.drupal.org/project/key).

The OS2Web key module provides two _key types_, [Certificate](#certificate) and [OpenID Connect
(OIDC)](#openid-connect-oidc). It also comes with two _key providers_,
[Azure Key Vault](#azure-key-vault) and [HashiCorp Vault](#hashicorp-vault).

See [the Key Developer Guide](https://www.drupal.org/docs/contributed-modules/key/developer-guide) for details in how to
use keys in Drupal.

## Installation

``` shell
composer require os2web/os2web_key
drush pm:install os2web_key
```

Keys are managed on `/admin/config/system/keys`.

## Key types

### Certificate

This key type handles [PKCS 12](https://en.wikipedia.org/wiki/PKCS_12) or [Privacy-Enhanced Mail
(PEM)](https://en.wikipedia.org/wiki/Privacy-Enhanced_Mail) certificate with an optional password (passphrase).

Managing the key:

!["Certificate" key type form](docs/assets/key-type-certificate.png)

Use in a form:

``` php
$form['key'] => [
 '#type' => 'key_select',
 '#key_filters' => [
   'type' => 'os2web_key_certificate',
 ],
];
```

The [`KeyHelper`](https://github.com/OS2web/os2web_key/blob/main/src/KeyHelper.php) can be used to get
the actual certificates (parts):

``` php
<?php

use Drupal\os2web_key\KeyHelper;
use Drupal\key\KeyRepositoryInterface;

// Use dependency injection for this.
/** @var KeyRepositoryInterface $repository */
$repository = \Drupal::service('key.repository');
/** @var KeyHelper $helper */
$helper = \Drupal::service(KeyHelper::class);

// Use `drush key:list` to list your keys.
$key = $repository->getKey('my_key');
[
  // Passwordless certificate.
  CertificateKeyType::CERT => $certificate,
  CertificateKeyType::PKEY => $privateKey,
] = $helper->getCertificates($key);

```

**Note**: The parsed certificate has no password.

### OpenID Connect (OIDC)

Managing the key:

!["OpenID Connect (OIDC)" key type form](docs/assets/key-type-oidc.png)

Example use in a form:

``` php
$form['key'] => [
 '#type' => 'key_select',
 '#key_filters' => [
   'type' => 'os2web_key_oidc,
 ],
];
```

Get the OIDC config:

``` php
<?php

use Drupal\key\KeyRepositoryInterface;
use Drupal\os2web_key\Plugin\KeyType\OidcKeyType;

// Use dependency injection for this.
/** @var KeyRepositoryInterface $repository */
$repository = \Drupal::service('key.repository');

$key = $repository->getKey('openid_connect_ad');
[
  OidcKeyType::DISCOVERY_URL => $discoveryUrl,
  OidcKeyType::CLIENT_ID => $clientId,
  OidcKeyType::CLIENT_SECRET => $clientSecret,
] = $helper->getOidcValues($key);
```

## Providers

The module comes with two key providers.

### Azure Key Vault

Used for fetching certificate from Azure Key vault.

### HashiCorp Vault

Used to fetch any sort of secret string from HashiCorp vault. Note that
this can only provide string values, i.e. no binary files.

To use this provider you must configure the following in `settings.local.php`:

``` php
$settings['os2web_vault_role_id'] = '{ROLE_ID}';
$settings['os2web_vault_secret_id'] = '{SECRET_ID}';
$settings['os2web_vault_url'] = '{VAULT_URL}';
```

## Coding standards

Our coding are checked by GitHub Actions (cf. [.github/workflows/pr.yml](.github/workflows/pr.yml)). Use the commands
below to run the checks locally.

### PHP

```shell
docker run --rm --volume ${PWD}:/app --workdir /app itkdev/php8.3-fpm composer install
# Fix (some) coding standards issues
docker run --rm --volume ${PWD}:/app --workdir /app itkdev/php8.3-fpm composer coding-standards-apply
# Check that code adheres to the coding standards
docker run --rm --volume ${PWD}:/app --workdir /app itkdev/php8.3-fpm composer coding-standards-check
```

### Markdown

```shell
docker run --rm --volume $PWD:/md peterdavehello/markdownlint markdownlint --ignore vendor --ignore LICENSE.md '**/*.md' --fix
docker run --rm --volume $PWD:/md peterdavehello/markdownlint markdownlint --ignore vendor --ignore LICENSE.md '**/*.md'
```

## Code analysis

We use [PHPStan](https://phpstan.org/) for static code analysis.

Running static code analysis on a standalone Drupal module is a bit tricky, so we use a helper script to run the
analysis:

```shell
docker run --rm --volume ${PWD}:/app --workdir /app itkdev/php8.3-fpm ./scripts/code-analysis
```

## Unit tests

We use [PHPUnit](https://phpunit.de/documentation.html) for unit testing.

Testing mostly centers around the conversion and parsing of certificates. For this purpose a bunch of test
certificates has been generated.

Running PHPUnit tests in a standalone Drupal module is a bit tricky, so we use a helper script to run the
analysis:

```shell
docker run --rm --volume ${PWD}:/app --workdir /app itkdev/php8.3-fpm ./scripts/unit-tests
```
