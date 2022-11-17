<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 10/7/18
 * Time: 3:59 PM
 */

namespace Drupal\strawberry_runners\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\strawberryfield\Event\StrawberryfieldFileEvent;
use Drupal\strawberryfield\StrawberryfieldEventType;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\PluginWithFormsTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;


abstract class StrawberryRunnersPostProcessorPluginBase extends PluginBase implements StrawberryRunnersPostProcessorPluginInterface, ContainerFactoryPluginInterface {

  use PluginWithFormsTrait;

  /**
   * Temporary directory setup to be used by Drupal
   * @var string
   */
  protected $temporary_directory;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface;
   */
  protected $entityTypeManager;

  /**
   * @var \GuzzleHttp\Client;
   */
  protected $httpClient;

  /**
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   *
   */
  protected $entityTypeBundleInfo;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The Cache Backend
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheBackend;

  /**
   * An array containing files that can be deleted.
   *
   * @var array
   */
  protected $instanceFiles = [];

  /**
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;


  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entityTypeManager,
    EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    Client $httpClient,
    ConfigFactoryInterface $config_factory,
    FileSystemInterface $file_system,
    LoggerInterface $logger,
    CacheBackendInterface $cache_backend,
    EventDispatcherInterface $event_dispatcher
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeBundleInfo = $entityTypeBundleInfo;
    $this->entityTypeManager = $entityTypeManager;
    $this->setConfiguration($configuration);
    $this->httpClient = $httpClient;
    // For files being processed by a binary, the Queue worker will have made sure
    // they are made local
    // \Drupal\strawberry_runners\Plugin\QueueWorker\IndexPostProcessorQueueWorker::ensureFileAvailability
    $this->fileSystem = $file_system;
    $this->temporary_directory = $this->fileSystem->getTempDirectory();
    $this->logger = $logger;
    $this->cacheBackend = $cache_backend;
    $this->eventDispatcher = $event_dispatcher;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('http_client'),
      $container->get('config.factory'),
      $container->get('file_system'),
      $container->get('logger.channel.strawberry_runners'),
      $container->get('cache.default'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'jsonkey' => ['as:image'],
      'ado_type' => ['Book'],
      'output_destination' => ['plugin' => 'plugin'],
      // Max time to run in seconds per item.
      'timeout' => 10,
      // Order in which this processor is executed in the chain
      'weight' => 0,
      // The id of the config entity from where these values came from.
      'configEntity' => '',
    ];
  }

  /**
   * @param array $parents
   * @param FormStateInterface $form_state;
   *
   * @return array
   */
  public function settingsForm(array $parents, FormStateInterface $form_state) {
    return [];
  }
  /**
   * {@inheritdoc}
   */
  public function label() {
    $definition = $this->getPluginDefinition();
    // The label can be an object.
    // @see \Drupal\Core\StringTranslation\TranslatableMarkup
    return $definition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {
    $this->configuration = $configuration + $this->defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function onDependencyRemoval(array $dependencies) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function run(\stdClass $io, $context = strawberryRunnersPostProcessorPluginInterface::PROCESS) {
    return FALSE;
  }

  protected function proc_execute($command, $timeout = 5) {
    $handle = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipe);
    $startTime = microtime(true);
    $read = NULL;
    if(!is_resource($handle)) {
      return $read;
    }
    fclose($pipe[0]);
    /* Read the command output and kill it if the process surpassed the timeout */
    while(!feof($pipe[1])) {
      $read .= fread($pipe[1], 8192);
      if($startTime + $timeout < microtime(true)) {
        $read = NULL;
        break;
      }
    }

    $status = proc_get_status($handle);
    if($status['running'] == true) {
      fclose($pipe[1]); //stdout
      fclose($pipe[2]); //stderr
      $ppid = $status['pid'];
      exec('ps -o pid,ppid', $ps_out);
      for($i = 1; $i <= count($ps_out) - 1; $i++) {
        $pid_row = preg_split('/\s+/', trim($ps_out[$i]));
        if (((int)$pid_row[1] ?? '') == $ppid && is_numeric(($pid_row[0] ?? ''))) {
          $pid_to_kill = (int) $pid_row[0];
          $this->kill($pid_to_kill);
        }
      }
      // Also kill the main one.
      $this->kill($ppid);
      proc_close($handle);
    }
    return $read;
  }

  /* The proc_terminate() function doesn't end proccess properly on Windows */
  protected function kill($pid) {
    return strstr(PHP_OS, 'WIN') ? exec("taskkill /F /T /PID $pid") : posix_kill($pid, 9);
  }

  /**
   * Replace the first occurrence of a given value in the string.
   * @see https://github.com/phannaly/laravel-helpers/blob/v1.0.3/src/String.php#L308
   *
   * @param  string  $search
   * @param  string  $replace
   * @param  string  $subject
   * @return string
   */
  public function strReplaceFirst(string $search, string $replace, string $subject) {
    if ($search === '') {
      return $subject;
    }
    $position = strpos($subject, $search);
    if ($position !== false) {
      return substr_replace($subject, $replace, $position, strlen($search));
    }

    return $subject;
  }

  public function __destruct() {
    foreach($this->instanceFiles as $instanceFile) {
      $event_type = StrawberryfieldEventType::TEMP_FILE_CREATION;
      $current_timestamp = (new DrupalDateTime())->getTimestamp();
      $event = new StrawberryfieldFileEvent($event_type, 'strawberry_runners', $instanceFile, $current_timestamp);
      // This will allow any temp file on ADO save to be managed
      // IN a queue by \Drupal\strawberryfield\EventSubscriber\StrawberryfieldEventCompostBinSubscriber
      $this->eventDispatcher->dispatch($event, $event_type);
    }
  }


}
