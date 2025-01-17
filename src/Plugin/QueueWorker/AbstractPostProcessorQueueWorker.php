<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/4/19
 * Time: 4:19 PM
 */

namespace Drupal\strawberry_runners\Plugin\QueueWorker;

use Drupal;
use Drupal\Core\Datetime\DrupalDateTime;
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
use Drupal\strawberryfield\Event\StrawberryfieldFileEvent;
use Drupal\strawberryfield\Semantic\ActivityStream;
use Drupal\Core\File\Exception\FileException;
use Drupal\strawberryfield\StrawberryfieldEventType;
use Exception;
use Drupal\Core\Queue\RequeueException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginManager;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\search_api\ParseMode\ParseModePluginManager;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

abstract class AbstractPostProcessorQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  const QUEUES = [
    'background' => 'strawberryrunners_process_background',
    'realtime' => 'strawberryrunners_process_index'
  ];
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
   * An array containing files that can be deleted.
   *
   * @var array
   */
  protected $instanceFiles = [];

  /**
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

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
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, StrawberryRunnersPostProcessorPluginManager $strawberry_runner_processor_plugin_manager, FileSystemInterface $file_system, StreamWrapperManagerInterface $stream_wrapper_manager, KeyValueFactoryInterface $key_value, LoggerInterface $logger, ParseModePluginManager $parse_mode_manager, EventDispatcherInterface $event_dispatcher) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->strawberryRunnerProcessorPluginManager = $strawberry_runner_processor_plugin_manager;
    $this->fileSystem = $file_system;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->keyValue = $key_value;
    $this->logger = $logger;
    $this->parseModeManager = $parse_mode_manager;
    $this->eventDispatcher = $event_dispatcher;
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
      $container->get('plugin.manager.search_api.parse_mode'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {

    // If $data->filepath_to_clean is present and is an array (can be a single entry) and
    // $data->sbr_cleanup == TRUE
    // Then this is will not do its processor invoking, this is a cleanup local files
    // We send the composter even invocation and return;
    if (is_array($data->filepath_to_clean ?? NULL) && ($data->sbr_cleanup ?? FALSE)) {
      $this->dispatchComposter($data);
      return;
    }


    $processor_instance = $this->getProcessorPlugin($data->plugin_config_entity_id);

    if (!$processor_instance) {
      $this->logger->log(LogLevel::ERROR, 'Strawberry Runners Processing aborted because the @processor may be inactive', ['@processor' => $processor_instance->label()]);
      return;
    }
    $processor_config = $processor_instance->getConfiguration();

    // @TODO check on this Diego. This is a bit misleading since it assumes
    // every processor will work only on Files.
    // True for now, but eventually we want processors that do only
    // metadata to metadata.

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
      $this->logger->log(LogLevel::ERROR, 'Sorry the ADO Node ID @nodeid passed to the Strawberry Runners processor does not (longer?) exists. Skipping.', [
        '@nodeid' => $data->nid,
      ]);
      return;
    }

    // We only need to ensure $file if we are going to use the actual file for processing.
    if ($processor_instance->getPluginDefinition()['input_property'] == 'filepath') {
      $filelocation = $this->ensureFileAvailability($file);
      if ($filelocation === FALSE) {
        $this->logger->log(LogLevel::ERROR, 'Strawberry Runners Processing aborted for ADO Node ID @nodeid because we could not ensure a local file location needed for @processor. You might have run out space or have permission issues or (less likely) the original File/ADO was removed milliseconds ago.',
          [
            '@processor' => $processor_instance->label(),
            '@nodeid' => $data->nid,
          ]
        );
        // Note. If $filelocation could not be acquired, means we do not need to compost neither
        // its already gone/not possible
        return;
      }
      // Means we could pass also a file directly anytime. But not really as such
      // only into $data->filepath but not into $filelocation bc
      // that would compost and remove the file. What if its needed later?
      $data->filepath = $filelocation;
      // We preset it up here.
      $this->instanceFiles = [$filelocation];
    }
    else {
      $data->filepath = NULL;
    }

    if (!isset($processor_config['output_destination']) || !is_array($processor_config['output_destination'])) {
      $this->logger->log(LogLevel::ERROR, 'Strawberry Runners Processing aborted for ADO Node ID @nodeid because there is no output destination setup for @processor',
        [
          '@processor' => $processor_instance->label(),
          '@nodeid' => $data->nid,
        ]
      );
      return;
    }

    // Get the whole processing chain
    $childprocessorschain = $this->getChildProcessorIds($data->plugin_config_entity_id ?? '', true);

    // If a child processor at any level will eventually chain up to a leaf (means generate queue items again)
    $will_chain_future = FALSE;
    // Just in case someone decides to avoid setting this one up
    $data->sbr_cleanedup_before = $data->sbr_cleanedup_before ?? FALSE;

    if (!$data->sbr_cleanedup_before) {
      foreach ($childprocessorschain as $plugin_info) {
        /* @var  $postprocessor_config_entity_chain \Drupal\strawberry_runners\Entity\strawberryRunnerPostprocessorEntity */
        $postprocessor_config_entity_chain = $plugin_info['config_entity'];
        $chains = $postprocessor_config_entity_chain->getPluginconfig(
          )['output_destination']['plugin'] ?? FALSE;
        $chains = $chains === 'plugin' ? TRUE : FALSE;
        $will_chain_future = $will_chain_future || $chains;
      }
    }

    $queue_name = $processor_config['processor_queue_type'] ?? 'realtime';
    $queue_name = AbstractPostProcessorQueueWorker::QUEUES[$queue_name] ?? AbstractPostProcessorQueueWorker::QUEUES['realtime'];

    // When to clean up?
    // If not cleaned up before
    // AND won't chain in the future

    $needs_localfile_cleanup = !$will_chain_future && !$data->sbr_cleanedup_before && $processor_instance->getPluginDefinition()['input_property'] == 'filepath';
    // We set this before triggering cleanup, means future thinking
    // bc we need to make sure IF there is a next processor it will get
    // The info that during this queuworker processing cleanup at the end
    // Will happen at the end.
    $data->sbr_cleanedup_before = $data->sbr_cleanedup_before == TRUE ? $data->sbr_cleanedup_before : $needs_localfile_cleanup;

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
        // New ot 0.9.0. Since ML chained might have as input argument 'annotation'
        // But still need a sequence_number, we will check if data contains either one
        // or a FIXED ->sequence_number
        if ($input_argument && $input_argument == "sequence_number") {
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
        else {
          // See fixed. ML processors will always set this in their output.
          $sequence_key = $data->sequence_number ?? 1;
        }
        // Here goes the main trick for making sure out $sequence_key in the Solr ID
        // Is the right now (relative to its own, 1 if single file, or an increasing number if a pdf
        // We check if the current item has siblings!
        if (!isset($data->siblings) || isset($data->siblings) && $data->siblings == 1) {
          $sequence_key = 1;
        }
        // Now the strange case of a PDF, page 2, with annotations.
        if (isset($data->internal_sequence_id) && is_numeric($data->internal_sequence_id) && $data->internal_sequence_id !=1 ) {
          $sequence_key = $sequence_key . '-' . $data->internal_sequence_id;
          // So Second Page of a PDF, first ML annotation will be 1 : 2-1
        }

        if (is_a($entity, TranslatableInterface::class)) {
          $translations = $entity->getTranslationLanguages();
          foreach ($translations as $translation_id => $translation) {
            // checksum and file->uuid apply even if the source is not a local-ized/ensure local file.
            // But we might want to review this if we plan on indexing JSON RAW/metadata directly as an vector embedding.
            $item_id = $entity->id() . ':' . $sequence_key . ':' . $translation_id . ':' . $file->uuid() . ':' . $data->plugin_config_entity_id;
            // a single 0 as return will force us to reindex.
            $inindex = $inindex * $this->flavorInSolrIndex($item_id, $data->metadata['checksum'], $indexes);
            $item_ids[] = $item_id;
          }
        }

        // Check if we already have this entry in Solr
        if ($inindex !== 0 && !$data->force) {
          $this->logger->log(LogLevel::INFO, 'Flavor already in index for @plugin on ADO Node ID @nodeid, not forced, so skipping or chaining.',
            [
              '@plugin' => $processor_instance->getPluginId(),
              '@nodeid' => $data->nid,
            ]
          );
        }
        $inkeystore = TRUE;

        // For now keeping a single language. Processor might not be aware of other languages for chaining indexed?
        // Reason is even if we iterate over each language, $toindex == 1. Always the same.
        // @TODO May 2024. Re-Review this in Flavor Data Source provider. We could save ourself a lot of KeyStore element.s
        $processed_data_for_chaining = NULL;

        // Skip file if element for every language is found in key_value collection.
        foreach ($item_ids as $item_id) {
          $processed_data = $this->keyValue->get($keyvalue_collection)
            ->get($item_id);
          if (empty($processed_data) || !isset($processed_data->checksum) ||
            empty($processed_data->checksum) ||
            $processed_data->checksum != $data->metadata['checksum']) {
            $inkeystore = $inkeystore && FALSE;
          }
          else {
            // I am keeping a single one here. Should we discern by language for chaining?
            // @TODO analize what it means for us.
            $processed_data_for_chaining = $processed_data;
          }
        }
        // May 2024. Allow a Processor that is to be indexed, already was processed and has data in the key store
        // To use that data as input for a child one, if chained too. But only if nothing has set $io->output->plugin before
        // This is needed for Processors (e.g OCR) that have already processed everything and then get a new chained
        // Child that was never processed before. Would be terrible to have to re-process OCR completely just to get
        // A Child to trigger. We will only provide $io->input->plugin['searchapi'] bc that is what we know
        // Any other type of child won't be able to feed from pre-existing.
        if ($inkeystore && $tobechained && !$data->force && $processed_data_for_chaining!=NULL && (!isset($io->output->plugin) || !empty($io->output->plugin))) {
          // Since we don't know at all what $io->output->plugin should contain
          // We will pass the keystore value into $io->output->plugin and let the Processor itself (needs to have that logic)
          // Deal with this use case.
          $this->logger->log(LogLevel::INFO, 'Chaining @plugin on ADO Node ID @nodeid with preexisting data to the next one.',
            [
              '@plugin' => $processor_instance->getPluginId(),
              '@nodeid' => $data->nid,
            ]
          );
          if (!isset($io)) {
            $io=  new \stdClass();
            $io->output = new \stdClass();
            $io->output->plugin = [];
          }
          $io->output->plugin['searchapi'] = $processed_data_for_chaining;
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
          $toindex->config_processor_id = $data->plugin_config_entity_id ?? '';
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
          // ML ones.
          $toindex->vector_384 = $io->output->searchapi['vector_384'] ?? NULL;
          $toindex->vector_512 = $io->output->searchapi['vector_512'] ?? NULL;
          $toindex->vector_576 = $io->output->searchapi['vector_576'] ?? NULL;
          $toindex->vector_1024 = $io->output->searchapi['vector_1024'] ?? NULL;
          $toindex->vector_768 = $io->output->searchapi['vector_768'] ?? NULL;
          $toindex->service_md5 = $io->output->searchapi['service_md5'] ?? '';

          // $siblings will be the amount of total children processors that were
          // enqueued for a single Processor chain.
          $toindex->sequence_total = !empty($data->siblings) ? $data->siblings : 1;
          // Be implicit about this one. No longer depend on the Solr DOC ID splitting.

          $toindex->sequence_id = $data->sequence_number ?? 1;
          $toindex->internal_sequence_id = $data->internal_sequence_id ?? $toindex->sequence_id ;
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
          // Re-enqueue in the same Queue it came from. Not so great to have round robin
          Drupal::queue($queue_name, TRUE)
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
      foreach ($childprocessorschain as $plugin_info) {
        if ($plugin_info['parent_plugin_id'] !== $data->plugin_config_entity_id) {
          // Means its another processor up the tree
          continue ;
        }
        $childdata = clone $data; // So we do not touch original data
        //@TODO. What if we want to force a child object only?
        // We could IF the Child Object depends only on searchapi.
        // Requires a Change in our SBR Trigger VBO plugin
        // @TODO ask Allison. We might need a VBO processor to delete, selectively Flavors from Key/Solr too.
        // Only way of A) removing Bias/bad vectors/Even bad OCR> And the processor should be also be able to mark
        // ap:task no ML etc
        /* if ($plugin_info['plugin_definition']['id'] ?? NULL == 'ml_sentence_transformer') {
          $childdata->force = TRUE;
        }*/
        /* @var  $strawberry_runners_postprocessor_config \Drupal\strawberry_runners\Entity\strawberryRunnerPostprocessorEntity */
        $postprocessor_config_entity = $plugin_info['config_entity'];
        $queue_name = $postprocessor_config_entity_chain->getPluginconfig()['processor_queue_type'] ?? 'realtime';
        $queue_name = AbstractPostProcessorQueueWorker::QUEUES[$queue_name] ?? AbstractPostProcessorQueueWorker::QUEUES['realtime'];
        $input_property = $plugin_info['plugin_definition']['input_property'] ?? NULL;
        $input_argument = $plugin_info['plugin_definition']['input_argument'] ?? NULL;
        //@TODO check if this are here and not null!
        // $io->output will contain whatever the output is
        // We will check if the child processor
        // contains a property contained in $output
        // If so we check if there is a single value or multiple ones
        // For each we enqueue a child using that property in its data
        // Possible input properties:
        // - Can come from the original Data (most likely)
        // - May be overridden by the $io->output, e.g when a processor generates a file that is not part of any node
        $input_property_value_from_plugin = TRUE;
        $input_property_value = $input_property && isset($io->output->plugin) && isset($io->output->plugin[$input_property]) ? $io->output->plugin[$input_property] : NULL;
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
        // Warning Diego. This may lead to a null?
        $childdata->{$input_property} = $input_property_value;
        $childdata->plugin_config_entity_id = $postprocessor_config_entity->id();
        $input_argument_value = $input_argument && isset($io->output->plugin) && isset($io->output->plugin[$input_argument]) ?
          $io->output->plugin[$input_argument] : ($input_argument && isset($data->{$input_argument}) ? $data->{$input_argument} : NULL);

        // May 2024, Most cases, like Pagers (PDF page extractors) $input_argument_value will be an array, a sequence
        // Leading to many children.
        // But for chained processors like ML ones, e.g each OCR will generate exactly ONE ML
        // using the same input property of OCR.
        // So we can no longer assume/not depend on $input_argument_value as we did until 0.7.0

        if ($input_argument_value) {
          if (is_array($input_argument_value)) {
            foreach ($input_argument_value as $input_argument_index => $value) {
              // Here is the catch.
              // Output properties may be many
              // Input Properties matching always need to be one
              if (!is_array($value)) {
                $childdata->{$input_argument} = $value;
                // The count will always be relative to this call
                // Means count of how many children are being called.
                $childdata->siblings = count($input_argument_value);
                // In case the $input_property_value is an array coming from a plugin we may want to know if it has the same amount of values of $input_argument_value
                // If so, it is many to one, and we only need the corresponding entry to this sequence
                if ($input_property_value_from_plugin &&
                  is_array($input_property_value) &&
                  count($input_property_value) == $childdata->siblings &&
                  isset($input_property_value[$value])) {
                  $childdata->{$input_property} = $input_property_value[$value];
                }

                $childdata->sequence_id =  $childdata->sequence_id ?? 1;
                // I know sequence_number and sequence_id are the same. But we have been using this silly mapping
                // for years.
                $childdata->sequence_number = $childdata->sequence_number ?? $childdata->sequence_id;

                $childdata->internal_sequence_id = $input_argument_index + 1;


                Drupal::queue($queue_name, TRUE)
                  ->createItem($childdata);
              }
            }
          } elseif (!empty($input_argument_value) && $input_property_value) {
            // WE Have a single one. E.g Generated by a Double chaining. For 0.8.0 we will accept this option
            $childdata->{$input_argument} = $input_argument_value;
            $childdata->{$input_property} = $input_property_value;
            $childdata->siblings = $childdata->siblings ?? 1;

            $childdata->sequence_id = $childdata->sequence_id ?? 1;
            $childdata->sequence_number = $childdata->sequence_number ?? $childdata->sequence_id;
            $childdata->internal_sequence_id = $childdata->internal_sequence_id ?? 1;

            Drupal::queue($queue_name, TRUE)
              ->createItem($childdata);
          }
        }
      }
    }
    // If we enqueued means we can not compost the original file.
    // Safest route to get rid of the ensured local file
    // Is to enqueue it on the same `strawberryrunners_process_background` queue
    // Sadly not on a processor that is a leaf but on the one that creates leaves! (enques)
    // or had will never enqueue at all. (yes, some just process).
    // Why? because for one that creates there might be hundreds of leaves and we don't want
    // to enqueue for cleanup hundred of times. Right?
    // There is a bit of statistics (when) here but that said
    // Since the file is re-checked of existence everytime a queue worker jumps
    // in, if lost, we will simply regenerate it.
    if ($needs_localfile_cleanup && $filelocation) {
      $data_cleanup = new \stdClass();
      $data_cleanup->filepath_to_clean = [$filelocation];
      $data_cleanup->sbr_cleanup = TRUE;
      Drupal::queue($queue_name, TRUE)
       ->createItem($data_cleanup);
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
      // But no post-processing here is also good, faster and we just want
      // to know if its there or not.
      $query->setProcessingLevel(QueryInterface::PROCESSING_NONE);
      $results = $query->execute();

      // $solr_response = $results->getExtraData('search_api_solr_response');
      // In case of more than one Index with the same Data Source we accumulate
      $count = $count + (int) $results->getResultCount();

    }
    // This is a good one. If I have multiple indexes, but one is missing the I assume
    // reprocessing is needed
    // But if not, then I return 1, which means we have them all
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

    $input_property = $processor_instance->getPluginDefinition()['input_property'] ?? NULL;
    $input_argument = $processor_instance->getPluginDefinition()['input_argument'] ?? NULL;

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
      throw new RequeueException('I am not done yet. Will re-enqueue myself');
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
    // If there means this was enqueued many times, and we do not need to add it again
    // We can not stop the actual runner to execute, but we can at least avoid
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
   * @param bool   $wholechain
   *     If we keep getting children up and accumulating the whole tree to a leaf
   * @return array
   */
  private function getChildProcessorIds(string $plugin_config_entity_id, $wholechain = FALSE): array {
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
        // We keep the parent here too since we might need to fetch (when $wholechain == TRUE)
        // Just the ones directly attached to the current one.
        $active_plugins[] = [
          'config_entity' => $plugin_config_entity,
          'plugin_definition' => $plugin_definitions[$plugin_config_entity->getPluginid()],
          'parent_plugin_id' => $plugin_config_entity_id,
        ];
        if ($wholechain) {
          $active_plugins = array_merge($active_plugins, $this->getChildProcessorIds($plugin_config_entity->id(), $wholechain));
        }
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
   *   The real path to the file if it is a local file. A URL otherwise.
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

  private function dispatchComposter(\StdClass $data):void {
    // Just in case bc the destructor will be invoked
    $this->instanceFiles = [];
    foreach($data->filepath_to_clean ?? [] as $instanceFile) {
      $event_type = StrawberryfieldEventType::TEMP_FILE_CREATION;
      $current_timestamp = (new DrupalDateTime())->getTimestamp();
      $event = new StrawberryfieldFileEvent($event_type, 'strawberry_runners', $instanceFile, $current_timestamp);
      // This will allow any temp file on ADO save to be managed
      // IN a queue by \Drupal\strawberryfield\EventSubscriber\StrawberryfieldEventCompostBinSubscriber
      $this->eventDispatcher->dispatch($event, $event_type);
    }
  }

  public function __destruct() {

    /* foreach($this->instanceFiles as $instanceFile) {
      $event_type = StrawberryfieldEventType::TEMP_FILE_CREATION;
      $current_timestamp = (new DrupalDateTime())->getTimestamp();
      $event = new StrawberryfieldFileEvent($event_type, 'strawberry_runners', $instanceFile, $current_timestamp);
      // This will allow any temp file on ADO save to be managed
      // IN a queue by \Drupal\strawberryfield\EventSubscriber\StrawberryfieldEventCompostBinSubscriber
      $this->eventDispatcher->dispatch($event, $event_type);
    }*/
  }

}
