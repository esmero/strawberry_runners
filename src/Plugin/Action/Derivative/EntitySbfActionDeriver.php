<?php

namespace Drupal\strawberry_runners\Plugin\Action\Derivative;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Action\Plugin\Action\Derivative\EntityActionDeriverBase;
use Drupal\node\NodeInterface;

/**
 * Provides an action deriver that returns entities that have/may have a SBF.
 *
 * @see \Drupal\strawberry_runners\Plugin\Action\StrawberryRunnersPostProcess
 */
class EntitySbfActionDeriver extends EntityActionDeriverBase {

  /**
   * {@inheritdoc}
   */
  protected function isApplicable(EntityTypeInterface $entity_type) {
    return $entity_type->entityClassImplements(NodeInterface::class);
  }
}
