<?php
namespace Drupal\strawberry_runners\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\Core\Entity\EntityChangedInterface;


/**
 * Provides an interface defining a Metadata Display entity.
 * @ingroup format_strawberryfield
 */
interface runnerItemEntityInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

  /**
   * Processes this Item.
   *
   * @param array $context
   *
   * @return array
   */
  public function processItem();

  /**
   * enqueues this Item.
   *
   * @param array $context
   *
   * @return array
   */
  public function enqueueItem();

  /**
   * enqueues this Item.
   *
   * @param array $context
   *
   * @return array
   */
  public function getNextSetId();

}
