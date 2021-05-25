<?php

namespace Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessor;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface;
use Web64\Nlp\NlpClient;

/**
 *
 * System Binary Post processor Plugin Implementation
 *
 * @StrawberryRunnersPostProcessor(
 *    id = "webpage",
 *    label = @Translation("Post processor that Indexes WACZ Frictionless data Search Index to Search API"),
 *    input_type = "json",
 *    input_property = "plugin_metadata",
 *    input_argument = "sequence_number"
 * )
 */
class WebPageTextPostProcessor extends StrawberryRunnersPostProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'source_type' => 'json',
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
        'json' => 'JSON passed by a parent Processor',
      ],
      '#default_value' => $this->getConfiguration()['source_type'],
      '#description' => $this->t('Select from where the source data for this processor is fetched'),
      '#required' => TRUE,
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
      '#description' => t('As Input for another processor Plugin will only have an effect if another Processor is setup to consume this ouput.'),
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
    $input_property = $this->pluginDefinition['input_property'];
    $node_uuid = isset($io->input->nuuid) ? $io->input->nuuid : NULL;
    $output = new \stdClass();
    $output->searchapi['fulltext'] = StrawberryfieldFlavorDatasource::EMPTY_MINIOCR_XML;
    $output->searchapi['metadata'] = [];
    if (isset($io->input->{$input_property}) && $node_uuid) {
        $page_info = json_decode($io->input->{$input_property}, true, 3);
      if (json_last_error() == JSON_ERROR_NONE) {
        $page_title = $page_info['title'] ?? NULL;
        $page_url = $page_info['url'] ?? '';
        $page_title = $page_title ?? $page_url;
        $page_text = $page_info['text'] ?? '';
        $page_ts = $page_info['ts'] ?? date("c");
        $nlp = new NlpClient('http://esmero-nlp:6400');
        if ($nlp) {
          $polyglot = $nlp->polyglot_entities($page_text, 'en');
          $output->searchapi['where']= $polyglot->getLocations();
          $output->searchapi['who'] = array_unique(array_merge($polyglot->getOrganizations() , $polyglot->getPersons()));
          $output->searchapi['sentiment'] = $polyglot->getSentiment();
          $output->searchapi['uri'] = $page_url;
          $entities_all = $polyglot->getEntities();
          if (!empty($entities_all) and is_array($entities_all)) {
            $output->searchapi['metadata'] = $entities_all;
          }
        }
        $output->searchapi['plaintext'] = $page_url . ' , '. $page_title . ' , ' . $page_text;
        $output->searchapi['label'] = $page_title;
        $output->searchapi['metadata'][] = $page_url;

        $output->searchapi['ts'] = $page_ts;

        $output->plugin = $output->searchapi;
      } else {
        throw new \Exception("WebPage Text was not a valid JSON");
      }
    }
      $io->output = $output;
  }
}
