<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/4/19
 * Time: 4:19 PM
 */

namespace Drupal\strawberry_runners\Plugin\QueueWorker;

use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Process the JSON payload provided by the webhook.
 */
#[QueueWorker(
  id: 'strawberryrunners_process_index',
  title: new TranslatableMarkup('Strawberry Runners Process via Cron Queue Worker'),
  cron: [
    'time' => 180
  ]
)]
class IndexPostProcessorQueueWorker extends AbstractPostProcessorQueueWorker {
}
