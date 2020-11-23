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
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Component\Serialization\Json;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\FileInterface;
use Drupal\search_api\Plugin\search_api\datasource\ContentEntity;
use Drupal\search_api_attachments\Plugin\search_api\processor\FilesExtractor;
use Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginManager;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;

/**
 * Process the JSON payload provided by the webhook.
 *
 * @QueueWorker(
 *   id = "strawberryrunners_process_index",
 *   title = @Translation("Strawberry Runners Process to Index Queue Worker"),
 *   cron = {"time" = 5}
 * )
 */
class IndexPostProcessorQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Drupal\Core\Entity\EntityTypeManager definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginManager
   */
  private $strawberryRunnerProcessorPluginManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * Key value service.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueFactoryInterface
   */
  protected $keyValue;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;


  /**
   * Constructor.
   *
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_field_manager
   * @param \Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginManager $strawberry_runner_processor_plugin_manager
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, StrawberryRunnersPostProcessorPluginManager $strawberry_runner_processor_plugin_manager,  FileSystemInterface $file_system, StreamWrapperManagerInterface $stream_wrapper_manager, KeyValueFactoryInterface $key_value, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->strawberryRunnerProcessorPluginManager = $strawberry_runner_processor_plugin_manager;
    $this->fileSystem = $file_system;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->keyValue = $key_value;
    $this->logger = $logger;
  }

  /**
   * Implementation of the container interface to allow dependency injection.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   *
   * @return static
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      empty($configuration) ? [] : $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('strawberry_runner.processor_manager'),
      $container->get('file_system'),
      $container->get('stream_wrapper_manager'),
      $container->get('keyvalue'),
      $container->get('logger.channel.strawberry_runners')
    );
  }

  /**
   * Get the extractor plugin.
   *
   * @return object
   *   The plugin.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  protected function getProcessorPlugin($plugin_config_entity_id) {
    // Get extractor configuration.
    /* @var $plugin_config_entity \Drupal\strawberry_runners\Entity\strawberryRunnerPostprocessorEntityInterface */
    $plugin_config_entity = $this->entityTypeManager->getStorage(
      'strawberry_runners_postprocessor'
    )->load($plugin_config_entity_id);

    if ($plugin_config_entity->isActive()) {
      $entity_id = $plugin_config_entity->id();
      $configuration_options = $plugin_config_entity->getPluginconfig();
      $configuration_options['configEntity'] = $entity_id;
      /* @var \Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface $plugin_instance */
      $plugin_instance = $this->strawberryRunnerProcessorPluginManager->createInstance(
        $plugin_config_entity->getPluginid(),
        $configuration_options
      );
      return $plugin_instance;
    }
  }


  /**
   * {@inheritdoc}
   */
  public function processItem($data) {

    $processor_instance = $this->getProcessorPlugin($data->plugin_config_entity_id);

    if (!isset($data->fid) || $data->fid == NULL || !isset($data->nid) || $data->nid == NULL || !is_array($data->metadata)) {
      return;
    }
    $file = $this->entityTypeManager->getStorage('file')->load($data->fid);

    if ($file === NULL || !isset($data->metadata['checksum'])) {
      error_log('Sorry the file does not exist or has no checksum yet. We really need the checksum');
      return;
    }
    //@TODO should we wrap this around a try catch?
    $filelocation = $this->ensureFileAvailability($file);

    if ($filelocation === NULL) {
      return;
    }

    try {
      $keyvalue_collection = 'Strawberryfield_flavor_datasource_temp';
      $key = $keyvalue_collection . ':' . $file->uuid().':'.$data->plugin_config_entity_id;

      //We only deal with NODES.
      $entity = $this->entityTypeManager->getStorage('node')
        ->load($data->nid);

      if(!$entity) {
        return;
      }

      // Skip file if element is found in key_value collection.
      $processed_data = $this->keyValue->get($keyvalue_collection)->get($key);
      error_log('Is this already in our temp keyValue?');
      error_log(empty($processed_data));
      //@TODO allow a force in case of corrupted key value? Partial output
      // Extragenous weird data?
      if (true || empty($processed_data) ||
        $data->force == TRUE ||
        (!isset($processed_data->checksum) ||
          empty($processed_data->checksum) ||
          $processed_data->checksum != $data->metadata['checksum'])) {
        // Extract file and save it in key_value collection.
        $io = new \stdClass();
        $input =  new \stdClass();
        $input->filepath = $filelocation;
        $input->page_number = 1;
        // The Node UUID
        $input->nuuid = $data->nuuid;
        // All the rest of the associated Metadata in an as:structure
        $input->metadata = $data->metadata;
        $io->input = $input;
        $io->output = NULL;
        //@TODO implement the TEST and BENCHMARK logic here
        // RUN should return exit codes so we can know if something failed
        // And totally discard indexing.
        $extracted_data = $processor_instance->run($io, StrawberryRunnersPostProcessorPluginInterface::PROCESS);
        error_log ('processing just run');
        error_log('writing to keyvalue');
        error_log($key);
        $toindex = new \stdClass();
        $toindex->fulltext = $io->output;
        $toindex->checksum = $data->metadata['checksum'];
        error_log(var_export($toindex,true));
        $this->keyValue->get($keyvalue_collection)->set($key, $toindex);

        // Get which indexes have our StrawberryfieldFlavorDatasource enabled!
        $indexes = StrawberryfieldFlavorDatasource::getValidIndexes();

        $item_ids = [];
        if (is_a($entity, TranslatableInterface::class)) {
          $translations = $entity->getTranslationLanguages();
          foreach ($translations as $translation_id => $translation) {
            $item_ids[] = $entity->id() . ':'.'1' .':'.$translation_id.':'.$file->uuid().':'.$data->plugin_config_entity_id;
          }
        }
        error_log(var_export($item_ids,true));
        $datasource_id = 'strawberryfield_flavor_datasource';
        foreach ($indexes as $index) {
          $index->trackItemsInserted($datasource_id, $item_ids);
        }
      }
    }
    catch (\Exception $exception) {
      if ($data->extract_attempts < 3) {
        $data->extract_attempts++;
        \Drupal::queue('strawberryrunners_process_index')->createItem($data);
      }
      else {
        $message_params = [
          '@file_id' => $data->fid,
          '@entity_id' => $data->nid,
        ];
        $this->logger->log(LogLevel::ERROR, 'Strawberry Runners Processing failed after 3 attempts @file_id for @entity_type @entity_id.', $message_params);
      }
    }
  }

  /**
   * Move file to local to if needed process.
   *
   * @param \Drupal\file\FileInterface $file
   *   The File URI to look at.
   *
   * @return array
   *   Output of processing chain for a particular file.
   */
  private function ensureFileAvailability(FileInterface $file) {
    $uri = $file->getFileUri();
    // Local stream.
    $cache_key = md5($uri);
    // Check first if the file is already around in temp?
    // @TODO can be sure its the same one? Ideas?
    if (is_readable(
      $this->fileSystem->realpath(
        'temporary://sbr_' . $cache_key . '_' . basename($uri)
      )
    )) {
      $templocation = $this->fileSystem->realpath(
        'temporary://sbr_' . $cache_key . '_' . basename($uri)
      );
    }
    else {
      $templocation = $this->fileSystem->copy(
        $uri,
        'temporary://sbr_' . $cache_key . '_' . basename($uri),
        FileSystemInterface::EXISTS_REPLACE
      );
      $templocation = $this->fileSystem->realpath(
        $templocation
      );
    }


    if (!$templocation) {
      $this->loggerFactory->get('strawberry_runners')->warning(
        'Could not adquire a local accessible location for text extraction for file with URL @fileurl',
        [
          '@fileurl' => $file->getFileUri(),
        ]
      );
      return FALSE;
    } else {
      return $templocation;
    }
  }

  /**
   * Helper method to get the real path from an uri.
   *
   * @param string $uri
   *   The URI of the file, e.g. public://directory/file.jpg.
   *
   * @return mixed
   *   The real path to the file if it is a local file. An URL otherwise.
   */
  public function getRealpath($uri) {
    $wrapper = $this->streamWrapperManager->getViaUri($uri);
    $scheme = $this->streamWrapperManager->getScheme($uri);
    $local_wrappers = $this->streamWrapperManager->getWrappers(StreamWrapperInterface::LOCAL);
    if (in_array($scheme, array_keys($local_wrappers))) {
      return $wrapper->realpath();
    }
    else {
      return $wrapper->getExternalUrl();
    }
  }

}
