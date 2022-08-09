<?php

namespace Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessor;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface;
use Drupal\strawberry_runners\Web64\Nlp\NlpClient;

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
        'nlp' => TRUE,
        'nlp_url' => 'http://esmero-nlp:6400',
        'nlp_method' => 'polyglot',
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

    $element['nlp'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Use NLP to extract entities from Text"),
      '#default_value' => $this->getConfiguration()['nlp'] ?? TRUE,
      '#description' => t('If checked Full text will be processed for Natural language Entity extraction using Polyglot'),
    ];
    $element['nlp_url'] = [
      '#type' => 'url',
      '#title' => $this->t("The URL location of your NLP64 server."),
      '#default_value' => $this->getConfiguration()['nlp_url'] ?? 'http://esmero-nlp:6400',
      '#description' => t('Defaults to http://esmero-nlp:6400'),
      '#states' => [
        'visible' => [
          ':input[name="pluginconfig[nlp]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $element['nlp_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Which method(NER) to use'),
      '#options' => [
        'spacy' => 'spaCy (more accurate)',
        'polyglot' => 'Polyglot (faster)',
      ],
      '#default_value' => $this->getConfiguration()['nlp_method'],
      '#description' => $this->t('The NER NLP method to use to extract Agents, Places and Sentiment'),
      '#states' => [
        'visible' => [
          ':input[name="pluginconfig[nlp]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $element['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout in seconds for this process.'),
      '#default_value' => $this->getConfiguration()['timeout'],
      '#description' => $this->t('If the process runs out of time it can still be processed again.'),
      '#size' => 4,
      '#maxlength' => 4,
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
    $config = $this->getConfiguration();
    if (isset($io->input->{$input_property}) && $node_uuid) {
      $page_info = json_decode($io->input->{$input_property}, true, 3);
      if (json_last_error() == JSON_ERROR_NONE) {
        $page_title = $page_info['title'] ?? NULL;
        $page_url = $page_info['url'] ?? '';
        $page_title = $page_title ?? $page_url;
        $page_text = $page_info['text'] ?? '';
        $page_text = preg_replace('/[\x0D]/', '', $page_text);
        $page_ts = $page_info['ts'] ?? date("c");
        // Check if NPL processing is enabled and if so do it.
        if ($config['nlp'] && !empty($config['nlp_url']) && strlen(trim($page_text)) > 0 ) {
          $nlp = new NlpClient($config['nlp_url']);
          if ($nlp) {
            $languages_enabled = [];
            $capabilities = $nlp->get_call('/status', NULL);
            $detected_lang = NULL;
            //@TODO Should cache this too. Or deprecate ::language for 0.5.0
            if ($capabilities
              && is_array($capabilities)
              && is_array($capabilities['web64']['endpoints'])
              && in_array('/fasttext', $capabilities['web64']['endpoints'])) {
              $detected_lang = $nlp->fasttext($page_text);
              $detected_lang = is_array($detected_lang) && isset($detected_lang['language']) ? $detected_lang['language'] : $detected_lang;
            }
            // Either Capabilities are not present for FastText of we had an issue.
            if ($detected_lang == NULL) {
              $detected_lang = $nlp->language($page_text) ?? NULL;
            }
            $cache_id = "strawberry_runners:postprocessor:".$this->getPluginId();
            $cached = $this->cacheBackend->get($cache_id);
            if ($cached) {
              $languages_enabled = $cached->data;
            }
            else {
              if ($capabilities && is_array($capabilities) && isset($capabilities['polyglot_lang_models']) && is_array($capabilities['polyglot_lang_models'])) {
                $languages_enabled = array_keys($capabilities['polyglot_lang_models']);
                $languages_enabled = array_map(function ($languages_enabled) {
                  $parts = explode(':', $languages_enabled);
                  return $parts[1] ?? NULL;
                }, $languages_enabled);
                $languages_enabled = array_filter($languages_enabled);
                $this->cacheBackend->set($cache_id, $languages_enabled, CacheBackendInterface::CACHE_PERMANENT, [$cache_id]);
              }
            }
            if ($config['nlp_method'] == 'spacy') {
              /*
              PERSON:      People, including fictional.
              NORP:        Nationalities or religious or political groups.
              FAC:         Buildings, airports, highways, bridges, etc.
              ORG:         Companies, agencies, institutions, etc.
              GPE:         Countries, cities, states.
              LOC:         Non-GPE locations, mountain ranges, bodies of water.
              PRODUCT:     Objects, vehicles, foods, etc. (Not services.)
              EVENT:       Named hurricanes, battles, wars, sports events, etc.
              WORK_OF_ART: Titles of books, songs, etc.
              LAW:         Named documents made into laws.
              LANGUAGE:    Any named language.
              DATE:        Absolute or relative dates or periods.
              TIME:        Times smaller than a day.
              PERCENT:     Percentage, including ”%“.
              MONEY:       Monetary values, including unit.
              QUANTITY:    Measurements, as of weight or distance.
              ORDINAL:     “first”, “second”, etc.
              CARDINAL:    Numerals that do not fall under another type.
               */
              $spacy = $nlp->spacy_entities($page_text,'en');
              $output->searchapi['sentiment'] = $nlp->sentiment($page_text, 'en');
              $output->searchapi['sentiment'] = is_scalar($output->searchapi['sentiment']) ? $output->searchapi['sentiment'] : NULL;
              $output->searchapi['where'] = array_unique(($spacy['GPE'] ?? []) + ($spacy['FAC'] ?? []));
              $output->searchapi['who'] = array_unique(($spacy['PERSON'] ?? []) + ($spacy['ORG'] ?? []));
              $output->searchapi['metadata'] = array_unique(($spacy['WORK_OF_ART'] ?? []) + ($spacy['EVENT'] ?? []));
            }
            elseif ($config['nlp_method'] == 'polyglot') {
              if (in_array($detected_lang, $languages_enabled)) {
                $polyglot = $nlp->polyglot_entities($page_text, $detected_lang);
                $output->searchapi['where'] = $polyglot->getLocations();
                $output->searchapi['who'] = array_unique(array_merge((array) $polyglot->getOrganizations(),
                  (array) $polyglot->getPersons()));
                $output->searchapi['sentiment'] = $polyglot->getSentiment();
                $entities_all = $polyglot->getEntities();
                if (!empty($entities_all) and is_array($entities_all)) {
                  $output->searchapi['metadata'] = $entities_all;
                }
              }
            }
            $output->searchapi['nlplang'] = [$detected_lang];
            foreach (['where','who', 'metadata'] as $nlp_key) {
              if (isset($output->searchapi[$nlp_key])
                && is_array(
                  $output->searchapi[$nlp_key]
                )
              ) {
                $output->searchapi[$nlp_key] = preg_grep(
                  "/^[\p{L}|\p{N}\s+]+[\p{L}|\p{N}\s\-'+]+[\p{L}|\p{N}\s+]+$/u",
                  $output->searchapi[$nlp_key]
                );
              }
            }
          }
          else {
            $this->logger->warning('NLP64 server @nlp_url could not be queried. Skipping NLP.',
              [
                '@nlp_url' => $config['nlp_url'],
              ]);
          }
        }
        $output->searchapi['uri'] = $page_url;
        $output->searchapi['plaintext'] = $page_title . '\n' . $page_text;
        // Empty since we are extracting what is there, we did not define
        // Any post processing language at all here.
        $output->searchapi['processlang'] = [];
        $output->searchapi['label'] = $page_title;
        $output->searchapi['ts'] = $page_ts;
        $output->plugin = $output->searchapi;
      }
      else {
        throw new \Exception("WebPage Text was not a valid JSON.");
      }
    }
    $io->output = $output;
  }
}
