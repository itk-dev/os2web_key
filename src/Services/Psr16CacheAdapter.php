<?php

namespace Drupal\os2web_key\Services;

use Drupal\Core\Cache\CacheBackendInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 Cache Adapter for Drupal.
 */
class Psr16CacheAdapter implements CacheInterface {

  /**
   * Constructs a new Psr16CacheAdapter.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cacheBackend
   *   The Drupal cache backend.
   */
  public function __construct(private readonly CacheBackendInterface $cacheBackend) {
  }

  /**
   * {@inheritdoc}
   */
  public function get($key, $default = NULL): mixed {
    $cache = $this->cacheBackend->get($key);
    return $cache ? $cache->data : $default;
  }

  /**
   * {@inheritdoc}
   */
  public function set($key, $value, $ttl = NULL): bool {
    try {
      $this->cacheBackend->set($key, $value, $ttl);
      return TRUE;
    }
    catch (\Exception $exception) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete($key): bool {
    try {
      $this->cacheBackend->delete($key);
      return TRUE;
    }
    catch (\Exception $exception) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function clear(): bool {
    try {
      $this->cacheBackend->deleteAll();
      return TRUE;
    }
    catch (\Exception $exception) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple($keys, $default = NULL): iterable {
    // Not implemented for simplicity.
    throw new \Exception('Method not implemented.');
  }

  /**
   * {@inheritdoc}
   *
   * @param iterable<mixed, mixed> $values
   *   A list of key => value pairs for a multiple-set operation.
   * @param \DateInterval|int|null $ttl
   *   Optional. The TTL value of this item.
   */
  public function setMultiple($values, $ttl = NULL): bool {
    // Not implemented for simplicity.
    throw new \Exception('Method not implemented.');
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple($keys): bool {
    // Not implemented for simplicity.
    throw new \Exception('Method not implemented.');
  }

  /**
   * {@inheritdoc}
   */
  public function has($key): bool {
    return $this->cacheBackend->get($key) !== FALSE;
  }

}
