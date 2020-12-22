<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/4/19
 * Time: 4:19 PM
 */

namespace Drupal\strawberry_runners\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\FileInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginManager;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\search_api\ParseMode\ParseModePluginManager;


/**
 * Process the JSON payload provided by the webhook.
 *
 * @QueueWorker(
 *   id = "strawberryrunners_process_background",
 *   title = @Translation("Strawberry Runners Process to Index Queue Worker"),
 * )
 */
class backgroundPostProcessorQueueWorker extends AbstractPostProcessorQueueWorker {
}
