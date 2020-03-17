<?php

namespace Drupal\strawberry_runners\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Provides an interface for defining Strawberry Key Name Providers entities.
 */
interface strawberryRunnerPostprocessorEntityInterface extends ConfigEntityInterface {

  /**
   * @return bool
   */
  public function isActive(): bool;

  /**
   * @param bool $active
   */
  public function setActive(bool $active): void;

  /**
   * @return array
   */
  public function getPluginconfig(): array;

  /**
   * @param array $pluginconfig
   */
  public function setPluginconfig(array $pluginconfig): void;

}
