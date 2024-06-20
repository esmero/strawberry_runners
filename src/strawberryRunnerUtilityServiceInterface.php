<?php

namespace Drupal\strawberry_runners;

use Drupal\Core\Entity\ContentEntityInterface;

interface strawberryRunnerUtilityServiceInterface {

  /**
   * Fetches matching processors for a given ADO and enqueues them.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   * @param array                                      $sbf_fields
   *
   * @param bool                                       $force
   *      If TRUE Overrides any $force argument passed via metadata
   *
   * @param array                                      $filter
   *      If it contains the Keys for Processor Config entities, only those
   *      will run, if empty all will run. Defaults to all.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function invokeProcessorForAdo(ContentEntityInterface $entity,
    array $sbf_fields, bool $force = FALSE, array $filter = []
  ): void;

  /**
   * Gets all Currently Active PLugin Entities and Configs initialized
   *
   * @param bool $onlyRoot
   *     TRUE means we only get Top/first call Processors. FALSE, any processor at any level.
   * @return array
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function getActivePluginConfigs($onlyRoot = TRUE):array;

}
