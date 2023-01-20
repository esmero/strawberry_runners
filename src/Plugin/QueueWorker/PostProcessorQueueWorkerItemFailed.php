<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/4/19
 * Time: 4:19 PM
 */

namespace Drupal\strawberry_runners\Plugin\QueueWorker;

use Drupal;

/**
 * This queue as a container for inspecting failed strawberry runner process items.
 *
 * @QueueWorker(
 *   id = "strawberryrunners_process_item_failed",
 *   title = @Translation("Failed Strawberry Runner Items for Review"),
 * )
 */
class postProcessorQueueWorkerItemFailed extends AbstractPostProcessorQueueWorker {
  /**
   * Processing an item simply removes it from the queue.
   *
   */
  public function processItem($data) {
    $processor_instance = $this->getProcessorPlugin($data->plugin_config_entity_id);
    $message_params = [
      '@processor' => $processor_instance->getPluginId(),
      '@queue' => $this->getBaseId(),
      ];
    Drupal::messenger()->addMessage(t('Processing of @queue::@processor queue items simply removes them from this queue.', $message_params), 'status');
  }

}
