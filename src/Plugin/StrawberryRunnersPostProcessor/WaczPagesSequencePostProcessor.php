<?php

namespace Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessor;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginBase;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface;

/**
 *
 * File sequence Post processor Plugin Implementation
 *
 * @StrawberryRunnersPostProcessor(
 *    id = "waczpages",
 *    label = @Translation("Post processor that extracts/generates Indexed Page Content from WACZ files in an ADO"),
 *    input_type = "entity:file",
 *    input_property = "filepath",
 *    input_argument = NULL
 * )
 */
class WaczPagesSequencePostProcessor extends StrawberryRunnersPostProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'source_type' => 'asstructure',
        'mime_type' => ['application/vnd.datapackage+zip'],
        'output_type' => 'json',
        'output_destination' => ['plugin' => 'plugin'],
        'processor_queue_type' => ['realtime' => 'realtime'],
      ] + parent::defaultConfiguration();
  }


  public function calculateDependencies() {
    // Since Processors could be chained we need to check if any other
    // processor instance is using an instance of this one
    // @TODO: Implement calculateDependencies() method.
  }

  public function settingsForm(array $parents, FormStateInterface $form_state) {

    $element['source_type'] = [
      '#type' => 'hidden',
      '#title' => $this->t('The type of source data this processor works on'),
      '#default_value' => $this->getConfiguration()['source_type'],
    ];

    $element['jsonkey'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('The JSON key that contains the desired source files.'),
      '#options' => [
        'as:image' => 'as:image',
        'as:document' => 'as:document',
        'as:audio' => 'as:audio',
        'as:video' => 'as:video',
        'as:text' => 'as:text',
        'as:application' => 'as:application',
      ],
      '#default_value' => (!empty($this->getConfiguration()['jsonkey']) && is_array($this->getConfiguration()['jsonkey'])) ? $this->getConfiguration()['jsonkey'] : [],
      '#required' => TRUE,
    ];

    // Because we are using the default entity Form, we want to ensure the
    // Settings for contains all the values
    $element['output_destination'] = [
      '#type' => 'value',
      '#default_value' => $this->defaultConfiguration()['output_destination'],
    ];

    $element['mime_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mimetypes(s) to limit this Processor to.'),
      '#default_value' => $this->getConfiguration()['mime_type'],
      '#description' => $this->t('A single Mimetype type or a coma separed list of mimetypes that qualify to be Processed. Leave empty to apply any file'),
    ];


    $element['ado_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ADO type(s) to limit this processor to.'),
      '#default_value' => $this->getConfiguration()['ado_type'],
      '#description' => $this->t('A single ADO type or a coma delimited list of ado types that qualify to be Processed. Leave empty to apply to all ADOs.'),
    ];

    $element['output_type'] = [
      '#type' => 'select',
      '#title' => $this->t('The expected and desired output of this processor.'),
      '#options' => [
        'json' => 'Data/Values that can be serialized to JSON',
      ],
      '#default_value' => $this->getConfiguration()['output_type'],
      '#description' => $this->t('Only option for this Processor is JSON output'),
    ];


    $element['processor_queue_type'] = [
      '#type' => 'select',
      '#title' => $this->t('The queue to use for this processor.'),
      '#options' => [
        'background' => 'Secondary queue in background',
        'realtime' => 'Primary queue in realtime',
      ],
      '#default_value' => $this->getConfiguration()['processor_queue_type'],
      '#description' => $this->t('The primary queue will be execute in realtime while the Secondary will be execute in background'),
    ];

    $element['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout in seconds for this process.'),
      '#default_value' => $this->getConfiguration()['timeout'],
      '#description' => $this->t('If the process runs out of time it can still be processed again.'),
      '#size' => 3,
      '#maxlength' => 3,
      '#min' => 1,
    ];
    $element['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Order or execution in the global chain.'),
      '#default_value' => $this->getConfiguration()['weight'],
    ];

    return $element;
  }


  public function onDependencyRemoval(array $dependencies) {
    // Since Processors could be chained we need to check if any other
    // processor instance is using an instance of this one
    return parent::onDependencyRemoval(
      $dependencies
    ); // TODO: Change the autogenerated stub
  }

  /**
   * Executes the logic of this plugin given a file path and a context.
   *
   * @param \stdClass $io
   *    $io->input needs to contain
   *           \Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor::$input_property
   *           \Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor::$input_arguments
   *    $io->output will contain the result of the processor
   * @param string $context
   */
  public function run(\stdClass $io, $context = StrawberryRunnersPostProcessorPluginInterface::PROCESS) {

    $input_property = $this->pluginDefinition['input_property'];
    $input_argument = $this->pluginDefinition['input_argument'];
    $file_uuid = isset($io->input->metadata['dr:uuid']) ? $io->input->metadata['dr:uuid'] : NULL;
    $node_uuid = isset($io->input->nuuid) ? $io->input->nuuid : NULL;
    $config = $this->getConfiguration();
    $output = new \stdClass();
    $io->output = $io->input;
    $output->searchapi['fulltext'] = '';
    if (isset($io->input->{$input_property}) && $file_uuid && $node_uuid) {
      // To be used by miniOCR as id in the form of {nodeuuid}/canvas/{fileuuid}/p{pagenumber}
      $file_path = isset($io->input->{$input_property}) ? $io->input->{$input_property} : NULL;
      // File path may not be .zip?
      // We may want to check
      $info = pathinfo($file_path);
      $newname = $info['dirname'].'/'.$info['filename'] . '.' . 'zip';
      $sequence_data = [];
      $sequence_number = [];
      $this->fileSystem->move($file_path, $newname, FileSystemInterface::EXISTS_REPLACE);
      $z = new \ZipArchive();
      $contents = NULL;
      if ($z->open($newname)) {
        $fp = $z->getStream('pages/pages.jsonl');
        if ($fp) {
          $i = 0;
          while (($buffer = fgets($fp, 4096)) !== FALSE) {
            // First row in a jsonl will be the headers, we do not need this one.
            if ($i == 0) {
              $i++;
              continue;
            }
            $sequence_data[$i] = $buffer;
            $sequence_number[] = $i;
            $i++;
          }
          if (!feof($fp)) {
            error_log('ups!');
          }
          fclose($fp);
        }
        else {
          // Opening the ZIP file failed.
          error_log('NO Pages found to extract');
        }
      }

      $output = new \stdClass();
      $output->plugin = [
        'sequence_number' => $sequence_number,
        'plugin_metadata' => $sequence_data,
      ];
      $io->output = $output;
      error_log(var_export($io, true));
    }
    else {
      throw new \InvalidArgumentException(\sprintf("Invalid arguments passed to %s", $this->getPluginId()));
    }
  }

}




