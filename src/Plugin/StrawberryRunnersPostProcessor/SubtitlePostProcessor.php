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
use Drupal\strawberry_runners\VTTLine;
use Drupal\strawberry_runners\VTTProcessor;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\strawberry_runners\Web64\Nlp\NlpClient;
use Laracasts\Transcriptions\Transcription;


/**
 *
 * Sub title (VTT) Plugin Implementation
 *
 * @StrawberryRunnersPostProcessor(
 *    id = "subtitle",
 *    label = @Translation("Post processor that extracts subtitles and generates time/space transmutated OCR"),
 *    input_type = "entity:file",
 *    input_property = "filepath",
 *    input_argument = NULL
 * )
 */
class SubtitlePostProcessor extends TextPostProcessor {

  public $pluginDefinition;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'source_type' => 'asstructure',
        'mime_type' => ['text/vtt'],
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
        'as:text' => 'as:text',
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
    // For subtitles we might have to use NLP in the actual Solr DOC for the language. The ADO might not be enough,
    // But still OK  to have as a fallback.
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
            $miniocr = $this->SubtitletoMiniOCR($text_content, $sequence_number);
            $page_text = $text_content;

            // Quick decision to be share with @alliomera. I won't match the VTT to the Audio or Video here
            // Let's deal with that at the discovery side. That way we can still use Highlights even IF
            // the how the user wants to match these is not clear.
            // For IIIF, we will let the manifest drive the need OR add an option at the Content Search Config
            // To match always (and there we can make the choice)
          }

          $output->searchapi['fulltext']
            = $miniocr ?? StrawberryfieldFlavorDatasource::EMPTY_MINIOCR_XML;
          $output->plugin = $text_content;
          $output->searchapi['plaintext'] = $page_text;
        }
        else {
          $this->logger->warning(
            "Subitlte Text processing was not possible because binary data was found"
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
      $io->output = $output;
    }
    else {
      throw new \Exception("Invalid argument for Text processor");
    }
  }

  protected function SubtitletoMiniOCR($text_content, $pageid) {
    $miniocr = new \XMLWriter();
    $miniocr->openMemory();
    $miniocr->startDocument('1.0', 'UTF-8');
    $miniocr->startElement("ocr");
    $atleastone_word = FALSE;
    $transcription = New VTTProcessor($text_content);

    // X is arbitrary // O - width
    // Y becomes time // Just because that is how a human would read.

    // let's get how much time we will cover.
    $last_line = NULL;
    if ($transcription->getMaxTime()) {

      // Rough refence is 250 words to a page, 30 minute == 3000/3600 words: So we will try to fit that.
      // How. Option 1. Measure accumulated time. 15 minutes per page (basically the diference between
      // How many lines can we fit in a single page? with a 12 pixel heigh per line
      $pageWidthPts = 2480;
      $pageHeightPts = round($transcription->getMaxTime() * StrawberryfieldFlavorDatasource::PIXELS_PER_SECOND); // 15 minutes = 3508 pixles
      $miniocr->startElement("p");
      $miniocr->writeAttribute("xml:id", 'timesequence_' . $pageid);
      $miniocr->writeAttribute("wh", $pageWidthPts . " " . $pageHeightPts);
      $miniocr->startElement("b"); // Testing with a single Block first
      /** @var VTTLine $line */
      foreach ($transcription as $line) {
        $miniocr->startElement("l");
        $hpos_rel = 0.010;
        $vpos_rel = (float)($line->getStarttime() * StrawberryfieldFlavorDatasource::PIXELS_PER_SECOND) / $pageHeightPts;
        $width_rel = 0.990;
        $height_rel = (float)(($line->getEndstime() - $line->getStarttime()) * StrawberryfieldFlavorDatasource::PIXELS_PER_SECOND) / $pageHeightPts;

        $l = ltrim(sprintf('%.3f', $hpos_rel) ?? '', 0);
        $t = ltrim(sprintf('%.3f', $vpos_rel) ?? '', 0);
        $w = ltrim(sprintf('%.3f', $width_rel) ?? '', 0);
        $h = ltrim(sprintf('%.3f', $height_rel) ?? '', 0);

        // New OCR Highlight > 0.71 does not like empty <w> tags at all
        if (strlen(trim($line->getBody() ?? "")) > 0) {
          $miniocr->startElement("w");
          $miniocr->writeAttribute("x",
            $l . ' ' . $t . ' ' . $w . ' ' . $h);
          $miniocr->text($line->getBody());
          // Only assume we have at least one word for <w> tags
          // Since lines? could end empty?
          $atleastone_word = TRUE;
          $miniocr->endElement();
        }
        $miniocr->endElement(); // Closes line
      }

      $miniocr->endElement(); // Closes "b"
      $miniocr->endElement(); // Closes "p""
    }
    $miniocr->endElement(); // Closes "ocr"
    $miniocr->endDocument();

    unset($transcription);
    if ($atleastone_word) {
      return $miniocr->outputMemory(TRUE);
    }
    else {
      unset($miniocr);
      return StrawberryfieldFlavorDatasource::EMPTY_MINIOCR_XML;
    }
  }
}
