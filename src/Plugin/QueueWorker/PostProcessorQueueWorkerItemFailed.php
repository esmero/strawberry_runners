<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/4/19
 * Time: 4:19 PM
 */

namespace Drupal\strawberry_runners\Plugin\QueueWorker;

use Drupal;
use Drupal\Core\Annotation\QueueWorker;

/**
 * This queue is a container for inspecting failed strawberry runner process items, and optionally processing to re-enqueue (re-try) them.
 *
 * @QueueWorker(
 *   id = "strawberryrunners_process_item_failed",
 *   title = @Translation("Failed Strawberry Runner Items for Review/Re-Try"),
 * )
 */
class postProcessorQueueWorkerItemFailed extends AbstractPostProcessorQueueWorker {
  /**
   * Processing a failed strawberry runner queue item moves it back to its original queue to re-try.
   * The user's alternative is to remove the items from this queue after inspection and, hopefully,
   * figuring out a plan of action to resolve the original problem and start over.
   *
   */
  public function processItem($data) {
    $processor_instance = $this->getProcessorPlugin($data->plugin_config_entity_id);
    $message_params = [
      '@processor' => $processor_instance->getPluginId(),
      '@queue' => $this->getBaseId(),
      ];
    if(!empty($data->item_failure_data) && !empty($data->item_failure_data['@queue'])) {
      // Add the item back to the original queue.
      $original_queue_id = $data->item_failure_data['@queue'];
      unset($data->item_failure_data);
      $new_queue_item_id = Drupal::queue($original_queue_id, TRUE)
        ->createItem($data);
      $message_params['@original'] = $original_queue_id;
      $message_params['@new_queue_item_id'] = $new_queue_item_id;
      Drupal::messenger()->addMessage(t('Processing of @queue::@processor queue: the item was moved it back to the @original queue to re-try.', $message_params), 'status');
    }
     else {
       Drupal::messenger()->addMessage(t('Processing of  @queue::@processor failed because no original queue name was found to re-enqueue it to.', $message_params), 'error');
       Drupal::logger()->error('Processing of  @queue::@processor failed because no original queue was found to re-enqueue it to.', $message_params);
     }
  }

}
