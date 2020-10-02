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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class WebhookController.
 */
class StrawberryRunnersWebhookController extends ControllerBase {

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
   * @return \Symfony\Component\HttpFoundation\Response
   *   A simple string and 200 response.
   */
  public function capture(Request $request) {

    $response = new Response();

    // Capture the payload.
    $payload = $request->getContent();

    // Check if it is empty.
    if (empty($payload)) {
      $message = 'The Webhook payload was empty.';
      $this->logger->error($message);
      $response->setContent($message);
      return $response;
    }

    // Use temporarily to inspect payload.
    if ($this->debug) {
      $this->logger->debug('<pre>@payload</pre>', ['@payload' => $payload]);
    }

    // Add the $payload to our defined queue.
    $this->queue->createItem($payload);

    $response->setContent('Success!');
    return $response;
  }

  /**
   * Simple authorization using a token.
   *
   * @param string $token
   *    A random token only your webhook knows about.
   *
   * @return AccessResult
   *   AccessResult allowed or forbidden.
   */
  public function authorize($token) {
    // Always require a token.
    if (($token === $this->secret) && !empty($token)) {
      return AccessResult::allowed();
    }
    return AccessResult::forbidden();
  }

}