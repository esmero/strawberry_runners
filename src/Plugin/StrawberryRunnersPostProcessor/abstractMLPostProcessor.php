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
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginBase;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\strawberry_runners\Web64\Nlp\NlpClient;

abstract class abstractMLPostProcessor extends StrawberryRunnersPostProcessorPluginBase {

  public $pluginDefinition;

  protected $bb_margin = 50;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'source_type' => 'asstructure',
        'mime_type' => ['image/jpeg'],
        'output_type' => 'json',
        'output_destination' => 'searchapi',
        'processor_queue_type' => 'background',
        'language_key' => 'language_iso639_3',
        'language_default' => 'eng',
        'iif_server_image_type' => 'default.jpg',
        'timeout' => 300,
        'nlp_url' => 'http://esmero-nlp:6400',
        'ml_method' => NULL,
        'iiif_server' => '',
      ] + parent::defaultConfiguration();
  }

  public const ML_IMAGE_VECTOR_SIZE = [
    '/image/yolo' => 576,
    '/image/mobilenet' => 1024,
    '/image/insightface' => 512,
    '/image/vision_transformer' => 768,
  ];


  public const ML_IMAGE_INPUT_SIZE = [
    '/image/yolo' => 640,
    '/image/mobilenet' => 480,
    '/image/insightface' => 640,
    '/image/vision_transformer' => 224,
  ];

  public const ML_TEXT_VECTOR_SIZE = [
    '/text/sentence_transformer' => 384,
  ];

  protected $nlp_client = null;

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
        'ado' => 'ADO Strawberryfield JSON',
        'json' => 'JSON provided by another Processor'
      ],
      '#default_value' => $this->getConfiguration()['source_type'],
      '#description' => $this->t('Select from where the source data this processor needs is fetched'),
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
      '#title' => $this->t('The JSON key that contains the desired source.'),
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
    ];

    $element['jmespath'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Jmespath used to fetch/prefilter the metadata passed as JSON to the processor'),
      '#default_value' => (!empty($this->getConfiguration()['jmespath']) && is_array($this->getConfiguration()['jmespath'])) ? $this->getConfiguration()['jmespath'] : [],
      '#states' => [
        'visible' => [
          ':input[name="pluginconfig[source_type]"]' => ['value' => 'ado'],
        ],
      ],
    ];

    $element['mime_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mimetypes(s) to limit this Processor to.'),
      '#default_value' => $this->getConfiguration()['mime_type'],
      '#description' => $this->t('A single Mimetype type or a comma separated list of mimetypes that qualify to be Processed. Leave empty to apply any file'),
      '#states' => [
        'visible' => [
          ':input[name="pluginconfig[source_type]"]' => ['value' => 'asstructure'],
        ],
      ],
    ];

    $element['language_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Within the ADO's metadata, the JSON key that contains the language in ISO639-3 (3 letter)"),
      '#default_value' => (!empty($this->getConfiguration()['language_key'])) ? $this->getConfiguration()['language_key'] : '',
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
      '#description' => $this->t('ML processors only generate JSON'),
    ];

    $element['output_destination'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t("Where and how the output will be used."),
      '#options' => [
        'plugin' => 'As Input for another processor Plugin',
        'searchapi' => 'In a Search API Document using the Strawberryfield Flavor Data Source (e.g used for ML Vector Comparison)',
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
      '#required' => TRUE,
    ];

    $element['nlp_url'] = [
      '#type' => 'url',
      '#title' => $this->t("The URL location of your NLP64/ML server."),
      '#default_value' => $this->getConfiguration()['nlp_url'] ?? 'http://esmero-nlp:6400',
      '#description' => t('Defaults to http://esmero-nlp:6400'),
      '#required' => TRUE,
    ];

    $element['ml_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Which ML endpoint to use'),
      '#options' => [
        '/image/yolo' => 'YOLO (Image Object detection (as MiniOCR Annotations) & embedding as a Unit Length Vector)',
        '/image/mobilenet' => 'MobileNet (Image embeddings as a a Unit Length Vector)',
        '/text/sentence_transformer' => 'SBert Sentence Transformer (text embeddings as a Unit Length Vector)',
        '/image/insightface' => 'InsightFace (Detection as MiniOCR Annotations and embedding as a Unit Length Vector)',
      ],
      '#default_value' => $this->getConfiguration()['ml_method'],
      '#description' => $this->t('The ML endpoint/Model. Depending on the choice the actual value/size of data ingested will vary.'),
      '#required' => TRUE,
    ];

    $element['iiif_server'] = [
      '#type' => 'url',
      '#title' => $this->t('The IIIF Server to use for Image ML'),
      '#default_value' => $this->getConfiguration()['iiif_server'] ?: \Drupal::service('config.factory')
        ->get('format_strawberryfield.iiif_settings')
        ->get('int_server_url'),
      '#description' => $this->t('The IIIF Server to use. By default we will use the Internal (esmero-cantaloupe) endpoint'),
      '#required' => TRUE,
    ];

    $element['iiif_server_image_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('What type of IIIF Image API Quality to request'),
      '#options' => [
        'default.jpg' => 'The image is returned using the server’s default quality (e.g. color, gray or bitonal) for the image.',
        'gray.jpg' => 'The image is returned in grayscale, where each pixel is black, white or any shade of gray in between.',
        'bitonal.jpg' => 'The image returned is bitonal, where each pixel is either black or white.',
        'color.jpg' => 'The image is returned with all of its color information.',
      ],
      '#default_value' => $this->getConfiguration()['iiif_server_image_type'] ?? 'default.jpg',
      '#description' => $this->t('The quality parameter determines whether the IIIF image is delivered in color, grayscale or black and white. For certain ML models forcing grey might help skip a strongly opinionated vector component/feature.'),
      '#required' => TRUE,
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
    $file_uuid = isset($io->input->metadata['dr:uuid']) ? $io->input->metadata['dr:uuid'] : NULL;
    $node_uuid = isset($io->input->nuuid) ? $io->input->nuuid : NULL;

    $config = $this->getConfiguration();
    $timeout = $config['timeout']; // in seconds
    $output = new \stdClass();

    if (!empty($config['nlp_url']) && !empty($config['ml_method'])) {
      $nlp = $this->getNLPClient();
      if ($nlp) {
        $capabilities = $nlp->get_call('/status', NULL, 3);
        $languages_enabled = [];
        $detected_lang = NULL;
        //@TODO Should cache this too. Or deprecate ::language for 0.5.0
        if ($capabilities
          && is_array($capabilities)
          && is_array($capabilities['web64']['endpoints'])
          && in_array($config['ml_method'], $capabilities['web64']['endpoints'])) {
          if (in_array($config['source_type'], ['asstructure']) && isset($io->input->{$input_property}) && $file_uuid && $node_uuid) {
            $mloutput = $this->runImageMLfromIIIF($io, $nlp);
            $io->output = $mloutput ?? $output;
          }
          elseif (in_array($config['source_type'], ['ado', 'json']) && $node_uuid) {
            $mloutput = $this->runTextMLfromJSON($io, $nlp);
            $io->output = $mloutput ?? $output;
          }
          else {
            throw new \Exception("Invalid argument(s) for ML processor");
          }
        }
        else {
            throw new \Exception("Your NLP/ML endpoint does not provide ". $config['ml_method'] . ' capabilities');
          }
      }
      else {
        throw new \Exception("NLP/ML endpoint did not respond");
      }
    }
    else {
        throw new \Exception("Missing ML Configuration(s) for ML processor");
      }
  }

  abstract protected function runImageMLfromIIIF($io, NlpClient $nlpClient): \stdClass;

  abstract protected function runTextMLfromJSON($io, NlpClient $nlpClient) :\stdClass;

  // Mime types supported as input to Tesseract.
  // See https://github.com/tesseract-ocr/tessdoc/blob/main/InputFormats.md
  public function isImageMLMimeType($mime_type): bool {
    $image_ML_mime_types = [
      'image/png',
      'image/jpeg',
      'image/tiff',
      'image/jp2',
      'application/pdf',
    ];
    return in_array($mime_type, $image_ML_mime_types);
  }

  public function getVectorMLInfo() {
    $config = $this->getConfiguration();
    $info = [
      'nlp_url' => $config['nlp_url'],
      'ml_method' => $config['ml_method'],
      'iiif_server' => $config['iiif_server'],
    ];
  }

  abstract public function callImageML($image_url, $labels):mixed;
  abstract public function callTextML($text, $query):mixed;

  protected function getNLPClient() {
    if ($this->nlp_client) {
      return $this->nlp_client;
    }
    else {
      $config = $this->getConfiguration();
      $nlp = new NlpClient($config['nlp_url']);
      $this->nlp_client = $nlp;
      return $this->nlp_client;
    }
  }



}
