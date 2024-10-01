<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 05/24/24
 * Time: 1:00 AM
 */

namespace Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessor;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface;
use Drupal\strawberry_runners\VTTLine;
use Drupal\strawberry_runners\VTTProcessor;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\strawberry_runners\Web64\Nlp\NlpClient;
use Laracasts\Transcriptions\Transcription;

/**
 *
 * ML Sentence Transformer
 *
 * @StrawberryRunnersPostProcessor(
 *    id = "ml_sentence_transformer",
 *    label = @Translation("Post processor that generates Vector Embeddings using Sentence Transformers"),
 *    input_property = "searchapi",
 *    input_type = "json",
 *    output_type = "json",
 *    input_argument = "sequence_number"
 * )
 */
class MLSentenceTransformertPostProcessor extends abstractMLPostProcessor {

  public $pluginDefinition;

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
        'timeout' => 300,
        'nlp_url' => 'http://esmero-nlp:6400',
        'ml_method' => '/text/sentence_transformer',
      ] + parent::defaultConfiguration();
  }

  public function settingsForm(array $parents, FormStateInterface $form_state) {
    $element = parent::settingsForm($parents, $form_state);
    $element['source_type'] = [
      '#type' => 'select',
      '#title' => $this->t('The type of source data this processor works on'),
      '#options' => [
        'json' => 'JSON passed by a parent Processor.This processor needs to be chained to another one that generates Text. e.g OCR.',
      ],
      '#default_value' => $this->getConfiguration()['source_type'],
      '#description' => $this->t('Select from where the source file  this processor needs is fetched'),
      '#required' => TRUE,
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
      '#description' => t('As Input for another processor Plugin will only have an effect if another Processor is setup to consume this output. This plugin always generates also search API output data.'),
      '#required' => TRUE,
    ];
    $element['ml_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('ML endpoint to use (fixed)'),
      '#options' => [
        '/text/sentence_transformer' => 'SBert Sentence Transformer (text embeddings as a Unit Length Vector)',
      ],
      '#default_value' => $this->getConfiguration()['ml_method'],
      '#description' => $this->t('The ML endpoint/Model. This is fixed for this processor.'),
      '#required' => TRUE,
    ];

    return $element;
  }

  protected function runTextMLfromJSON($io, NlpClient $nlpClient): \stdClass
  {
    $output = new \stdClass();
    $config = $this->getConfiguration();

    $input_argument = $this->pluginDefinition['input_argument'];
    $input_property = $this->pluginDefinition['input_property'];

    $file_languages = isset($io->input->lang) ? (array)$io->input->lang : [$config['language_default'] ? trim($config['language_default'] ?? '') : 'eng'];
    $sequence_number = isset($io->input->{$input_argument}) ? (int)$io->input->{$input_argument} : 1;

    setlocale(LC_CTYPE, 'en_US.UTF-8');
    if (isset($io->input->{$input_property})) {
      // depending on the sources of $io->input->{$input_property}.
      // If generated/enqueued directly by a parent or recycled from pre-generated data found at the SBflavor storage
      // this might be either an object or an array.
      // So we are going to normalize here
      $input_normalized = (object) $io->input->{$input_property};
      $page_text = $input_normalized->plaintext ?? NULL;
      if ($page_text) {
        $labels = [];
        $output->plugin = NULL;
        $labels = [];
        $ML = $this->callTextML($page_text, false);
        $output->searchapi['vector_384'] = isset($ML['sentence_transformer']['vector']) && is_array($ML['sentence_transformer']['vector']) && count($ML['sentence_transformer']['vector']) == 384 ? $ML['sentence_transformer']['vector'] : NULL;
        $output->searchapi['metadata'] = $input_normalized->metadata ?? [];
        $output->searchapi['service_md5'] = isset($ML['mobilenet']['modelinfo']) ? md5(json_encode($ML['mobilenet']['modelinfo'])) : NULL;
        $output->searchapi['plaintext'] = $page_text ?? '';
        $output->searchapi['fulltext'] = $input_normalized->fulltext ?? [];
        $output->searchapi['processlang'] = $file_languages;
        $output->searchapi['ts'] = date("c");
        $output->searchapi['label'] = $this->t("Sentence Transformer ML Text Embeddings & Vectors") . ' ' . $sequence_number;
        $output->plugin['searchapi'] = $output->searchapi;
      }
    }
    return $output;
  }

  public function callImageML($image_url, $labels):mixed {
   return FALSE;
  }

  public function callTextML($text, $query = TRUE):mixed {
    $nlpClient = $this->getNLPClient();
    $config = $this->getConfiguration();
    $arguments['text'] =  $text;
    if ($query) {
      $arguments['query'] =  TRUE;
    }
    //@TODO we are not filtering here by label yet. Next release.
    $ML = $nlpClient->get_call($config['ml_method'],  $arguments, 1);
    return $ML;
  }

  protected function runImageMLfromIIIF($io, NlpClient $nlpClient): \stdClass
  {
    $output = new \stdClass();
    return $output;
  }

}
