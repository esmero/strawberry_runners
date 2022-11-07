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
use Drupal\Core\File\Exception\FileException;
use Exception;
use Drupal\Core\Queue\RequeueException;
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
   * @var \Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginManager
   */
  private $strawberryRunnerProcessorPluginManager;

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
      $this->logger->log(LogLevel::ERROR, 'Sorry the file ID @fileid does not (longer?) exists or has no checksum yet. We really need the checksum', [
        '@fileid' => $data->fid,
      ]);
      return;
    }

    //We only deal with NODES.
    $entity = $this->entityTypeManager->getStorage('node')
      ->load($data->nid);

    if (!$entity) {
      $this->logger->log(LogLevel::ERROR, 'Sorry the Node ID @nodeid passed to the Strawberry Runners processor does not (longer?) exists. Skipping.', [
        '@nodeid' => $data->nid,
      ]);
      return;
    }

    $filelocation = $this->ensureFileAvailability($file);

    if ($filelocation === FALSE) {
      return;
    }
    // Means we could pass also a file directly anytime
    $data->filepath = $filelocation;


    if (!isset($processor_config['output_destination']) || !is_array($processor_config['output_destination'])) {
      $this->logger->log(LogLevel::ERROR, 'Strawberry Runners Processing aborted because there is no output destination setup for @processor', ['@processor' => $processor_instance->label()]);
      return;
    }

    $enabled_processor_output_types = array_intersect_assoc(StrawberryRunnersPostProcessorPluginInterface::OUTPUT_TYPE, $processor_config['output_destination']);

    // make all this options constants

    $tobeindexed = FALSE;
    $tobeupdated = FALSE;
    $tobechained = FALSE;

    if (array_key_exists('searchapi', $enabled_processor_output_types) && $enabled_processor_output_types['searchapi'] === 'searchapi') {
      $tobeindexed = TRUE;
    }
    if (array_key_exists('file', $enabled_processor_output_types) && $enabled_processor_output_types['file'] === 'file') {
      $tobeupdated = TRUE;
    }
    if (array_key_exists('plugin', $enabled_processor_output_types) && $enabled_processor_output_types['plugin'] === 'plugin') {
      $tobechained = TRUE;
    }


    // Only applies to those that will be indexed
    if ($tobeindexed) {
      try {
        // Get which indexes have our StrawberryfieldFlavorDatasource enabled!
        $indexes = StrawberryfieldFlavorDatasource::getValidIndexes();
        $keyvalue_collection = StrawberryfieldFlavorDatasource::SBFL_KEY_COLLECTION;
        $item_ids = [];
        $inindex = 1;
        $input_property = $processor_instance->getPluginDefinition()['input_property'];
        $input_argument = $processor_instance->getPluginDefinition()['input_argument'];
        $sequence_key = 1;

        // If argument is not there we will assume there is a mistake and that it is
        // a single one.
        if ($input_argument) {
          $data->{$input_argument} = $data->{$input_argument} ?? 1;
          // In case $data->{$input_argument} is an array/data we will use the key as "sequence"
          // Each processor needs to be sure it passes a single item and with a unique key
          if (is_array($data->{$input_argument})) {
            $sequence_key = array_key_first($data->{$input_argument});
          }
          else {
            $sequence_key = (int) $data->{$input_argument} ?? 1;
          }
        }
        // Here goes the main trick for making sure out $sequence_key in the Solr ID
        // Is the right now (relative to its own, 1 if single file, or an increasing number if a pdf
        // We check if the current item has siblings!
        // If not, we immediatelly, independently of the actual internal
        // $sequence is 1
        if (!isset($data->siblings) || isset($data->siblings) && $data->siblings == 1) {
          $sequence_key = 1;
        }

        if (is_a($entity, TranslatableInterface::class)) {
          $translations = $entity->getTranslationLanguages();
          foreach ($translations as $translation_id => $translation) {
            $item_id = $entity->id() . ':' . $sequence_key . ':' . $translation_id . ':' . $file->uuid() . ':' . $data->plugin_config_entity_id;
            // a single 0 as return will force us to reindex.
            $inindex = $inindex * $this->flavorInSolrIndex($item_id, $data->metadata['checksum'], $indexes);
            $item_ids[] = $item_id;
          }
        }

        // Check if we already have this entry in Solr
        if ($inindex !== 0 && !$data->force) {
          $this->logger->log(LogLevel::INFO, 'Flavor already in index for @plugin on ADO Node ID @nodeid, not forced, so skipping.',
          [
            '@plugin' => $processor_instance->getPluginId(),
            '@nodeid' => $data->nid,
          ]
          );
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
        // Allows a force in case of corrupted key value? Partial output
        // External/weird data?

        if (($inindex === 0 || $inkeystore === FALSE) ||
          $data->force == TRUE) {
          // Extract file and save it in key_value collection.
          $this->logger->log(LogLevel::INFO, 'Invoking @plugin on ADO Node ID @nodeid.',
            [
              '@plugin' => $processor_instance->getPluginId(),
              '@nodeid' => $data->nid,
            ]
          );
          $io = $this->invokeProcessor($processor_instance, $data);

          // Check if $io->output exists?
          $toindex = new stdClass();
          $toindex->fulltext = $io->output->searchapi['fulltext'] ?? '';
          $toindex->plaintext = $io->output->searchapi['plaintext'] ?? '';
          $toindex->metadata = $io->output->searchapi['metadata'] ?? [];
          $toindex->who = $io->output->searchapi['who'] ?? [];
          $toindex->where = $io->output->searchapi['where'] ?? [];
          $toindex->when = $io->output->searchapi['when'] ?? [];
          $toindex->ts = $io->output->searchapi['ts'] ?? NULL;
          // Comes from WACZ Text one
          $toindex->uri = $io->output->searchapi['uri'] ?? NULL;
          $toindex->label = $io->output->searchapi['label'] ?? NULL;
          $toindex->sentiment = $io->output->searchapi['sentiment'] ?? 0;
          $toindex->nlplang = $io->output->searchapi['nlplang'] ?? [];
          $toindex->processlang = $io->output->searchapi['processlang'] ?? [];
          $toindex->config_processor_id = $data->plugin_config_entity_id ?? '';

          // $siblings will be the amount of total children processors that were
          // enqueued for a single Processor chain.
          $toindex->sequence_total = !empty($data->siblings) ? $data->siblings : 1;
          // Be implicit about this one. No longer depend on the Solr DOC ID splitting.
          $toindex->sequence_id = $data->{$input_argument} ?? 1;
          $toindex->checksum = $data->metadata['checksum'];

          $datasource_id = 'strawberryfield_flavor_datasource';
          foreach ($indexes as $index) {
            // For each language we do this
            // Eventually we will want to have different outputs per language?
            // But maybe not for HOCR. since the doc will be the same.
            foreach ($item_ids as $item_id) {
              $this->keyValue->get($keyvalue_collection)
                ->set($item_id, $toindex);
            }
            $index->trackItemsInserted($datasource_id, $item_ids);
          }
        }
      }
      catch (Exception $exception) {
        $message_params = [
          '@file_id' => $data->fid,
          '@entity_id' => $data->nid,
          '@message' => $exception->getMessage(),
        ];
        if (!isset($data->extract_attempts)) {
          $data->extract_attempts = 0;
          $this->logger->log(LogLevel::ERROR, 'Strawberry Runners Processing failed with message: @message File id @file_id at ADO Node ID @entity_id.', $message_params);
        }
        if ($data->extract_attempts < 3) {
          $data->extract_attempts++;
          Drupal::queue('strawberryrunners_process_index', TRUE)
            ->createItem($data);
        }
        else {
          $message_params = [
            '@file_id' => $data->fid,
            '@entity_id' => $data->nid,
          ];
          $this->logger->log(LogLevel::ERROR, 'Strawberry Runners Processing failed after 3 attempts File Id @file_id at ADO Node ID @entity_id.', $message_params);
        }
      }
    }
    else {
      $io = $this->invokeProcessor($processor_instance, $data);
    }
    // Means we got a file back from the processor
    if ($tobeupdated && isset($io->output->file) && !empty($io->output->file)) {
      $this->updateNodeWithFile($entity, $data, $io);
    }
    // Chains a new Processor into the QUEUE, if there are any children
    if ($tobechained && isset($io->output->plugin) && !empty($io->output->plugin)) {
      $childprocessors = $this->getChildProcessorIds($data->plugin_config_entity_id);
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
        $input_property_value_from_plugin = TRUE;
        $input_property_value = isset($io->output->plugin) && isset($io->output->plugin[$input_property]) ? $io->output->plugin[$input_property] : NULL;
        // If was not defined by the previous processor try from the main data.
        if ($input_property_value == NULL) {
          $input_property_value_from_plugin = FALSE;
          $input_property_value = isset($data->{$input_property}) ? $data->{$input_property} : NULL;
        }

        // If still null means the child is incompatible with the parent. We abort.
        if ($input_property_value == NULL) {
          $this->logger->log(LogLevel::WARNING,
            'Sorry @childplugin is incompatible with @parentplugin or its output or the later is empty, skipping.',
            [
              '@parentplugin' => $data->plugin_config_entity_id,
              '@childplugin' => $postprocessor_config_entity->id(),
            ]);
          continue;
        }
        // Warning Diego. This may lead to a null
        $childdata->{$input_property} = $input_property_value;
        $childdata->plugin_config_entity_id = $postprocessor_config_entity->id();
        $input_argument_value = isset($io->output->plugin) && isset($io->output->plugin[$input_argument]) ?
          $io->output->plugin[$input_argument] : $data->{$input_argument};
        // This is a must: Solr indexing requires a list of sequences. A single one
        // will not be enqueued.
        if (is_array($input_argument_value)) {
          foreach ($input_argument_value as $value) {
            // Here is the catch.
            // Output properties may be many
            // Input Properties matching always need to be one
            if (!is_array($value)) {
              $childdata->{$input_argument} = $value;
              // The count will always be relative to this call
              // Means count of how many children are being called.
              $childdata->siblings = count($input_argument_value);
              // In case the $input_property_value is an array coming from a plugin we may want to if has the same amount of values of $input_argument_value
              // If so its many to one and we only need the corresponding entry to this sequence
              if ($input_property_value_from_plugin &&
                is_array($input_property_value) &&
                count($input_property_value) == $childdata->siblings &&
                isset($input_property_value[$value])) {
                $childdata->{$input_property} = $input_property_value[$value];
              }
              Drupal::queue('strawberryrunners_process_background', TRUE)
                ->createItem($childdata);
            }
          }
        }
      }
    }
  }

  /**
   * Get the extractor plugin.
   *
   * @param $plugin_config_entity_id
   *
   * @return StrawberryRunnersPostProcessorPluginInterface|NULL
   *   The plugin.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getProcessorPlugin($plugin_config_entity_id) {
    // Get extractor configuration.
    /* @var $plugin_config_entity \Drupal\strawberry_runners\Entity\strawberryRunnerPostprocessorEntityInterface */
    $plugin_config_entity = $this->entityTypeManager
      ->getStorage('strawberry_runners_postprocessor')
      ->load($plugin_config_entity_id);

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
   * Move file to local to if needed process.
   *
   * @param \Drupal\file\FileInterface $file
   *   The File URI to look at.
   *
   * @return string|FALSE
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
      try {
        $templocation = $this->fileSystem->copy(
          $uri,
          'temporary://sbr_' . $cache_key . '_' . basename($uri),
          FileSystemInterface::EXISTS_REPLACE
        );
        $templocation = $this->fileSystem->realpath(
          $templocation
        );
      } catch (FileException $exception) {
        // Means the file is not longer there
        // This happens if a file was added and shortly after that removed and replace
        // by a new one.
        $templocation = FALSE;
      }
    }

    if (!$templocation) {
      $this->logger->warning(
        'Could not adquire a local accessible location for text extraction for file with URL @fileurl. File may no longer exist.',
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
   * Checks Search API indexes for an Document ID and Checksum Match
   *
   * @param string $key
   * @param string $checksum
   * @param array $indexes
   *
   * @return int
   *  The number of Solr Documents found.
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
      $parse_mode = $this->parseModeManager->createInstance('terms');
      $query->setParseMode($parse_mode);
      $query->sort('search_api_relevance', 'DESC');
      $query->setOption('search_api_retrieved_field_values', ['id']);
      // Query breaks if not because standard hl is enabled for all fields.
      // and normal hl offsets on OCR HL specific ones.
      $query->setOption('no_highlight', 'on');
      $query->addCondition('search_api_id', 'strawberryfield_flavor_datasource/' . $key)
        ->addCondition('search_api_datasource', 'strawberryfield_flavor_datasource')
        ->addCondition('checksum', $checksum);
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
    return ($count == count($indexes)) ? 1 : 0;
    // Keys we need in the Search API
    // - ss_search_api_id == $key
    // A checksum field == Should be configurable?
    // Let's start by naming it checksum? If not present we may trigger some Logger/alert?
    // Or maybe we can use D8/D9 Status mechanic to let the user know this module
    // needs it in the data flavor.
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

    // CHECK IF $input_argument even exists!


    $io = new stdClass();
    $input = new stdClass();
    if (isset($input_property)) {
      $input->{$input_property} = $data->{$input_property};
    }

    if (isset($input_argument)) {
      $input->{$input_argument} = $data->{$input_argument} ?? 1;
    }

    // The Node UUID
    $input->nuuid = $data->nuuid;
    // All the rest of the associated Metadata in an as:structure
    $input->metadata = $data->metadata;
    $input->field_name = $data->field_name;
    $input->field_delta = $data->field_delta;
    $input->lang = $data->lang ?? NULL;
    $io->input = $input;
    $io->output = NULL;
    //@TODO implement the TEST and BENCHMARK logic here
    // RUN should return exit codes so we can know if something failed
    // And totally discard indexing.
    try {
      $extracted_data = $processor_instance->run($io, StrawberryRunnersPostProcessorPluginInterface::PROCESS);
    }
    catch (\Exception $exception) {
      $this->logger->error('@plugin threw an exception while trying to call ::run for Node UUID @nodeuuid with message: @msg', [
          '@msg' => $exception->getMessage(),
          '@plugin' => $processor_instance->getPluginId(),
          '@nodeuuid' => $input->nuuid,
        ]
      );
      throw new RequeueException('I am not done yet!');
    }
    return $io;
  }

  /**
   *  Updates a node with data passed from a processors io and original data
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   * @param \stdClass $data
   * @param \stdClass $io
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function updateNodeWithFile(ContentEntityInterface $entity, stdClass $data, stdClass $io) {

    // If the file went missing there is nothing we can do.
    if (!file_exists($io->output->file)) {
      $message_params = [
        '@file_id' => $data->fid,
        '@entity_id' => $data->nid,
        '@newfile_path' => $io->output->file,
      ];
      $this->logger->log(
        LogLevel::ERROR,
        'Strawberry Runners Processing failed to update Node because expected @newfile_path was not found! message: @message File id @file_id at Node @entity_id.',
        $message_params
      );
      return;
    }
    /** @var $itemfield \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem */

    $itemfield = $entity->get($data->field_name)->get($data->field_delta);
    $field_content = $itemfield->provideDecoded(TRUE);
    if (!isset($field_content['ap:entitymapping']['entity:file']) ||
      !in_array('flv:' . $data->plugin_config_entity_id, $field_content['ap:entitymapping']['entity:file'])) {
      $field_content['ap:entitymapping']['entity:file'][] = 'flv:' . $data->plugin_config_entity_id;
    }

    /** @var $newfile \Drupal\file\FileInterface */
    $newfile = $this->entityTypeManager->getStorage('file')->create([
      'uri' => $io->output->file,
      'status' => 0,
    ]);
    $uniqueid = $data->asstructure_uniqueid;
    $jsonkey = $data->asstructure_key;

    // check 'flv:' . $data->plugin_config_entity_id for empty
    // If there means this was enqueued many times and we do not need to add it again
    // We can not stop the actual runner to execute but we can at least avoid
    // creating multiple temporal anomalies
    // The same processor will not create more than a single file per source.
    if (empty($field_content[$jsonkey][$uniqueid]['flv:' . $data->plugin_config_entity_id])) {
      try {
        // Give it a simple but nice name.
        $uuid = !empty($field_content[$jsonkey][$uniqueid]['dr:uuid']) ? $field_content[$jsonkey][$uniqueid]['dr:uuid'] : str_replace("urn:uuid:", "", $uniqueid);
        $newfile->setFileName($this->setNiceName($newfile->getFileUri(), $data->plugin_config_entity_id, $uuid));
        $newfile->save();
        $newfile->id();
        $field_content['flv:' . $data->plugin_config_entity_id][]
          = (int) $newfile->id();
        $field_content['flv:' . $data->plugin_config_entity_id] = array_unique(
          $field_content['flv:' . $data->plugin_config_entity_id]
        );
        $field_content[$jsonkey][$uniqueid]['flv:' . $data->plugin_config_entity_id] = $this->addActivityStream($data->plugin_config_entity_id);
        $itemfield->setMainValueFromArray($field_content);
        // Should we check decide on this? Safer is a new revision, but also an overhead
        // $entity->setNewRevision(FALSE);
        $entity->save();
      }
      catch (Exception $exception) {
        $message_params = [
          '@file_id' => $data->fid,
          '@entity_id' => $data->nid,
          '@newfile_path' => $io->output->file,
          '@message' => $exception->getMessage(),
        ];
        $this->logger->log(
          LogLevel::ERROR,
          'Strawberry Runners Processing failed to update Node and add @newfile_path, message: @message for File ID @file_id at Node ID @entity_id.',
          $message_params
        );
      }
    }
    else {
      $message_params = [
        '@file_id' => $data->fid,
        '@entity_id' => $data->nid,
        '@newfile_path' => $io->output->file,
      ];
      $this->logger->log(
        LogLevel::INFO,
        'Strawberry Runners Processing decided to not update Node and add @newfile_path because the source was marked already as processed. message: for File ID @file_id at Node  ID@entity_id. No action is required',
        $message_params
      );
      unlink($io->output->file);
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

  /**
   * Gets all Children of the currently being processed Processor Plugin
   *
   * @param string $plugin_config_entity_id
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
   * Helper method to get the real path from an uri.
   *
   * @param string $uri
   *   The URI of the file, e.g. public://directory/file.jpg.
   *
   * @return mixed
   *   The real path to the file if it is a local file. An URL otherwise.
   */
  public function getRealpath(string $uri) {
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
   * @param $current_uri
   * @param $pluginid
   * @param $uuid
   *
   * @return string
   */
  private function setNiceName($current_uri, $pluginid, $uuid) {
    $file_parts['destination_filename'] = pathinfo(
      $current_uri,
      PATHINFO_FILENAME
    );

    $file_parts['destination_extension'] = pathinfo(
      $current_uri,
      PATHINFO_EXTENSION
    );
    // Check if the file may have a secondary extension

    $file_parts['destination_extension_secondary'] = pathinfo(
      $file_parts['destination_filename'],
      PATHINFO_EXTENSION
    );
    // Deal with 2 part extension problem.
    if (!empty($file_parts['destination_extension_secondary']) &&
      strlen($file_parts['destination_extension_secondary']) <= 4 &&
      strlen($file_parts['destination_extension_secondary']) > 0
    ) {
      $file_parts['destination_extension'] = $file_parts['destination_extension_secondary'] . '.' . $file_parts['destination_extension'];
    }
    $destination_extension = mb_strtolower(
      $file_parts['destination_extension']
    );

    return $pluginid . '_from_' . $uuid . '.' . $destination_extension;
  }


}
