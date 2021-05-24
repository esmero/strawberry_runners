<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 10/7/18
 * Time: 3:59 PM
 */

namespace Drupal\strawberry_runners\Plugin;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Field\FieldTypePluginManager;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\PluginWithFormsTrait;


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

  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entityTypeManager,
    EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    Client $httpClient,
    ConfigFactoryInterface $config_factory,
    FileSystemInterface $file_system,
    LoggerInterface $logger
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
      $container->get('logger.channel.strawberry_runners')
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
    /* Read the command output and kill it if the proccess surpassed the timeout */
    while(!feof($pipe[1])) {
      $read .= fread($pipe[1], 8192);
      if($startTime + $timeout < microtime(true)) {
        $read = NULL;
        break;
      }
    }
    $status = proc_get_status($handle);
    $this->kill($status['pid']);
    proc_close($handle);

    return $read;
  }

  /* The proc_terminate() function doesn't end proccess properly on Windows */
  protected function kill($pid) {
    return strstr(PHP_OS, 'WIN') ? exec("taskkill /F /T /PID $pid") : exec("kill -9 $pid");
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

}
