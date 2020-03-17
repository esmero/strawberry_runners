<?php

namespace Drupal\strawberry_runners\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\strawberryfield\Event\StrawberryfieldServiceEvent;
use Drupal\strawberryfield\EventSubscriber\StrawberryfieldEventJsonProcessingSubscriber;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Entity\EntityStorageInterface;
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
class StrawberryRunnersEventJsonProcessingSubscriber extends StrawberryfieldEventJsonProcessingSubscriber  {


  use StringTranslationTrait;

  /**
   * @var int
   */
  protected static $priority = -700;

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
  protected $instanceCopiesOfFiles;

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
   * StrawberryRunnersEventJsonProcessingSubscriber constructor.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * @param \Symfony\Component\Serializer\SerializerInterface $serializer
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   * @param \Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginManager $strawberry_runner_processor_plugin_manager
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityStorageInterface $storage,
    TranslationInterface $string_translation,
    MessengerInterface $messenger,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    StreamWrapperManagerInterface $stream_wrapper_manager,
    FileSystemInterface $file_system,
    StrawberryRunnersPostProcessorPluginManager $strawberry_runner_processor_plugin_manager
  ) {
    $this->stringTranslation = $string_translation;
    $this->messenger = $messenger;
    $this->loggerFactory = $logger_factory;
    $this->configFactory = $config_factory;
    $this->storage = $storage;
    $this->entityTypeManager = $entity_type_manager;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->fileSystem = $file_system;
    $this->strawberryRunnerProcessorPluginManager = $strawberry_runner_processor_plugin_manager;
  }

  /**
   * Method called when Event occurs.
   *
   * @param \Drupal\strawberryfield\Event\StrawberryfieldServiceEvent $event
   *   The event.
   */
  public function onJsonInvokeProcess(StrawberryfieldServiceEvent $event) {

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
        $active_plugins[$plugin_definition['input_type']][] = $plugin_instance;
      }
    }

    // We will fetch all files and then see if each file can be processed by one
    // or more plugin.
    // Slower option would be to traverse every file per processor.

    $updated = 0;
    $entity = $event->getEntity();
    $sbf_fields = $event->getFields();
    $processedcount = 0;
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

          if (isset($flatvalues['dr:fid'])) {
            foreach ($flatvalues['dr:fid'] as $fid) {
              if (is_numeric($fid)) {
                $file = $this->entityTypeManager->getStorage('file')->load(
                  $fid
                );
                /** @var $file FileInterface; */
                if ($file) {
                  $this->add_file_usage($file, $entity->id(), $entity_type_id);
                  $updated++;
                }
                else {
                  $this->messenger()->addError(
                    t(
                      'Your content references a file with Internal ID @file_id that does not exist or was removed.',
                      ['@file_id' => $fid]
                    )
                  );
                }
              }
            }
          }
        }
      }
    }
  }

  /**
   * Move file to local and process.
   *
   * @param \Drupal\file\FileInterface $file
   *   The File URI to look at.
   *
   * @return array
   *   Output of processing chain for a particular file.
   */
  private function processFile(FileInterface $file) {
    $uri = $file->getFileUri();
    $processOutput = [];

    /** @var \Drupal\Core\File\FileSystem $file_system */
    $scheme = $this->fileSystem->uriScheme($uri);

    // If the file isn't stored locally make a temporary copy.
    if (!isset($this->streamWrapperManager
        ->getWrappers(StreamWrapperInterface::LOCAL)[$scheme])) {
      // Local stream.
      $cache_key = md5($uri);
      if (empty($this->instanceCopiesOfFiles[$cache_key])) {
        if (!($this->instanceCopiesOfFiles[$cache_key] = $this->fileSystem->copy($uri, 'temporary://sbr_' . $cache_key . '_' . basename($uri), FileSystemInterface::FILE_EXISTS_REPLACE))) {
          $this->loggerFactory->get('strawberry_runners')
            ->notice('Unable to create local temporary copy of remote file for Strawberry Runners Post processing File %file.',
              [
                '%file' => $uri,
              ]);
          return [];
        }
      }
      $uri = $this->instanceCopiesOfFiles[$cache_key];
    }

    return $processOutput;
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
   * Cleanup of artifacts from processing files.
   */
  public function __destruct() {
    // Get rid of temporary files created for this instance.
    foreach ($this->instanceCopiesOfFiles as $uri) {
      \Drupal::service('file_system')->unlink($uri);
    }
  }







}
