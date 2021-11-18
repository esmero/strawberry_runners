<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 11/11/19
 * Time: 8:18 PM
 */

namespace Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessor;

use Drupal\Core\Form\FormStateInterface;
use Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginBase;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface;
use Mixnode\WarcReader;


/**
 *
 * System Binary Post processor Plugin Implementation
 *
 * @StrawberryRunnersPostProcessor(
 *    id = "warc",
 *    label = @Translation("Post processor that extracts info and data from WARC files"),
 *    input_type = "entity:file",
 *    input_property = "filepath"
 * )
 */
class WarcExtractionPostProcessor extends StrawberryRunnersPostProcessorPluginBase{

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'source_type' => 'asstructure',
      'mime_type' => ['application/pdf'],
      'path' => '',
      'arguments' => '',
      'output_type' => 'json',
      'output_destination' => 'subkey',
    ] + parent::defaultConfiguration();
  }


  public function calculateDependencies() {
    // Since Processors could be chained we need to check if any other
    // processor instance is using an instance of this one
    // @TODO: Implement calculateDependencies() method.
  }

  public function settingsForm(array $parents, FormStateInterface $form_state) {

    $element['source_type'] = [
      '#type' => 'select',
      '#title' => $this->t('The type of source data this processor works on'),
      '#options' => [
        'asstructure' => 'File entities referenced in the as:filetype JSON structure',
        'filepath' => 'Full file paths passed by another processor',
      ],
      '#default_value' => $this->getConfiguration()['source_type'],
      '#description' => $this->t('Select from where the source file  this processor needs is fetched'),
      '#required' => TRUE
    ];

    $element['ado_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ADO type(s) to limit this processor to.'),
      '#default_value' => $this->getConfiguration()['ado_type'],
      '#description' => $this->t('A single ADO type or a coma separed list of ado types that qualify to be Processed. Leave empty to apply to all ADOs.'),
    ];

    $element['jsonkey'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('The JSON key that contains the desired source files.'),
      '#options' => [
        'as:document' => 'as:document',
        'as:application' =>  'as:application',
      ],
      '#default_value' => (!empty($this->getConfiguration()['jsonkey']) && is_array($this->getConfiguration()['jsonkey'])) ? $this->getConfiguration()['jsonkey'] : [],
      '#states' => [
        'visible' => [
          ':input[name="pluginconfig[source_type]"]' => ['value' => 'asstructure'],
          ],
        ],
      '#required' => TRUE,
    ];

    $element['mime_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mimetypes(s) to limit this Processor to.'),
      '#default_value' => $this->getConfiguration()['mime_type'],
      '#description' => $this->t('A single Mimetype type or a coma separed list of mimetypes that qualify to be Processed. Leave empty to apply any file'),
    ];

    $element['output_type'] = [
      '#type' => 'select',
      '#title' => $this->t('The expected and desired output of this processor.'),
      '#options' => [
        'json' => 'Data/Values that can be serialized to JSON',
      ],
      '#default_value' => $this->getConfiguration()['output_type'],
      '#description' => $this->t('If the output is just data and "One or more Files" is selected all data will be dumped into a file and handled as such.'),
    ];

    $element['output_destination'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t("Where and how the output will be used."),
      '#options' => [
        'subkey' => 'In the same Source Metadata, as a child structure of each Processed file',
        'ownkey' => 'In the same Source Metadata but inside its own, top level, "as:flavour" subkey based on the given machine name of the current plugin',
        'plugin' => 'As Input for another processor Plugin',
      ],
      '#default_value' => (!empty($this->getConfiguration()['output_destination']) && is_array($this->getConfiguration()['output_destination']))? $this->getConfiguration()['output_destination']: [],
      '#description' => t('As Input for another processor Plugin will only have an effect if another Processor is setup to consume this ouput.'),
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
   *    $io->input needs to contain \Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor::$input_property
   *    $io->output will contain the result of the processor
   * @param string $context
   */
  public function run(\stdClass $io, $context = StrawberryRunnersPostProcessorPluginInterface::PROCESS) {
    // Specific input key as defined in the annotation
    // In this case it will contain an absolute Path to a File.
    // Needed since this executes locally on the server via SHELL.
    $input_property =  $this->pluginDefinition['input_property'];
    if (isset($io->input->{$input_property})) {
       $warc_reader = new WarcReader($io->input->{$input_property});
       $output = NULL;
       while(($record = $warc_reader->nextRecord()) != FALSE){
        // A WARC record is broken into two parts: header and content.
        // header contains metadata about content, while content is the actual resource captured.
         $output[] = $record['header'];
        //print_r($record['content']);
      }
        if (is_null($output)) {
          throw new \Exception("Could not execute WarcReader");
        }
        $io->output =  $output;

      }
    else {
      throw new \InvalidArgumentException(\sprintf("Invalid arguments passed to %s",$this->getPluginId()));
    }
  }
}
