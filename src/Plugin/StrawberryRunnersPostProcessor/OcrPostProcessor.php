<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 11/11/19
 * Time: 8:18 PM
 */

namespace Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessor;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Web64\Nlp\NlpClient;


/**
 *
 * System Binary Post processor Plugin Implementation
 *
 * @StrawberryRunnersPostProcessor(
 *    id = "ocr",
 *    label = @Translation("Post processor that Runs OCR/HORC against files"),
 *    input_type = "entity:file",
 *    input_property = "filepath",
 *    input_argument = "sequence_number"
 * )
 */
class OcrPostProcessor extends SystemBinaryPostProcessor {

  public $pluginDefinition;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'source_type' => 'asstructure',
        'mime_type' => ['application/pdf'],
        'path' => '',
        'path_tesseract' => '',
        'path_pdfalto' => '',
        'arguments' => '',
        'arguments_tesseract' => '',
        'arguments_pdfalto' => '',
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
        'as:image' => 'as:image',
        'as:document' => 'as:document',
        'as:audio' => 'as:audio',
        'as:video' => 'as:video',
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
    $element['path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('The system path to the ghostscript (gs) binary that will be executed by this processor.'),
      '#default_value' => $this->getConfiguration()['path'],
      '#description' => t('A full system path to the gs binary present in the same environment your PHP runs, e.g  <em>/usr/bin/gs</em>'),
      '#required' => TRUE,
    ];

    $element['arguments'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Any additional argument your executable binary requires.'),
      '#default_value' => !empty($this->getConfiguration()['arguments']) ? $this->getConfiguration()['arguments'] : '%file',
      '#description' => t('Any arguments your binary requires to run. Use %file as replacement for the file if the executable requires the filename to be passed under a specific argument.'),
      '#required' => TRUE,
    ];

    $element['path_tesseract'] = [
      '#type' => 'textfield',
      '#title' => $this->t('The system path to the Tesseract binary that will be executed by this processor.'),
      '#default_value' => $this->getConfiguration()['path_tesseract'],
      '#description' => t('A full system path to the Tesseract binary present in the same environment your PHP runs, e.g  <em>/usr/bin/tesseract</em>'),
      '#required' => TRUE,
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
    $element['arguments_tesseract'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Any additional argument for your tesseract binary.'),
      '#default_value' => !empty($this->getConfiguration()['arguments_tesseract']) ? $this->getConfiguration()['arguments_tesseract'] : '%file',
      '#description' => t('Any arguments your binary requires to run. Use %file as replacement for the file that is output by the GS binary. Use %language as replacement for the chosen languange'),
      '#required' => TRUE,
    ];

    $element['datafolder_tesseract'] = [
      '#type' => 'textfield',
      '#title' => $this->t('The data/languages folder for Tesseract'),
      '#default_value' => !empty($this->getConfiguration()['datafolder_tesseract']) ? $this->getConfiguration()['datafolder_tesseract'] : '/usr/share/tessdata',
      '#description' => t('Absolute path where the Languages are stored in the Server. This will be used in --tessdata-dir'),
      '#required' => TRUE,
    ];

    $element['path_pdfalto'] = [
      '#type' => 'textfield',
      '#title' => $this->t('The system path to the pdfalto binary that will be executed by this processor.'),
      '#default_value' => $this->getConfiguration()['path_pdfalto'],
      '#description' => t('A full system path to the pdfalto binary present in the same environment your PHP runs, e.g  <em>/usr/local/bin/pdfalto</em>'),
      '#required' => FALSE,
    ];

    $element['arguments_pdfalto'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Any additional argument for your pdfalto binary.'),
      '#default_value' => !empty($this->getConfiguration()['arguments_pdfalto']) ? $this->getConfiguration()['arguments_pdfalto'] : '%file',
      '#description' => t('Any arguments your binary requires to run. Use %file as replacement for the file that is output by the pdfalto binary.'),
      '#required' => FALSE,
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
    $file_languages = isset($io->input->lang) ? (array) $io->input->lang : [$config['language_default'] ? trim($config['language_default']) : 'eng'];
    if (isset($io->input->{$input_property}) && $file_uuid && $node_uuid) {
      $output = new \stdClass();
      // To be used by miniOCR as id in the form of {nodeuuid}/canvas/{fileuuid}/p{pagenumber}
      $sequence_number = isset($io->input->{$input_argument}) ? (int) $io->input->{$input_argument} : 1;
      setlocale(LC_CTYPE, 'en_US.UTF-8');
      $execstring_pdfalto = $this->buildExecutableCommand_pdfalto($io);
      if ($execstring_pdfalto) {
          $backup_locale = setlocale(LC_CTYPE, '0');
          setlocale(LC_CTYPE, $backup_locale);
          // Support UTF-8 commands.
          // @see http://www.php.net/manual/en/function.shell-exec.php#85095
          shell_exec("LANG=en_US.utf-8");
          $proc_output = $this->proc_execute($execstring_pdfalto, $timeout);
          if (is_null($proc_output)) {
            $this->logger->warning("PDFALTO processing via {$execstring_pdfalto} timed out");
            throw new \Exception("Could not execute {$execstring_pdfalto} or timed out");
          }
          if (strpos($proc_output,"TextBlock")!== FALSE) {
            $miniocr = $this->ALTOtoMiniOCR($proc_output, $sequence_number);
            $output->searchapi['fulltext'] = $miniocr;
            $output->plugin = $miniocr;
            $io->output = $output;
        }
      }
      //if not searchable run tesseract
      if (!isset($output->plugin)) {
        setlocale(LC_CTYPE, 'en_US.UTF-8');
        $execstring = $this->buildExecutableCommand($io);
        if ($execstring) {
          $backup_locale = setlocale(LC_CTYPE, '0');
          setlocale(LC_CTYPE, $backup_locale);
          // Support UTF-8 commands.
          // @see http://www.php.net/manual/en/function.shell-exec.php#85095
          shell_exec("LANG=en_US.utf-8");
          $proc_output = $this->proc_execute($execstring, $timeout);
          if (is_null($proc_output)) {
            $this->logger->warning("OCR processing via {$execstring} timed out");
            throw new \Exception("Could not execute {$execstring} or timed out");
          }

          $miniocr = $this->hOCRtoMiniOCR($proc_output, $sequence_number);
          $output->searchapi['fulltext'] = $miniocr;
          $output->plugin = $miniocr;
        }
      }

      // Lastly plain text version of the XML
      $page_text = isset($output->searchapi['fulltext']) ? strip_tags(str_replace("<l>",
        PHP_EOL . "<l> ", $output->searchapi['fulltext'])) : '';
      $output->searchapi['metadata'] = [];
      // Check if NPL processing is enabled and if so do it.
      if ($config['nlp'] && !empty($config['nlp_url']) && strlen(trim($page_text)) > 0) {
        $nlp = new NlpClient($config['nlp_url']);
        if ($nlp) {
          $languages_enabled = [];
          $detected_lang = $nlp->language($page_text) ?? NULL;
          $cache_id = "strawberry_runners:postprocessor:".$this->getPluginId();
          $cached = $this->cacheBackend->get($cache_id);
          if ($cached) {
            $languages_enabled = $cached->data;
          }
          else {
            $capabilities = $nlp->get_call('/status', NULL);
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




            // $nlp->spacy_entities($page_text, 'en_core_web_sm');
            $spacy = $nlp->spacy_entities($page_text, 'en');
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
        }
      }
      $output->searchapi['plaintext'] = $page_text;
      $output->searchapi['processlang'] = $file_languages;
      $output->searchapi['ts'] = date("c");
      $output->searchapi['label'] = $this->t("Sequence") . ' ' . $sequence_number;
      $io->output = $output;
    }
    else {
      throw new \Exception("Invalid argument for OCR processor");
    }
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
    $input_property = $this->pluginDefinition['input_property'];
    $input_argument = $this->pluginDefinition['input_argument'];
    // Sets the default page to 1 if not passed.
    $file_path = isset($io->input->{$input_property}) ? $io->input->{$input_property} : NULL;
    $sequence_number = isset($io->input->{$input_argument}) ? (int) $io->input->{$input_argument} : 1;
    $config = $this->getConfiguration();
    $execpath_gs = $config['path'];
    $arguments_gs = $config['arguments'];
    $execpath_tesseract = $config['path_tesseract'];
    $arguments_tesseract = $config['arguments_tesseract'];
    $datafolder_tesseract = $config['datafolder_tesseract'];

    if (empty($file_path)) {
      return NULL;
    }
    $command = '';
    $can_run_gs = \Drupal::service('strawberryfield.utility')
      ->verifyCommand($execpath_gs);
    $can_run_tesseract = \Drupal::service('strawberryfield.utility')
      ->verifyCommand($execpath_tesseract);
    $filename = pathinfo($file_path, PATHINFO_FILENAME);
    $sourcefolder = pathinfo($file_path, PATHINFO_DIRNAME);
    $sourcefolder = strlen($sourcefolder) > 0 ? $sourcefolder . '/' : sys_get_temp_dir() . '/';
    $file_mime = $io->input->metadata["dr:mimetype"] ?? NULL;

    // If we can't run tesseract, or have no mime type, we can't do anything.
    if ($file_mime && $can_run_tesseract) {
      $commands = [];
      // Check if this is a pdf. If so, attempt to convert to a png using ghostscript.
      //-- with r300 == 300dpi, should be configurable, etc. All should be configurable
      // E.g. gs -dBATCH -dNOPAUSE -sDEVICE=pnggray -r300 -dUseCropBox -sOutputFile=somepage_pagenumber.png %file
      if ($can_run_gs && $this->isGsMimeType($file_mime) && (strpos($arguments_gs,
            '%file') !== FALSE)) {
        $tesseract_input_filename = "{$sourcefolder}{$filename}_{$sequence_number}.png";
        $arguments_gs = "-dBATCH -dNOPAUSE -r300 -dUseCropBox -dQUIET -sDEVICE=pnggray -dFirstPage={$sequence_number} -dLastPage={$sequence_number} -sOutputFile=$tesseract_input_filename " . $arguments_gs;
        $arguments_gs = str_replace('%s', '', $arguments_gs);
        $arguments_gs = $this->strReplaceFirst('%file', '%s', $arguments_gs);
        $arguments_gs = sprintf($arguments_gs, $file_path);
        $commands[] = escapeshellcmd($execpath_gs . ' ' . $arguments_gs);
      }

      elseif ($this->isTesseractMimeType($file_mime) && $can_run_tesseract && (strpos($arguments_tesseract,
            '%file') !== FALSE)) {
        // Run tesseract directly on the file.
        $tesseract_input_filename = $file_path;
      }

      if (!empty($tesseract_input_filename)) {
        if (strlen(trim($datafolder_tesseract))>0) {
          $arguments_tesseract = ' --tessdata-dir ' . $datafolder_tesseract . ' ' . $arguments_tesseract;
        }
        $arguments_tesseract = str_replace('%s', '', $arguments_tesseract);
        $arguments_tesseract = str_replace('%d', '', $arguments_tesseract);
        $arguments_tesseract = $this->strReplaceFirst('%file', '%s',
          $arguments_tesseract);
        $arguments_tesseract = $this->strReplaceFirst('%language', '%s',
          $arguments_tesseract);
        $file_languages = isset($io->input->lang) ? (array) $io->input->lang : ['eng'];
        $this->areTesseractLanguages($execpath_tesseract, $datafolder_tesseract, $file_languages);
        $file_languages_string = implode('+', $file_languages);

        $arguments_tesseract = sprintf($arguments_tesseract,
          $tesseract_input_filename, $file_languages_string);
        $commands[] = escapeshellcmd($execpath_tesseract . ' ' . $arguments_tesseract);
        $command = implode(' && ', $commands);
      }
    }
    // Only return $command if it contains the original filepath somewhere
    if (strpos($command, $file_path) !== FALSE) {
      return $command;
    }
    return NULL;
  }

  /**
   * Builds a clean PDF Alto Command string using a File path.
   *
   * @param \stdClass $io
   *    $io->input needs to contain
   *           \Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor::$input_property
   *           \Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor::$input_arguments
   *    $io->output will contain the result of the processor
   *
   * @return null|string
   */
  protected function buildExecutableCommand_pdfalto(\stdClass $io) {
    $input_property = $this->pluginDefinition['input_property'];
    $input_argument = $this->pluginDefinition['input_argument'];
    // Sets the default page to 1 if not passed.
    $file_path = isset($io->input->{$input_property}) ? $io->input->{$input_property} : NULL;
    $sequence_number = isset($io->input->{$input_argument}) ? (int) $io->input->{$input_argument} : 1;
    $config = $this->getConfiguration();
    $execpath_pdfalto = $config['path_pdfalto'];
    $arguments_pdfalto = $config['arguments_pdfalto'];
    $file_mime = $io->input->metadata["dr:mimetype"] ?? NULL;
    if (empty($file_path)) {
      return NULL;
    }
    // pdfalto -noLineNumbers -noImage -noImageInline -readingOrder -f 2 -l 2 %file -
    $command = '';
    $can_run_pdfalto = \Drupal::service('strawberryfield.utility')
      ->verifyCommand($execpath_pdfalto);
    if ($can_run_pdfalto &&
      (strpos($arguments_pdfalto,
          '%file') !== FALSE) && $this->isGsMimeType($file_mime)) {
      $arguments_pdfalto = "-noLineNumbers -noImage -noImageInline -readingOrder -f {$sequence_number} -l {$sequence_number} " . $arguments_pdfalto . " - ";
      $arguments_pdfalto = str_replace('%s', '', $arguments_pdfalto);
      $arguments_pdfalto = $this->strReplaceFirst('%file', '%s',
        $arguments_pdfalto);
      $arguments_pdfalto = sprintf($arguments_pdfalto, $file_path);
      $command_pdfalto = escapeshellcmd($execpath_pdfalto . ' ' . $arguments_pdfalto);
      $command = $command_pdfalto;
    }

    // Only return $command if it contains the original filepath somewhere
    if (strpos($command, $file_path) !== FALSE) {
      return $command;
    }
    return NULL;
  }

  protected function hOCRtoMiniOCR($output, $pageid) {
    $hocr = simplexml_load_string($output);
    $internalErrors = libxml_use_internal_errors(TRUE);
    libxml_clear_errors();
    libxml_use_internal_errors($internalErrors);
    if (!$hocr) {
      $this->logger->warning('Sorry for @pageid we could not decode/extract HOCR as XML',
        [
          '@pageid' => $pageid,
        ]);
      return NULL;
    }
    $miniocr = new \XMLWriter();
    $miniocr->openMemory();
    $miniocr->startDocument('1.0', 'UTF-8');
    $miniocr->startElement("ocr");
    $atleastone_word = FALSE;
    foreach ($hocr->body->children() as $page) {
      $titleparts = explode(';', $page['title']);
      $pagetitle = NULL;
      foreach ($titleparts as $titlepart) {
        $titlepart = trim($titlepart);
        if (strpos($titlepart, 'bbox') === 0) {
          $pagetitle = substr($titlepart, 5);
        }
      }
      if ($pagetitle == NULL) {
        $miniocr->flush();
        $this->logger->warning('Could not convert HOCR to MiniOCR for @pageid, no valid page dimensions found',
          [
            '@pageid' => $pageid,
          ]);
        return NULL;
      }
      $coos = explode(" ", $pagetitle);
      // To avoid divisions by 0
      $pwidth = (float) $coos[2] ? (float) $coos[2] : 1;
      $pheight = (float) $coos[3] ? (float) $coos[3] : 1;
      // NOTE: floats are in the form of .1 so we need to remove the first 0.
      if (count($coos)) {
        $miniocr->startElement("p");
        $miniocr->writeAttribute("xml:id", 'sequence_' . $pageid);
        $miniocr->writeAttribute("wh",
          ltrim($pwidth, 0) . " " . ltrim($pheight, 0));
        $miniocr->startElement("b");
        $page->registerXPathNamespace('ns', 'http://www.w3.org/1999/xhtml');
        foreach ($page->xpath('.//ns:span[@class="ocr_line"]') as $line) {
          $notFirstWord = FALSE;
          $miniocr->startElement("l");
          foreach ($line->children() as $word) {
            $wcoos = explode(" ", $word['title']);
            if (count($wcoos) >= 5) {
              $x0 = (float) $wcoos[1];
              $y0 = (float) $wcoos[2];
              $x1 = (float) $wcoos[3];
              $y1 = (float) $wcoos[4];
              $l = ltrim(sprintf('%.3f', ($x0 / $pwidth)), 0);
              $t = ltrim(sprintf('%.3f', ($y0 / $pheight)), 0);
              $w = ltrim(sprintf('%.3f', (($x1 - $x0) / $pwidth)), 0);
              $h = ltrim(sprintf('%.3f', (($y1 - $y0) / $pheight)), 0);
              $text = (string) $word;
              if ($notFirstWord) {
                $miniocr->text(' ');
              }
              $notFirstWord = TRUE;
              // New OCR Highlight does not like empty <w> tags at all
              if (strlen(trim($text)) > 0) {
                $miniocr->startElement("w");
                $miniocr->writeAttribute("x",
                  $l . ' ' . $t . ' ' . $w . ' ' . $h);
                $miniocr->text($text);
                // Only assume we have at least one word for <w> tags
                // Since lines? could end empty?
                $atleastone_word = TRUE;
                $miniocr->endElement();
              }
            }
          }
          $miniocr->endElement();
        }
        $miniocr->endElement();
        $miniocr->endElement();
      }
    }
    $miniocr->endElement();
    $miniocr->endDocument();
    unset($hocr);
    if ($atleastone_word) {
      return $miniocr->outputMemory(TRUE);
    }
    else {
      return StrawberryfieldFlavorDatasource::EMPTY_MINIOCR_XML;
    }
  }

  protected function ALTOtoMiniOCR($output, $pageid) {
    $alto = simplexml_load_string($output);
    $internalErrors = libxml_use_internal_errors(TRUE);
    libxml_clear_errors();
    libxml_use_internal_errors($internalErrors);

    $miniocr = new \XMLWriter();
    $miniocr->openMemory();
    $miniocr->startDocument('1.0', 'UTF-8');
    $miniocr->startElement("ocr");

    if (!$alto) {
      $this->logger->warning('Sorry for @pageid we could not decode/extract ALTO as XML',
        [
          '@pageid' => $pageid,
        ]);
      return NULL;
    }
    foreach ($alto->Layout->children() as $page) {
      $pageWidthPts = (float) $page['WIDTH'];
      $pageHeightPts = (float) $page['HEIGHT'];
      // To check if conversion is ok px = pts / 72 * 300 (dpi)
      //It seems that pdfalto output is in points while tesseract alto is in pixel
      $pageWidthPx = sprintf('%.0f', $pageWidthPts * 300 / 72);
      $pageHeightPx = sprintf('%.0f', $pageHeightPts * 300 / 72);
      $miniocr->startElement("p");
      $miniocr->writeAttribute("xml:id", 'sequence_' . $pageid);
      $miniocr->writeAttribute("wh", $pageWidthPx . " " . $pageHeightPx);

      $page->registerXPathNamespace('ns',
        'http://www.loc.gov/standards/alto/ns-v3#');
      foreach ($page->xpath('.//ns:TextBlock') as $block) {
        $miniocr->startElement("b");
        foreach ($block->children() as $line) {
          $miniocr->startElement("l");
          foreach ($line->children() as $child_name => $child_node) {
            if ($child_name == 'SP') {
              $miniocr->text(' ');
            }
            elseif ($child_name == 'String') {
              // ALTO <String ID="p1_w1" CONTENT="Senato" HPOS="74.6078" VPOS="58.3326" WIDTH="31.9943" HEIGHT="10.0627" STYLEREFS="font0" />
              //$miniocr->writeAttribute("x", $l . ' ' . $t . ' ' . $w . ' ' . $h);
              $hpos_rel = (float) $child_node['HPOS'] / $pageWidthPts;
              $vpos_rel = (float) $child_node['VPOS'] / $pageHeightPts;
              $width_rel = (float) $child_node['WIDTH'] / $pageWidthPts;
              $height_rel = (float) $child_node['HEIGHT'] / $pageHeightPts;

              $l = ltrim(sprintf('%.3f', $hpos_rel), 0);
              $t = ltrim(sprintf('%.3f', $vpos_rel), 0);
              $w = ltrim(sprintf('%.3f', $width_rel), 0);
              $h = ltrim(sprintf('%.3f', $height_rel), 0);

              $miniocr->startElement("w");
              $miniocr->writeAttribute("x",
                $l . ' ' . $t . ' ' . $w . ' ' . $h);
              $miniocr->text($child_node['CONTENT']);
              $miniocr->endElement();
            }
          }
          $miniocr->endElement();
        }
        $miniocr->endElement();
      }
      $miniocr->endElement();
    }
    $miniocr->endElement();
    $miniocr->endDocument();
    unset($alto);
    return $miniocr->outputMemory(TRUE);
  }

  // Mime types supported as input to Tesseract.
  // See https://github.com/tesseract-ocr/tessdoc/blob/main/InputFormats.md
  public function isTesseractMimeType($mime_type): bool {
    $tesseract_mime_types = [
      'image/png',
      'image/jpeg',
      'image/tiff',
      'image/jp2',
      'image/x-jp2',
      'image/gif',
      'image/webp',
      'image/bmp',
      'image/x-portable-anymap',
    ];
    return in_array($mime_type, $tesseract_mime_types);
  }

  // Mime types supported as input to GS.
  // See https://github.com/tesseract-ocr/tessdoc/blob/main/InputFormats.md
  public function isGsMimeType($mime_type): bool {
    $gs_mime_types = [
      'application/pdf',
      'application/x-pdf',
    ];
    return in_array($mime_type, $gs_mime_types);
  }

  protected function areTesseractLanguages($execpath_tesseract, $datafolder_tesseract, array $languages) {
    if (\Drupal::service('strawberryfield.utility')
      ->verifyCommand($execpath_tesseract)) {
      // --tessdata-dir /usr/share/tessdata

      if ($datafolder_tesseract && strlen(trim($datafolder_tesseract)) >0 ) {
        $execpath_tesseract = $execpath_tesseract . ' --tessdata-dir '. escapeshellarg( $datafolder_tesseract);
      }
      $execpath_tesseract = $execpath_tesseract .' --list-langs';
      $proc_output = NULL;
      $proc_return = TRUE;
      exec($execpath_tesseract, $proc_output, $proc_return);
      if (is_array($proc_output) and count($proc_output)> 1 && !$proc_return) {
        $proc_output = array_intersect($proc_output, $languages);
      }
      if (is_null($proc_output)) {
        return [];
      }
      else {
        return $proc_output;
      }
    }
    return [];
  }

}
