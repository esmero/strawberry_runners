<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 11/11/19
 * Time: 8:18 PM
 */

namespace Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessor;

use Drupal\Core\Form\FormStateInterface;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessor\SystemBinaryPostProcessor;
use Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginBase;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface;


/**
 *
 * File sequence Post processor Plugin Implementation
 *
 * @StrawberryRunnersPostProcessor(
 *    id = "filesequence",
 *    label = @Translation("Post processor that extracts/generates Ordered Sequences of files/pages/children using Files present in an ADO"),
 *    input_type = "entity:file",
 *    input_property = "filepath",
 *    input_argument = NULL
 * )
 */
class JsonFileSequencePostProcessor extends StrawberryRunnersPostProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'source_type' => 'asstructure',
        'mime_type' => ['application/pdf'],
        'output_type' => 'json',
        'output_destination' => 'plugin',
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

    $element['ado_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ADO type(s) to limit this processor to.'),
      '#default_value' => $this->getConfiguration()['ado_type'],
      '#description' => $this->t('A single ADO type or a coma delimited list of ado types that qualify to be Processed. Leave empty to apply to all ADOs.'),
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

    $element['mime_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mimetypes(s) to limit this Processor to.'),
      '#default_value' => $this->getConfiguration()['mime_type'],
      '#description' => $this->t('A single Mimetype type or a coma separed list of mimetypes that qualify to be Processed. Leave empty to apply any file'),
    ];

    $element['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout in seconds for this process.'),
      '#default_value' => $this->getConfiguration()['timeout'],
      '#description' => $this->t('If the process runs out of time it can still be processed again.'),
      '#size' => 2,
      '#maxlength' => 2,
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
    // Specific input key as defined in the annotation
    // In this case it will contain an absolute Path to a File.
    // Needed since this executes locally on the server via SHELL.

    $input_property = $this->pluginDefinition['input_property'];
    $file_uuid = isset($io->input->metadata['dr:uuid']) ? $io->input->metadata['dr:uuid'] : NULL;
    $node_uuid = isset($io->input->nuuid) ? $io->input->nuuid : NULL;
    $config = $this->getConfiguration();
    error_log('Get File Sequence');
    $page_number = [];
    if (isset($io->input->{$input_property}) && $file_uuid && $node_uuid) {
      // To be used by miniOCR as id in the form of {nodeuuid}/canvas/{fileuuid}/p{pagenumber}
      $io->output = $io->input;
      // Now check if there is an "flv:identify" and iterate over each one.
      if (isset($io->input->metadata['flv:identify']) && count($io->input->metadata['flv:identify']) > 0) {
        foreach ($io->input->metadata['flv:identify'] as $key => $sequence) {
          $page_number[] = $key;
        }
      }
      // At least give it one page. (Should we?)
      if (empty($page_number)) {
        $page_numbers[] = 1;
      }

      $output = new \stdClass();
      $output->plugin = ['page_number' => $page_number];
      $io->output = $output;
    }
    else {
      \throwException(new \InvalidArgumentException);
    }
  }
}



