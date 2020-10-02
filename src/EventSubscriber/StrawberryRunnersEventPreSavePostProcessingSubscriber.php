<?php

namespace Drupal\strawberry_runners\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Drupal\strawberryfield\EventSubscriber\StrawberryfieldEventPresaveSubscriber;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Component\Utility\Unicode;
use Drupal\file\FileInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginManager;

/**
 * Event subscriber for SBF bearing entity json process event.
 */
class StrawberryRunnersEventPreSavePostProcessingSubscriber extends StrawberryfieldEventPresaveSubscriber {


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
   * StrawberryRunnersEventPreSavePostProcessingSubscriber constructor.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   * @param \Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginManager $strawberry_runner_processor_plugin_manager
   */
  public function __construct(
    TranslationInterface $string_translation,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    StreamWrapperManagerInterface $stream_wrapper_manager,
    FileSystemInterface $file_system,
    EntityTypeManagerInterface $entity_type_manager,
    StrawberryRunnersPostProcessorPluginManager $strawberry_runner_processor_plugin_manager
  ) {
    $this->stringTranslation = $string_translation;
    $this->messenger = $messenger;
    $this->loggerFactory = $logger_factory;
    $this->configFactory = $config_factory;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->fileSystem = $file_system;
    $this->entityTypeManager = $entity_type_manager;
    $this->strawberryRunnerProcessorPluginManager = $strawberry_runner_processor_plugin_manager;
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
  public function onEntityPresave(StrawberryfieldCrudEvent $event) {

    /* @var $plugin_config_entities \Drupal\strawberry_runners\Entity\strawberryRunnerPostprocessorEntity[] */
    $plugin_config_entities = $this->entityTypeManager->getListBuilder('strawberry_runners_postprocessor')->load();
    $active_plugins = [];
    foreach($plugin_config_entities as $plugin_config_entity) {
      if ($plugin_config_entity->isActive()) {
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
      foreach($active_plugins['entity:file'] as $activePluginId => $config) {
        if ($config['source_type'] == 'asstructure') {
          $askeys = array_filter($config['jsonkey']);
          foreach($askeys as $key => $value) {
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
          $sbf_type = NULL;
          if (isset($flatvalues['type'])) {
            $sbf_type = $flatvalues['type'];
          }
          foreach ($askeymap as $jsonkey => $activePlugins) {
            if (isset($flatvalues[$jsonkey])) {
              foreach ($flatvalues[$jsonkey] as $uniqueid => $asstructure) {
                if (isset($asstructure['dr:fid']) && is_numeric($asstructure['dr:fid'])) {

                  foreach($activePlugins as $activePluginId => $config) {
                    $valid_mimes = [];
                    if (empty($config['ado_type']) || in_array($config['ado_type'] , $sbf_type)) {
                      $valid_mimes = explode(',', $config['mime_type']);
                      if (empty($valid_mimes) || (isset($asstructure["dr:mimetype"]) && in_array($asstructure["dr:mimetype"], $valid_mimes))) {
                        $data = new \stdClass();
                        $data->fid = $asstructure['dr:fid'];
                        $data->nid = $entity->id();
                        $data->plugin_config_entity_id = $activePluginId;

                        \Drupal::queue('strawberryrunners_process_index')
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
    $this->messenger->addStatus(t('Post processor was invoked'));

  }


  /**
   * Make sure no HTML or Javascript will be passed around.
   *
   * @param string $string
   *   A value returned by a processor
   *
   * @return string
   *   The value sanitized.
   */
  private function sanitizeValue($string) {
    if (!Unicode::validateUtf8($string)) {
      $string = Html::escape(utf8_encode($string));
    }
    return $string;
  }

  /**
   * Adds File usage to DB for temp files used by SB Runners.
   *
   * This differs from how we count managed files in other places like SBF.
   * Every Post Processor that needs the file will add a count
   * Once done, will remove one. File will become unused when everyone releases it.
   *
   *
   * @param \Drupal\file\FileInterface $file
   * @param int $nodeid
   */
  protected function add_file_usage(FileInterface $file, int $nodeid, string $entity_type_id = 'node') {
    if (!$file || !$this->moduleHandler->moduleExists('file')) {
      return;
    }
    /** @var \Drupal\file\FileUsage\FileUsageInterface $file_usage */

    if ($file) {
      $this->fileUsage->add($file, 'strawberry_runners', $entity_type_id, $nodeid);
    }
  }

  /**
   * Deletes File usage from DB for temp files used by SB Runners.
   *
   * @param \Drupal\file\FileInterface $file
   * @param int $nodeid
   * @param int $count
   *  If count is 0 it will remove all references.
   */
  protected function remove_file_usage(
    FileInterface $file,
    int $nodeid,
    string $entity_type_id = 'node',
    $count = 1
  ) {
    if (!$file || !$this->moduleHandler->moduleExists('file')) {
      return;
    }
    /** @var \Drupal\file\FileUsage\FileUsageInterface $file_usage */

    if ($file) {
      $this->fileUsage->delete(
        $file,
        'strawberry_runners',
        $entity_type_id,
        $nodeid,
        $count
      );
    }
  }

}
