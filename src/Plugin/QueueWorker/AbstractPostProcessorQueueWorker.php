<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/4/19
 * Time: 4:19 PM
 */

namespace Drupal\strawberry_runners\Plugin\QueueWorker;

use Drupal;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\FileInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface;
use Drupal\strawberryfield\Semantic\ActivityStream;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginManager;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\search_api\ParseMode\ParseModePluginManager;

abstract class AbstractPostProcessorQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

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
   * The parse mode manager.
   *
   * @var \Drupal\search_api\ParseMode\ParseModePluginManager
   */
  protected $parseModeManager;

  /**
   * Constructor.
   *
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginManager $strawberry_runner_processor_plugin_manager
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $key_value
   * @param \Psr\Log\LoggerInterface $logger
   * @param \Drupal\search_api\ParseMode\ParseModePluginManager $parse_mode_manager
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, StrawberryRunnersPostProcessorPluginManager $strawberry_runner_processor_plugin_manager, FileSystemInterface $file_system, StreamWrapperManagerInterface $stream_wrapper_manager, KeyValueFactoryInterface $key_value, LoggerInterface $logger, ParseModePluginManager $parse_mode_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->strawberryRunnerProcessorPluginManager = $strawberry_runner_processor_plugin_manager;
    $this->fileSystem = $file_system;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->keyValue = $key_value;
    $this->logger = $logger;
    $this->parseModeManager = $parse_mode_manager;
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
      $container->get('logger.channel.strawberry_runners'),
      $container->get('plugin.manager.search_api.parse_mode')
    );
  }

  /**
   * Get the extractor plugin.
   *
   * @return StrawberryRunnersPostProcessorPluginInterface|NULL
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
    return NULL;
  }


  /**
   * Gets all Children of the currently being processed Processor Plugin
   *
   * @param string $current_id
   *
   * @return array
   */
  private function getChildProcessorIds(string $plugin_config_entity_id): array {
    /* @var $plugin_config_entities \Drupal\strawberry_runners\Entity\strawberryRunnerPostprocessorEntity[] */
    $plugin_config_entities = $this->entityTypeManager->getListBuilder('strawberry_runners_postprocessor')
      ->load();
    $active_plugins = [];
    // This kids should be cached;
    // We basically want here what type of processor this is and its input_argument and input_options
    $plugin_definitions = $this->strawberryRunnerProcessorPluginManager->getDefinitions();

    error_log('getting child processors');
    foreach ($plugin_config_entities as $plugin_config_entity) {
      // Only get first level (no Parents) and Active ones.
      if ($plugin_config_entity->isActive() && $plugin_config_entity->getParent() == $plugin_config_entity_id) {
        $active_plugins[] = [
          'config_entity' => $plugin_config_entity,
          'plugin_definition' => $plugin_definitions[$plugin_config_entity->getPluginid()],
        ];
      }
    }
    return $active_plugins;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {

    $processor_instance = $this->getProcessorPlugin($data->plugin_config_entity_id);
    if (!$processor_instance) {
      $this->logger->log(LogLevel::ERROR, 'Strawberry Runners Processing aborted because the @processor may be inactive', ['@processor' => $processor_instance->label()]);
      return;
    }
    $processor_config = $processor_instance->getConfiguration();

    if (!isset($data->fid) || $data->fid == NULL || !isset($data->nid) || $data->nid == NULL || !is_array($data->metadata)) {
      return;
    }
    $file = $this->entityTypeManager->getStorage('file')->load($data->fid);
    // 0 byte files have checksum, check what it is!
    if ($file === NULL || !isset($data->metadata['checksum'])) {
      error_log('Sorry the file does not exist or has no checksum yet. We really need the checksum');
      return;
    }

    //We only deal with NODES.
    $entity = $this->entityTypeManager->getStorage('node')
      ->load($data->nid);

    if (!$entity) {
      error_log('Sorry the Node ID passed to this processor does not exist.');
    }

    //@TODO should we wrap this around a try catch?
    $filelocation = $this->ensureFileAvailability($file);

    if ($filelocation === NULL) {
      return;
    }
    // Means we could pass also a file directly anytime
    $data->filelocation = $filelocation;


    if (!isset($processor_config['output_destination']) || !is_array($processor_config['output_destination'])) {
      $this->logger->log(LogLevel::ERROR, 'Strawberry Runners Processing aborted because there is no output destination setup for @processor', ['@processor' => $processor_instance->label()]);
      return;
    }

    $enabled_processor_output_types = array_intersect_assoc(StrawberryRunnersPostProcessorPluginInterface::OUTPUT_TYPE, $processor_config['output_destination']);

    // make all this options constants

    $tobeindexed = FALSE;
    $tobeupdated = FALSE;
    $tobechained = FALSE;
    error_log(print_r($enabled_processor_output_types, true));
    if (array_key_exists('searchapi', $enabled_processor_output_types) && $enabled_processor_output_types['searchapi'] === 'searchapi') {
      error_log("processor says this goes into Solr");
      $tobeindexed = TRUE;
    }
    if (array_key_exists('file', $enabled_processor_output_types) && $enabled_processor_output_types['file'] === 'file') {
      error_log("processor says this goes into Solr");
      $tobeupdated = TRUE;
    }
    if (array_key_exists('plugin', $enabled_processor_output_types) && $enabled_processor_output_types['plugin'] === 'plugin') {
      error_log("processor says this goes into Solr");
      $tobechained = TRUE;
    }


    // Only applies to those that will be indexed
    if ($tobeindexed) {
      try {
        // Get which indexes have our StrawberryfieldFlavorDatasource enabled!
        $indexes = StrawberryfieldFlavorDatasource::getValidIndexes();
        $keyvalue_collection = 'Strawberryfield_flavor_datasource_temp';
        $item_ids = [];
        $inindex = 1;
        $input_property = $processor_instance->getPluginDefinition()['input_property'];
        $input_argument = $processor_instance->getPluginDefinition()['input_argument'];

        // @TODO If argument is not here, do we return??
        $data->{$input_argument} = isset($data->{$input_argument}) ? $data->{$input_argument} : 1;

        if (is_a($entity, TranslatableInterface::class)) {
          $translations = $entity->getTranslationLanguages();
          foreach ($translations as $translation_id => $translation) {
            //@TODO here, the number 1 needs to come from the sequence.
            $item_id = $entity->id() . ':' . $data->{$input_argument} . ':' . $translation_id . ':' . $file->uuid() . ':' . $data->plugin_config_entity_id;
            // a single 0 as return will force us to reindex.
            $inindex = $inindex * $this->flavorInSolrIndex($item_id, $data->metadata['checksum'], $indexes);
            $item_ids[] = $item_id;
          }
        }

        // Check if we already have this entry in Solr
        if ($inindex !== 0) {
          error_log('Already in search index, skipping');
        }
        $inkeystore = TRUE;
        // Skip file if element for every language is found in key_value collection.
        foreach ($item_ids as $item_id) {
          $processed_data = $this->keyValue->get($keyvalue_collection)
            ->get($item_id);
          if (empty($processed_data) || !isset($processed_data->checksum) ||
            empty($processed_data->checksum) ||
            $processed_data->checksum != $data->metadata['checksum']) {
            $inkeystore = $inkeystore && FALSE;
          }
        }
        //@TODO allow a force in case of corrupted key value? Partial output
        // Extragenoxus weird data?
        if (($inindex === 0 || $inkeystore === FALSE) ||
          $data->force == TRUE) {
          // Extract file and save it in key_value collection.
          $io = $this->invokeProcessor($processor_instance, $data);

          // Check if $io->output exists?
          $toindex = new stdClass();
          $toindex->fulltext = $io->output->searchapi;
          $toindex->checksum = $data->metadata['checksum'];

          $datasource_id = 'strawberryfield_flavor_datasource';
          foreach ($indexes as $index) {
            // For each language we do this
            // Eventually we will want to have different outputs per language?
            // But maybe not for HOCR. since the doc will be the same.
            foreach ($item_ids as $item_id) {
              error_log('processing just run');
              error_log('writing to keyvalue');
              error_log($item_id);
              $this->keyValue->get($keyvalue_collection)
                ->set($item_id, $toindex);
            }
            $index->trackItemsInserted($datasource_id, $item_ids);
          }
        }
      } catch (Exception $exception) {
        $message_params = [
          '@file_id' => $data->fid,
          '@entity_id' => $data->nid,
          '@message' => $exception->getMessage(),
        ];
        if (!isset($data->extract_attempts)) {
          $data->extract_attempts = 0;
          $this->logger->log(LogLevel::ERROR, 'Strawberry Runners Processing failed with message: @message File id @file_id at Node @entity_id.', $message_params);
        }
        if ($data->extract_attempts < 3) {
          $data->extract_attempts++;
          Drupal::queue('strawberryrunners_process_index')->createItem($data);
        }
        else {
          $message_params = [
            '@file_id' => $data->fid,
            '@entity_id' => $data->nid,
          ];
          $this->logger->log(LogLevel::ERROR, 'Strawberry Runners Processing failed after 3 attempts File Id @file_id at Node @entity_id.', $message_params);
        }
      }
    }
    else {
      // This will not
      $io = $this->invokeProcessor($processor_instance, $data);
      error_log('we do not need to index this');
      error_log(var_export($io, TRUE));
      error_log('we do not need to index this');
    }
    // Means we got a file back from the processor
    if ($tobeupdated && isset($io->output->file) && !empty($io->output->file)) {
      $this->updateNode($entity, $data, $io);
      error_log('we got a file');
    }
    // Chains a new Processor into the QUEUE, if there are any children
    if ($tobechained && isset($io->output->plugin) && !empty($io->output->plugin)) {
      error_log('Time to check on children');
      error_log($data->plugin_config_entity_id);
      $childprocessors = $this->getChildProcessorIds($data->plugin_config_entity_id);
      error_log(print_r($childprocessors, TRUE));
      foreach ($childprocessors as $plugin_info) {
        $childdata = clone $data; // So we do not touch original data
        /* @var  $strawberry_runners_postprocessor_config \Drupal\strawberry_runners\Entity\strawberryRunnerPostprocessorEntity */
        $postprocessor_config_entity = $plugin_info['config_entity'];
        $input_property = $plugin_info['plugin_definition']['input_property'];
        $input_argument = $plugin_info['plugin_definition']['input_argument'];
        //@TODO check if this are here and not null!
        // $io->ouput will contain whatever the output is
        // We will check if the child processor
        // contains a property contained in $output
        // If so we check if there is a single value or multiple ones
        // For each we enqueue a child using that property in its data

        // Possible input properties:
        // - Can come from the original Data (most likely)
        // - May be overriden by the $io->output, e.g when a processor generates a file that is not part of any node
        $input_property_value = isset($io->output->plugin) && isset($io->output->plugin[$input_property]) ? $io->output->plugin[$input_property] : $data->{$input_property};
        // Warning Diego. This may lead to a null
        $childdata->{$input_property} = $input_property_value;
        $childdata->plugin_config_entity_id = $postprocessor_config_entity->id();
        $input_argument_value = isset($io->output->plugin) && isset($io->output->plugin[$input_argument]) ? $io->output->plugin[$input_argument] : $data->{$input_argument};
        error_log(print_r($input_argument_value, TRUE));
        if (is_array($input_argument_value)) {
          foreach ($input_argument_value as $value) {
            // Here is the catch.
            // Output properties may be many
            // Input Properties matching always need to be one
            if (!is_array($value)) {
              $childdata->{$input_argument} = $value;
              error_log("should add to queue {$childdata->plugin_config_entity_id}");
              error_log(var_export($childdata, TRUE));
              Drupal::queue('strawberryrunners_process_index')
                ->createItem($childdata);
            }
          }
        }
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
    }
    else {
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

  /**
   * This method actually invokes the processor.
   *
   * @param StrawberryRunnersPostProcessorPluginInterface $processor_instance
   * @param \stdClass $data
   *
   * @return \stdClass
   */
  private function invokeProcessor(StrawberryRunnersPostProcessorPluginInterface $processor_instance, stdClass $data): stdClass {

    $input_property = $processor_instance->getPluginDefinition()['input_property'];
    $input_argument = $processor_instance->getPluginDefinition()['input_argument'];

    $io = new stdClass();
    $input = new stdClass();

    // @NOTE: this is the only place where we just pass filelocation fixed instead of the
    // actual property named $input_property. Which may be weird?
    $input->{$input_property} = $data->filelocation;
    $input->{$input_argument} = isset($data->{$input_argument}) ? $data->{$input_argument} : 1;
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
    return $io;
  }

  /**
   * Checks Search API indexes for an Document ID and Checksum Match
   *
   * @param string $key
   * @param string $checksum
   * @param array $indexes
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\search_api\SearchApiException
   */
  public function flavorInSolrIndex(string $key, string $checksum, array $indexes): int {
    /* @var \Drupal\search_api\IndexInterface[] $indexes */

    $count = 0;
    foreach ($indexes as $search_api_index) {

      // Create the query.
      $query = $search_api_index->query([
        'limit' => 1,
        'offset' => 0,
      ]);

      /*$query->setFulltextFields([
        'title',
        'body',
        'filename',
        'saa_field_file_document',
        'saa_field_file_news',
        'saa_field_file_page'
      ]);*/
      //$parse_mode = $this->parseModeManager->createInstance('direct');
      $parse_mode = $this->parseModeManager->createInstance('terms');
      $query->setParseMode($parse_mode);
      // $parse_mode->setConjunction('OR');
      // $query->keys($search);
      $query->sort('search_api_relevance', 'DESC');

      $query->addCondition('search_api_id', 'strawberryfield_flavor_datasource/' . $key)
        ->addCondition('search_api_datasource', 'strawberryfield_flavor_datasource')
        ->addCondition('checksum', $checksum);
      //$query = $query->addCondition('ss_checksum', $checksum);
      // If we allow processing here Drupal adds Content Access Check
      // That does not match our Data Source \Drupal\search_api\Plugin\search_api\processor\ContentAccess
      // we get this filter (see 2nd)
      /*
       *   array (
        0 => 'ss_search_api_id:"strawberryfield_flavor_datasource/2006:1:en:3dccdb09-f79f-478e-81c5-0bb680c3984e:ocr"',
        1 => 'ss_search_api_datasource:"strawberryfield_flavor_datasource"',
        2 => '{!tag=content_access,content_access_enabled,content_access_grants}(ss_search_api_datasource:"entity:file" (+(bs_status:"true" bs_status_2:"true") +(sm_node_grants:"node_access_all:0" sm_node_grants:"node_access__all")))',
        3 => '+index_id:default_solr_index +hash:1evb7z',
        4 => 'ss_search_api_language:("en" "und" "zxx")',
      ),
       */
      // Another solution would be to make our conditions all together an OR
      // But no post processing here is also good, faster and we just want
      // to know if its there or not.
      $query->setProcessingLevel(QueryInterface::PROCESSING_NONE);
      $results = $query->execute();

      // $solr_response = $results->getExtraData('search_api_solr_response');
      // In case of more than one Index with the same Data Source we accumulate
      $count = $count + (int) $results->getResultCount();

    }
    // This is a good one. If i have multiple indexes, but one is missing the i assume
    // reprocessing is needed
    // But if not, then i return 1, which means we have them all
    // FUTURE thinking is the best.
    $return = ($count == count($indexes)) ? 1 : 0;
    return $return;
    // Keys we need in the Search API
    // - ss_search_api_id == $key
    // A checksum field == Should be configurable?
    // Let's start by naming it checksum? If not present we may trigger some Logger/alert?
    // Or maybe we can use D8/D9 Status mechanic to let the user know this module
    // needs it in the data flavor.
  }

  /**
   * Updates a node with data passed from a processors io and original data
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   * @param \stdClass $data
   * @param \stdClass $io
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function updateNode(ContentEntityInterface $entity, stdClass $data, stdClass $io) {
    error_log(print_r($data, TRUE));
    error_log(print_r($io, TRUE));
    /** @var $itemfield \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem */

    $itemfield = $entity->get($data->field_name)->get($data->field_delta);
    $field_content = $itemfield->provideDecoded(TRUE);
    if (!isset($field_content['ap:entitymapping']['entity:file']) ||
      !in_array('flv:' . $data->plugin_config_entity_id, $field_content['ap:entitymapping']['entity:file'])) {
      $field_content['ap:entitymapping']['entity:file'][] = 'flv:' . $data->plugin_config_entity_id;
    }

    //$oldfiles = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $io->output->file]);
    //$newfile = $this->entityTypeManager->getStorage('file')->delete($oldfiles);

    $newfile = $this->entityTypeManager->getStorage('file')->create([
      'uri' => $io->output->file,
      'status' => 0,
    ]);
    $uniqueid = $data->asstructure_uniqueid;
    $jsonkey = $data->asstructure_key;
    try {
      $newfile->save();
      $newfile->id();
      $field_content['flv:' . $data->plugin_config_entity_id][] = (int) $newfile->id();
      $field_content['flv:' . $data->plugin_config_entity_id] = array_unique($field_content['flv:' . $data->plugin_config_entity_id]);
      $field_content[$jsonkey][$uniqueid]['flv:' . $data->plugin_config_entity_id] = $this->addActivityStream($data->plugin_config_entity_id);
      $itemfield->setMainValueFromArray($field_content);
      // Should we check decide on this? Safer is a new revision, but also an overhead
      // $entity->setNewRevision(FALSE);
      $entity->save();
    } catch (Exception $exception) {
      $message_params = [
        '@file_id' => $data->fid,
        '@entity_id' => $data->nid,
        '@newfile_path' => $io->output->file,
        '@message' => $exception->getMessage(),
      ];
      $this->logger->log(LogLevel::ERROR, 'Strawberry Runners Processing failed to update Node and add @newfile_path with message: @message File id @file_id at Node @entity_id.', $message_params);
    }

  }

  protected function addActivityStream($name = NULL) {

    // We use this to keep track of the webform used to create/update the field's json
    $eventBody = [
      'summary' => 'Generator',
      'endTime' => date('c'),
    ];

    $actor_properties = [
      'name' => $name ?: 'NaW',
    ];
    $event_type = ActivityStream::ASTYPES['Create'];

    $activitystream = new ActivityStream($event_type, $eventBody);

    $activitystream->addActor(ActivityStream::ACTORTYPES['Service'], $actor_properties);
    return $activitystream->getAsBody() ?: [];

  }


}
