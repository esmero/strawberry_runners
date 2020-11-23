<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 10/7/18
 * Time: 3:59 PM
 */

namespace Drupal\strawberry_runners\Plugin;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Field\FieldTypePluginManager;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\PluginWithFormsTrait;


abstract class StrawberryRunnersPostProcessorPluginBase extends PluginBase implements StrawberryRunnersPostProcessorPluginInterface, ContainerFactoryPluginInterface {

  use PluginWithFormsTrait;

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
   */

  protected $entityTypeBundleInfo;

  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entityTypeManager,
    EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    Client $httpClient
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeBundleInfo = $entityTypeBundleInfo;
    $this->entityTypeManager = $entityTypeManager;
    $this->setConfiguration($configuration);
    $this->httpClient = $httpClient;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('http_client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'jsonkey' => ['as:image'],
      'ado_type' => ['Book'],
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
    error_log(var_export($status,true));
    $this->kill($status['pid']);
    proc_close($handle);

    return $read;
  }

  /* The proc_terminate() function doesn't end proccess properly on Windows */
  protected function kill($pid) {
    return strstr(PHP_OS, 'WIN') ? exec("taskkill /F /T /PID $pid") : exec("kill -9 $pid");
  }



}
