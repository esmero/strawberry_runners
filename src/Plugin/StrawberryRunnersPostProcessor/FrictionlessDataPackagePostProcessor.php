<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 11/11/19
 * Time: 8:18 PM
 */

namespace Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessor;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginBase;
use Drupal\Core\Form\FormStateInterface;
use frictionlessdata\datapackage\Package;
use Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface;

/**
 *
 * System Binary Post processor Plugin Implementation
 *
 * @StrawberryRunnersPostProcessor(
 *    id = "datapackage",
 *    label = @Translation("Post processor that writes/reads Frictionless Data Packages"),
 *    input_type = "entity:file",
 *    input_property = "filepath",
 *    input_argument = "sequence_number"
 * )
 */
class FrictionlessDataPackagePostProcessor extends StrawberryRunnersPostProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'source_type' => 'asstructure',
        'mime_type' => ['application/zip, application\/vnd.datapackage+zip'],
        'path' => '',
        'arguments' => '',
        'output_type' => 'json',
        'output_destination' => 'searchapi',
        'processor_queue_type' => 'background',
        'time_out' => '300',
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
      '#description' => $this->t('Select from where the source file this processor needs is fetched'),
      '#required' => TRUE,
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
        'as:document' => 'as:document',
        'as:application' => 'as:application',
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


    $element['resource_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('A relative path of the resource to index'),
      '#default_value' => !empty($this->getConfiguration()['arguments']) ? $this->getConfiguration()['arguments'] : 'pages/pages.jsonl',
      '#description' => t('Which non binary resource (text, json, csv, jsonl, xml contained in manifest.json of the Data packaged will be extracted and processed. We will do our best to extract the most relevant information based on the format.'),
      '#required' => TRUE,
    ];

    $element['output_type'] = [
      '#type' => 'select',
      '#title' => $this->t('The expected and desired output of this processor.'),
      '#options' => [
        'entity:file' => 'One or more Files',
        'json' => 'Data/Values that can be serialized to JSON',
      ],
      '#default_value' => $this->getConfiguration()['output_type'],
      '#description' => $this->t('If the output is just data and "One or more Files" is selected all data will be dumped into a file and handled as such.'),
    ];

    $element['output_destination'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t("Where and how the output will be used."),
      '#options' => [
        'plugin' => 'As Input for another processor Plugin',
        'searchapi' => 'In a Search API Document using the Strawberryfield Flavor Data Source (e.g used for HOCR highlight)',
      ],
      '#default_value' => (!empty($this->getConfiguration()['output_destination']) && is_array($this->getConfiguration()['output_destination'])) ? $this->getConfiguration()['output_destination'] : [],
      '#description' => t('As Input for another processor Plugin will only have an effect if another Processor is setup to consume this output.'),
      '#required' => TRUE,
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
    // Specific input key as defined in the annotation
    // In this case it will contain an absolute Path to a File.
    // Needed since this executes locally on the server via SHELL.

    $input_property = $this->pluginDefinition['input_property'];
    $input_argument = $this->pluginDefinition['input_argument'];
    $file_uuid = isset($io->input->metadata['dr:uuid']) ? $io->input->metadata['dr:uuid'] : NULL;
    $node_uuid = isset($io->input->nuuid) ? $io->input->nuuid : NULL;
    $config = $this->getConfiguration();
    $timeout = $config['timeout']; // in seconds

    if (isset($io->input->{$input_property}) && $file_uuid && $node_uuid) {
      // To be used by miniOCR as id in the form of {nodeuuid}/canvas/{fileuuid}/p{pagenumber}
      $sequence_number = isset($io->input->{$input_argument}) ? (int) $io->input->{$input_argument} : 1;
      $file_path = isset($io->input->{$input_property}) ? $io->input->{$input_property} : NULL;
      $package = Package::load($file_path);
      foreach ($package as $resource) {
        echo $resource->name();
        foreach ($resource as $row) {
          echo $row;
        }
      }
          $output = new \stdClass();
          $output->searchapi['fulltext'] = NULL;
          $output->plugin = NULL;
          $io->output = $output;
        }
      // Lastly plain text version of the XML.
      $io->output->searchapi['plaintext'] = isset($output->searchapi['fulltext']) ? strip_tags(str_replace("<l>", PHP_EOL . "<l> ", $output->searchapi['fulltext'])) : '';
  }

  /**
   * Builds a clean Command string using a File path.
   *
   * @param \stdClass $io
   *    $io->input needs to contain
   *           \Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor::$input_property
   *           \Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor::$input_arguments
   *    $io->output will contain the result of the processor
   *
   * @return null|string
   */
  public function buildExecutableCommand(\stdClass $io) {

      return NULL;
  }

}
