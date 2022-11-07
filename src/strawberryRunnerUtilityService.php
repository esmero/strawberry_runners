<?php

namespace Drupal\strawberry_runners;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginManager;

class strawberryRunnerUtilityService implements strawberryRunnerUtilityServiceInterface {

  /**
   * The entity storage class.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $storage;

  /**
   * The queue service.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The entity storage class.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface;
   */
  protected $entityTypeManager;

  /**
   * The Config Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface;
   */
  protected $configFactory;

  /**
   * The Stream Wrapper Manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * File system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The StrawberryRunner Processor Plugin Manager.
   *
   * @var \Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginManager
   */
  protected $strawberryRunnerProcessorPluginManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * StrawberryRunnersEventInsertPostProcessingSubscriber constructor.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginManager $strawberry_runner_processor_plugin_manager
   * @param \Drupal\Core\Session\AccountInterface $account
   */
  public function __construct(
    TranslationInterface $string_translation,
    QueueFactory $queue_factory,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    StreamWrapperManagerInterface $stream_wrapper_manager,
    FileSystemInterface $file_system,
    EntityTypeManagerInterface $entity_type_manager,
    StrawberryRunnersPostProcessorPluginManager $strawberry_runner_processor_plugin_manager,
    AccountInterface $account
  ) {
    $this->stringTranslation = $string_translation;
    $this->queueFactory = $queue_factory;
    $this->messenger = $messenger;
    $this->loggerFactory = $logger_factory;
    $this->configFactory = $config_factory;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->fileSystem = $file_system;
    $this->entityTypeManager = $entity_type_manager;
    $this->strawberryRunnerProcessorPluginManager = $strawberry_runner_processor_plugin_manager;
    $this->account = $account;
  }

  /**
   * Fetches matching processors for a given ADO and enqueues them.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   * @param array                                      $sbf_fields
   *
   * @param bool                                       $force
   *      If TRUE Overrides any $force argument passed via metadata
   *
   * @param array                                      $filter
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function invokeProcessorForAdo(ContentEntityInterface $entity, array $sbf_fields, bool $force = FALSE, array $filter = []): void {

    $active_plugins = $this->getActivePluginConfigs();
    // First pass: for files, all the as:structures we want for, keyed by content type
    /* check your config
       "source_type" => "asstructure"
       "ado_type" => "Document"
       "jsonkey" => array:6 [▼
         "as:document" => "as:document"
         "as:image" => 0
         "as:audio" => 0
         "as:video" => 0
         "as:text" => 0
         "as:application" => 0
      ]
       "mime_type" => "application/pdf"
       "path" => "/usr/bin/pdftotext"
       "arguments" => "%file"
       "output_type" => "json"
       "output_destination" => array:3 [▼
          "plugin" => "plugin"
          "subkey" => 0
          "ownkey" => 0
      ]
      "timeout" => "10"
      "weight" => "0"
     "configEntity" => "test"
   ]*/
    $askeymap = [];
    if (isset($active_plugins['entity:file'])) {
      foreach ($active_plugins['entity:file'] as $activePluginId => $config) {
        // Only add to $askeymap if $filter is empty or $activePluginId is in the $filter.
        if (empty($filter) || in_array($activePluginId, $filter)) {
          if ($config['source_type'] == 'asstructure') {
            $askeys = array_filter($config['jsonkey']);
            foreach ($askeys as $key => $value) {
              $askeymap[$key][$activePluginId] = $config;
            }
          }
        }
      }
    }

    foreach ($sbf_fields as $field_name) {
      /* @var $field \Drupal\Core\Field\FieldItemInterface */
      $field = $entity->get($field_name);
      if (!$field->isEmpty()) {
        $entity = $field->getEntity();
        /** @var $field \Drupal\Core\Field\FieldItemList */
        foreach ($field->getIterator() as $delta => $itemfield) {
          // Note: we are not touching the metadata here.
          /** @var $itemfield \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem */
          $flatvalues = (array) $itemfield->provideFlatten();
          // Run first on entity:files
          $sbf_type = [];
          if (isset($flatvalues['type'])) {
            $sbf_type = (array) $flatvalues['type'];
          }
          foreach ($askeymap as $jsonkey => $activePlugins) {
            if (isset($flatvalues[$jsonkey])) {
              foreach ($flatvalues[$jsonkey] as $uniqueid => $asstructure) {
                if (isset($asstructure['dr:fid'])
                  && is_numeric($asstructure['dr:fid'])
                ) {
                  foreach ($activePlugins as $activePluginId => $config) {
                    // Checks if the flag is set and is an array.
                    $nopost = (isset($flatvalues["ap:tasks"]["ap:nopost"]) &&
                      is_array($flatvalues["ap:tasks"]["ap:nopost"]));

                    if ($nopost) {
                      if (in_array($activePluginId, $flatvalues["ap:tasks"]["ap:nopost"])) {
                        // if we have an entry like ["ap:tasks"]["ap:nopost"][0] == "pager" we don't run pager
                        // for this ADO. We won't delete existing ones. Just never process.
                        continue;
                      }
                    }

                    // Never ever run a processor over its own creation
                    if ($asstructure["dr:for"] == 'flv:' . $activePluginId) {
                      continue;
                    }

                    //@TODO also split $config['ado_type'] so we can check
                    $valid_ado_type = explode(',', $config['ado_type']);
                    $valid_ado_type = array_map('trim', $valid_ado_type);
                    if (empty($config['ado_type'])
                      || count(
                        array_intersect($valid_ado_type, $sbf_type)
                      ) > 0
                    ) {
                      $valid_mimes = explode(',', $config['mime_type']);
                      $valid_mimes = array_filter(
                        array_map('trim', $valid_mimes)
                      );
                      if (empty($asstructure['flv:' . $activePluginId])
                        && (empty($valid_mimes)
                          || (isset($asstructure["dr:mimetype"])
                            && in_array(
                              $asstructure["dr:mimetype"], $valid_mimes
                            )))
                      ) {
                        $data = new \stdClass();
                        $data->fid = $asstructure['dr:fid'];
                        $data->nid = $entity->id();
                        $data->asstructure_uniqueid = $uniqueid;
                        $data->asstructure_key = $jsonkey;
                        $data->nuuid = $entity->uuid();
                        $data->field_name = $field_name;
                        $data->field_delta = $delta;
                        // Get the configured Language from descriptive metadata
                        if (isset($config['language_key'])
                          && !empty($config['language_key'])
                          && isset($flatvalues[$config['language_key']])
                        ) {
                          $data->lang = is_array(
                            $flatvalues[$config['language_key']]
                          ) ? array_values($flatvalues[$config['language_key']])
                            : [$flatvalues[$config['language_key']]];
                        }
                        else {
                          $data->lang = $config['language_default'] ?? NULL;
                        }
                        // Check if there is a key that forces processing.
                        $force_from_metadata_or_arg
                          = isset($flatvalues["ap:tasks"]["ap:forcepost"])
                          ? (bool) $flatvalues["ap:tasks"]["ap:forcepost"]
                          : $force;

                        // We are passing also the full file metadata.
                        // This gives us an advantage so we can reuse
                        // Sequence IDs, PDF pages, etc and act on them
                        // @TODO. We may want to have also Kill switches in the
                        // main metadata to act on this
                        // E.g flv:processor[$activePluginId] = FALSE?
                        // Also. Do we want to act on metadata and mark
                        // Files as already send for processing by a certain
                        // $activePluginId? That would allow us to skip reprocessing
                        // Easier?
                        $data->metadata = $asstructure;

                        // @TODO how to force?
                        // Can be a JSON passed property or an argument.
                        // Issue with JSON passed property is that we can no longer

                        $data->force = $force_from_metadata_or_arg;
                        $data->plugin_config_entity_id = $activePluginId;
                        // See https://github.com/esmero/strawberry_runners/issues/10
                        // Since the destination Queue can be a modal thing
                        // And really what defines is the type of worker we want
                        // But all at the end will eventually feed the ::run() method
                        // We want to make this a full blown service.
                        $this->queueFactory->get(
                          'strawberryrunners_process_index', TRUE
                        )->createItem($data);
                      }
                    }
                  }
                }
              }
            }
          }
        }
      }
    }
  }

  /**
   * Gets all Currently Active PLugin Entities and Configs initialized
   *
   *
   * @return array
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function getActivePluginConfigs():array {
    $active_plugins = [];
    /* @var $plugin_config_entities \Drupal\strawberry_runners\Entity\strawberryRunnerPostprocessorEntity[] */
    $plugin_config_entities = $this->entityTypeManager->getListBuilder(
      'strawberry_runners_postprocessor'
    )->load();

    foreach ($plugin_config_entities as $plugin_config_entity) {
      // Only get first level (no Parents) and Active ones.
      if ($plugin_config_entity->isActive()
        && $plugin_config_entity->getParent() == ''
      ) {
        $entity_id = $plugin_config_entity->id();
        $configuration_options = $plugin_config_entity->getPluginconfig();
        $configuration_options['configEntity'] = $entity_id;
        /* @var \Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface $plugin_instance */
        $plugin_instance
          = $this->strawberryRunnerProcessorPluginManager->createInstance(
          $plugin_config_entity->getPluginid(),
          $configuration_options
        );
        $plugin_definition = $plugin_instance->getPluginDefinition();
        // We don't use the key here to preserve the original weight given order
        // Classify by input type

        $active_plugins[$plugin_definition['input_type']][$entity_id]
          = $plugin_instance->getConfiguration();
      }
    }
    return $active_plugins;
  }
}
