<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 4/23/18
 * Time: 9:02 PM
 */

namespace Drupal\strawberry_runners\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Access\AccessResult;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class WebhookController.
 */
class Redirect extends ControllerBase {

  /**
   * Drupal\Core\Logger\LoggerChannelFactory definition.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactory
   */
  protected $logger;

  /**
   * Drupal\Core\Queue\QueueFactory definition.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  /**
   * Enable or disable debugging.
   *
   * @var bool
   */
  protected $debug = FALSE;

  /**
   * Secret to compare against a passed token.
   *
   * Requires $config['strawberry_runners']['webhooktoken'] = 'yourtokeninsettingsphp'; in settings.php.
   *
   * @var string
   */
  protected $secret = NULL;

  /**
   * Constructs a new WebhookController object.
   */
  public function __construct(LoggerChannelFactory $logger, QueueInterface $queue) {
    $this->logger = $logger->get('strawberry_runners');
    $this->queue = $queue;
    $secret = \Drupal::service('config.factory')->get('strawberry_runners')->get('webhooktoken');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('logger.factory'),
      $container->get('queue')->get('process_payload_queue_worker')
    );
  }

  /**
   * Capture the payload.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A simple string and 302 response.
   */
  public function islandora(Request $request, $PID) {
    if ($PID) {
      $parts = explode(':', $PID);
    }
    $response = new RedirectResponse('/do/'.$parts[1], 302);
    return $response;
  }


}