<?php

namespace Drupal\strawberry_runners\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Component\Utility\Unicode;
use Drupal\file\FileInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginManager;
use Drupal\strawberryfield\EventSubscriber\StrawberryfieldEventSaveSubscriber;

/**
 * Event subscriber for SBF bearing entity json process event.
 */
class StrawberryRunnersEventSavePostProcessingSubscriber extends StrawberryfieldEventSaveSubscriber {


  use StringTranslationTrait;

  /**
   *
   * Run as late as possible.
   *
   * @var int
   */
  protected static $priority = -2000;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The entity storage class.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $storage;


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
   * An array containing local copies of stored files.
   *
   * @var array
   */
  protected $instanceCopiesOfFiles = [];

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
   * StrawberryRunnersEventSavePostProcessingSubscriber constructor.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
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
   *  Method called when Event occurs.
   *
   * @param \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent $event
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function onEntitySave(StrawberryfieldCrudEvent $event) {

    /* @var $plugin_config_entities \Drupal\strawberry_runners\Entity\strawberryRunnerPostprocessorEntity[] */
    $plugin_config_entities = $this->entityTypeManager->getListBuilder('strawberry_runners_postprocessor')
      ->load();
    $active_plugins = [];
    foreach ($plugin_config_entities as $plugin_config_entity) {
      // Only get first level (no Parents) and Active ones.
      if ($plugin_config_entity->isActive() && $plugin_config_entity->getParent() == '') {
        $entity_id = $plugin_config_entity->id();
        $configuration_options = $plugin_config_entity->getPluginconfig();
        $configuration_options['configEntity'] = $entity_id;
        /* @var \Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface $plugin_instance */
        $plugin_instance = $this->strawberryRunnerProcessorPluginManager->createInstance(
          $plugin_config_entity->getPluginid(),
          $configuration_options
        );
        $plugin_definition = $plugin_instance->getPluginDefinition();
        // We don't use the key here to preserve the original weight given order
        // Classify by input type

        $active_plugins[$plugin_definition['input_type']][$entity_id] = $plugin_instance->getConfiguration();
      }
    }

    // We will fetch all files and then see if each file can be processed by one
    // or more plugin.
    // Slower option would be to traverse every file per processor.

    $entity = $event->getEntity();
    $sbf_fields = $event->getFields();

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

    if (isset($active_plugins['entity:file'])) {
      foreach ($active_plugins['entity:file'] as $activePluginId => $config) {
        if ($config['source_type'] == 'asstructure') {
          $askeys = array_filter($config['jsonkey']);
          foreach ($askeys as $key => $value) {
            $askeymap[$key][$activePluginId] = $config;
          }
        }
      }
    }

    foreach ($sbf_fields as $field_name) {
      /* @var $field \Drupal\Core\Field\FieldItemInterface */
      $field = $entity->get($field_name);
      if (!$field->isEmpty()) {
        $entity = $field->getEntity();
        $entity_type_id = $entity->getEntityTypeId();
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
                if (isset($asstructure['dr:fid']) && is_numeric($asstructure['dr:fid'])) {
                  foreach ($activePlugins as $activePluginId => $config) {
                    // Never ever run a processor over its own creation
                    if ($asstructure["dr:for"] == 'flv:' . $activePluginId) {
                      continue;
                    }

                    $valid_mimes = [];
                    //@TODO also split $config['ado_type'] so we can check
                    $valid_ado_type = [];
                    $valid_ado_type = explode(',', $config['ado_type']);
                    $valid_ado_type = array_map('trim', $valid_ado_type);
                    if (empty($config['ado_type']) || count(array_intersect($valid_ado_type, $sbf_type)) > 0) {
                      $valid_mimes = explode(',', $config['mime_type']);
                      $valid_mimes = array_filter(array_map('trim', $valid_mimes));
                      if (empty($asstructure['flv:' . $activePluginId]) &&
                        (empty($valid_mimes) || (isset($asstructure["dr:mimetype"]) && in_array($asstructure["dr:mimetype"], $valid_mimes)))
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
                        if (isset($config['language_key']) && !empty($config['language_key']) && isset($flatvalues[$config['language_key']])) {
                          $data->lang = is_array($flatvalues[$config['language_key']]) ? array_values($flatvalues[$config['language_key']]) : [$flatvalues[$config['language_key']]];
                        }
                        else {
                          $data->lang = $config['language_default'] ?? NULL;
                        }
                        // Check if there is a key that forces processing.
                        $force = isset($flatvalues["ap:tasks"]["ap:forcepost"]) ? (bool) $flatvalues["ap:tasks"]["ap:forcepost"] : FALSE;

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
                        // Can be a state key, valuekey, or a JSON passed property.
                        // Issue with JSON passed property is that we can no longer
                        // Here modify it (Entity is saved)
                        // So we should really better have a non Metadata method for this
                        // Or/ we can have a preSave Subscriber that reads the prop,
                        // sets the state and then removes if before saving

                        $data->force = $force;
                        $data->plugin_config_entity_id = $activePluginId;
                        // See https://github.com/esmero/strawberry_runners/issues/10
                        // Since the destination Queue can be a modal thing
                        // And really what defines is the type of worker we want
                        // But all at the end will eventually feed the ::run() method
                        // We want to make this a full blown service.
                        \Drupal::queue('strawberryrunners_process_index', TRUE)
                          ->createItem($data);
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
    $current_class = get_called_class();
    $event->setProcessedBy($current_class, TRUE);
    if ($this->account->hasPermission('display strawberry messages')) {
      $this->messenger->addStatus($this->t('Post processor was invoked'));
    }
  }

}
