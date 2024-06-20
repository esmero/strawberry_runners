<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 11/18/22
 * Time: 2:01 PM
 */

namespace Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessor;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\strawberry_runners\Web64\Nlp\NlpClient;


/**
 *
 * System Binary Post processor Plugin Implementation
 *
 * @StrawberryRunnersPostProcessor(
 *    id = "text",
 *    label = @Translation("Post processor that extracts text from Files"),
 *    input_type = "entity:file",
 *    input_property = "filepath",
 *    input_argument = NULL
 * )
 */
class TextPostProcessor extends OcrPostProcessor {

  public $pluginDefinition;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'source_type' => 'asstructure',
        'mime_type' => ['application/pdf'],
        'output_type' => 'json',
        'output_destination' => 'searchapi',
        'processor_queue_type' => 'background',
        'language_key' => 'language_iso639_3',
        'language_default' => 'eng',
        'timeout' => 300,
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
        'asstructure' => 'File entities referenced in the as:filetype JSON structure',
        'filepath' => 'Full file paths passed by another processor',
      ],
      '#default_value' => $this->getConfiguration()['source_type'],
      '#description' => $this->t('Select from where the source file  this processor needs is fetched'),
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
        'as:text' => 'as:text',
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
      '#description' => $this->t('A single Mimetype type or a comma separated list of mimetypes that qualify to be Processed. Leave empty to apply any file'),
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

    $element['output_type'] = [
      '#type' => 'select',
      '#title' => $this->t('The expected and desired output of this processor.'),
      '#options' => [
        'json' => 'Data/Values that can be serialized to JSON',
      ],
      '#default_value' => $this->getConfiguration()['output_type'],
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
      '#description' => $this->t('The NER NLP method to use to extract Agents, Places and Sentiment.'),
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
   *           $io->input needs to contain
   *           \Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor::$input_property
   *           \Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor::$input_arguments
   *           $io->output will contain the result of the processor
   * @param string    $context
   *
   * @throws \Exception
   */
  public function run(\stdClass $io, $context = StrawberryRunnersPostProcessorPluginInterface::PROCESS) {
    // Specific input key as defined in the annotation

    $input_property = $this->pluginDefinition['input_property'];

    $file_uuid = isset($io->input->metadata['dr:uuid']) ? $io->input->metadata['dr:uuid'] : NULL;
    $node_uuid = isset($io->input->nuuid) ? $io->input->nuuid : NULL;
    $file_path = isset($io->input->{$input_property}) ? $io->input->{$input_property} : NULL;
    $config = $this->getConfiguration();
    $file_languages = isset($io->input->lang) ? (array) $io->input->lang : [$config['language_default'] ? trim($config['language_default'] ?? '') : 'eng'];
    if ($file_path && $file_uuid && $node_uuid) {
      $output = new \stdClass();
      // Let's see if we need an output path or not
      $file_path = isset($io->input->{$input_property}) ? $io->input->{$input_property} : NULL;
      $out_file_path = NULL;
      $sequence_number = isset($io->input->metadata['sequence']) ? (int) $io->input->metadata['sequence'] : 1;
      $file_mime = $io->input->metadata["dr:mimetype"] ?? NULL;
      $page_text = '';
      if ($file_mime) {
        $text_content = file_get_contents($file_path);
        if (!$this->isBinary($text_content)) {
          if ($this->isTextMime($file_mime)) {
            $page_text = $text_content;
          }
          elseif ($this->isXmlMime($file_mime)) {
            // Lastly plain text version of the XML
            // Try first with HOCR
            $page_text = $this->hOCRtoMiniOCR($text_content, $sequence_number);
            if ($page_text) {
              $page_text = strip_tags(str_replace("<l>",
                PHP_EOL . "<l> ", $page_text)) ;
            }
            else {
              // Simple remove all
              $page_text = strip_tags(
                str_replace(
                  ["<br>", "</br>"],
                  PHP_EOL, $text_content
                )
              );
              $page_text = html_entity_decode($page_text);
              $page_text = preg_replace(
                ['/\h{2,}|(\h*\v{1,})/umi', '/\v{2,}/uim', '/\h{2,}/uim'],
                [" \n", " \n", ' '], $page_text
              );
            }
          }
          elseif ($this->isJsonMime($file_mime)) {
            $page_array = json_decode($text_content, TRUE);
            if (json_last_error() == JSON_ERROR_NONE) {
              $page_text = '';
              array_walk_recursive($page_array, function ($item, $key) use (&$page_text){$page_text .= $key.' '. $item .' ';});
              $page_text = trim($page_text ?? '');
            }
          }
          $output->searchapi['fulltext']
            = StrawberryfieldFlavorDatasource::EMPTY_MINIOCR_XML;
          $output->searchapi['plaintext'] = $page_text;
        }
        else {
          $this->logger->warning(
            "Text processing was not possible because binary data was found"
          );
          throw new \Exception(
            "Could not execute text extraction on binary data"
          );
        }

        $output->searchapi['metadata'] = [];
        // Check if NPL processing is enabled and if so do it.
        if ($config['nlp'] && !empty($config['nlp_url'])
          && strlen(
            trim($page_text ?? '')
          ) > 0
        ) {
          $nlp = new NlpClient($config['nlp_url']);
          if ($nlp) {
            $capabilities = $nlp->get_call('/status', NULL);
            $languages_enabled = [];
            $detected_lang = NULL;
            //@TODO Should cache this too. Or deprecate ::language for 0.5.0
            if ($capabilities
              && is_array($capabilities)
              && is_array($capabilities['web64']['endpoints'])
              && in_array('/fasttext', $capabilities['web64']['endpoints'])
            ) {
              $detected_lang = $nlp->fasttext($page_text);
              $detected_lang = is_array($detected_lang)
              && isset($detected_lang['language']) ? $detected_lang['language']
                : $detected_lang;
            }
            // Either Capabilities are not present for FastText of we had an issue.
            if ($detected_lang == NULL) {
              $detected_lang = $nlp->language($page_text) ?? NULL;
            }
            $cache_id = "strawberry_runners:postprocessor:"
              . $this->getPluginId();
            $cached = $this->cacheBackend->get($cache_id);
            if ($cached) {
              $languages_enabled = $cached->data;
            }
            else {
              if ($capabilities && is_array($capabilities)
                && isset($capabilities['polyglot_lang_models'])
                && is_array($capabilities['polyglot_lang_models'])
              ) {
                $languages_enabled = array_keys(
                  $capabilities['polyglot_lang_models']
                );
                $languages_enabled = array_map(
                  function ($languages_enabled) {
                    $parts = explode(':', $languages_enabled);
                    return $parts[1] ?? NULL;
                  }, $languages_enabled
                );
                $languages_enabled = array_filter($languages_enabled);
                $this->cacheBackend->set(
                  $cache_id, $languages_enabled,
                  CacheBackendInterface::CACHE_PERMANENT, [$cache_id]
                );
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
              // $nlp->spacy_entities($page_text, 'en_core_web_sm');
              $spacy = $nlp->spacy_entities($page_text, 'en');
              $output->searchapi['sentiment'] = $nlp->sentiment(
                $page_text, 'en'
              );
              $output->searchapi['sentiment'] = is_scalar(
                $output->searchapi['sentiment']
              ) ? $output->searchapi['sentiment'] : NULL;
              $output->searchapi['where'] = array_unique(
                ($spacy['GPE'] ?? []) + ($spacy['FAC'] ?? [])
              );
              $output->searchapi['who'] = array_unique(
                ($spacy['PERSON'] ?? []) + ($spacy['ORG'] ?? [])
              );
              $output->searchapi['metadata'] = array_unique(
                ($spacy['WORK_OF_ART'] ?? []) + ($spacy['EVENT'] ?? [])
              );
            }
            elseif ($config['nlp_method'] == 'polyglot') {
              if (in_array($detected_lang, $languages_enabled)) {
                $polyglot = $nlp->polyglot_entities($page_text, $detected_lang);
                $output->searchapi['where'] = $polyglot->getLocations();
                $output->searchapi['who'] = array_unique(
                  array_merge(
                    (array) $polyglot->getOrganizations(),
                    (array) $polyglot->getPersons()
                  )
                );
                $output->searchapi['sentiment'] = $polyglot->getSentiment();
                $entities_all = $polyglot->getEntities();
                if (!empty($entities_all) and is_array($entities_all)) {
                  $output->searchapi['metadata'] = $entities_all;
                }
              }
            }
            $output->searchapi['nlplang'] = [$detected_lang];
            //Clean UP based on Regular expression now
            // Common to all NLP
            //$data_to_test = ["Düsseldorf", "إسرائيل", "сейчас", "γνωρίζωἀπὸ","საერთა შორისო","---hola---", "'", "hola", "", "ሰማይ አይታረስ", "O'Higgins", "4th Of July"];
            foreach (['where', 'who', 'metadata'] as $nlp_key) {
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
        }
      }
      $output->searchapi['plaintext'] = $page_text;
      $output->searchapi['processlang'] = $file_languages;
      $output->searchapi['ts'] = date("c");
      $output->searchapi['label'] = $this->t("Sequence") . ' '
        . $sequence_number;
      $output->plugin['searchapi'] = $output->searchapi;
      $io->output = $output;
    }
    else {
      throw new \Exception("Invalid argument for Text processor");
    }
  }

  // Mime types that might contain JSON.
  // See https://github.com/tesseract-ocr/tessdoc/blob/main/InputFormats.md
  public function isJsonMime($mime_type): bool {
    $mime_types = [
      'application/json',
      'application/json+ld',
    ];
    return in_array($mime_type, $mime_types);
  }

  // Mime types that might contain Text.
  // See https://github.com/tesseract-ocr/tessdoc/blob/main/InputFormats.md
  public function isTextMime($mime_type): bool {
    $file_type_parts = explode('/', $mime_type);
    if (count($file_type_parts) == 2) {
      return $file_type_parts[0] == 'text' &&
        $file_type_parts[1] !== 'xml' &&
        $file_type_parts[1] !== 'html'
      ;
    }
    else {
      return FALSE;
    }
  }

  // Mime types supported as input to GS.
  // See https://github.com/tesseract-ocr/tessdoc/blob/main/InputFormats.md
  public function isXmlMime($mime_type): bool {
    $mime_types = [
      'application/xml',
      'text/xml',
    ];
    return in_array($mime_type, $mime_types);
  }

  /**
   * Determine whether the given value is a binary string. From Symfony DB debug.
   *
   * @param string $value
   *
   * @return bool
   */
  public function isBinary($value): bool {
    return !preg_match('//u', $value);
  }
}
