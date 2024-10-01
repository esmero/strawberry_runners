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
        'language_key' => 'language_iso639_3',
        'language_default' => 'eng',
        'output_destination' => ['plugin' => 'plugin'],
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

    $element['language_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Within the ADO's metadata, the JSON key that contains the language in ISO639-3 (3 letter) format to be used for OCR/NLP processing via Tesseract."),
      '#default_value' => (!empty($this->getConfiguration()['language_key'])) ? $this->getConfiguration()['language_key'] : '',
      '#states' => [
        'visible' => [
          ':input[name="pluginconfig[source_type]"]' => ['value' => 'asstructure'],
          'and',
          ':input[name="pluginconfig[jsonkey][as:image]"]' => ['checked' => TRUE],
        ],
      ],
      '#required' => TRUE,
    ];

    $element['language_default'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Please provide a default language in ISO639-3 (3 letter) format. If none is provided we will use 'eng' "),
      '#default_value' => (!empty($this->getConfiguration()['language_default'])) ? $this->getConfiguration()['language_default'] : 'eng',
      '#required' => TRUE,
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
    // Specific input key as defined in the annotation
    // In this case it will contain an absolute Path to a File.
    // Needed since this executes locally on the server via SHELL.

    $input_property = $this->pluginDefinition['input_property'];
    $file_uuid = isset($io->input->metadata['dr:uuid']) ? $io->input->metadata['dr:uuid'] : NULL;
    $node_uuid = isset($io->input->nuuid) ? $io->input->nuuid : NULL;
    $config = $this->getConfiguration();
    $sequence_number = [];
    if (isset($io->input->{$input_property}) && $file_uuid && $node_uuid) {
      // To be used by miniOCR as id in the form of {nodeuuid}/canvas/{fileuuid}/p{pagenumber}
      $io->output = $io->input;
      // Now check if there is an "flv:identify" and has more than one entry, and iterate over each one.
      if (isset($io->input->metadata['flv:identify']) && count($io->input->metadata['flv:identify']) > 1) {
        foreach ($io->input->metadata['flv:identify'] as $key => $sequence) {
          $sequence_number[] = $key;
        }
      }
      elseif (isset($io->input->metadata['flv:pdfinfo']) && count($io->input->metadata['flv:pdfinfo']) > 0) {
        foreach ($io->input->metadata['flv:pdfinfo'] as $key => $sequence) {
          $sequence_number[] = $key;
        }
      }
      elseif (isset($io->input->metadata['sequence'])) {
        // If not assign the internal file sequence relative to its type (e.g as:image)
        // Final Sequence number is always relative to itself given that on Solr
        // We use the actual file UUID to as part of the ID
        // e.g default_solr_index-strawberryfield_flavor_datasource/5801:1:en:1e9f687c-e29e-4c23-91ba-655d9c5cdfe6:ocr
        // For the general ID we will use this number when there are multiple siblings
        // or 1 if the File is a single output
        $sequence_number[] = $io->input->metadata['sequence'];
      }

      // If empty it migth be a single image that lacks proper structure. That is fine. we use 1 since
      if (empty($sequence_number)) {
        $sequence_number[] = 1;
      }

      $output = new \stdClass();
      $output->plugin = [
        'sequence_number' => $sequence_number,
      ];
      $io->output = $output;
    }
    else {
      throw new \InvalidArgumentException(\sprintf("Invalid arguments passed to %s", $this->getPluginId()));
    }
  }

}




