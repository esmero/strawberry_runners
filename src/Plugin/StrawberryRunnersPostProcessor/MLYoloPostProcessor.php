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
 * ML YOLO
 *
 * @StrawberryRunnersPostProcessor(
 *    id = "ml_yolo",
 *    label = @Translation("Post processor that generates Object detection and Vector Embeddings using YOLO"),
 *    input_type = "entity:file",
 *    input_property = "filepath",
 *    input_argument = "sequence_number"
 * )
 */
class MLYoloPostProcessor extends abstractMLPostProcessor {

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
        'ml_method' => '/image/yolov8',
      ] + parent::defaultConfiguration();
  }

  public function settingsForm(array $parents, FormStateInterface $form_state) {
    $element = parent::settingsForm($parents, $form_state);
    return $element;
  }

  protected function runImageMLfromIIIF($io, NlpClient $nlpClient): \stdClass {
    $output = new \stdClass();
    $config = $this->getConfiguration();
    $input_argument = $this->pluginDefinition['input_argument'];
    $file_languages = isset($io->input->lang) ? (array) $io->input->lang : [$config['language_default'] ? trim($config['language_default'] ?? '') : 'eng'];
    // To be used by miniOCR as id in the form of {nodeuuid}/canvas/{fileuuid}/p{pagenumber}
    $sequence_number = isset($io->input->{$input_argument}) ? (int) $io->input->{$input_argument} : 1;
    setlocale(LC_CTYPE, 'en_US.UTF-8');
    $width = $io->input->metadata['flv:identify'][$io->input->{$input_argument}]['width'] ?? NULL;
    $height = $io->input->metadata['flv:identify'][$io->input->{$input_argument}]['height'] ?? NULL;
    if (!($width && $height)) {
      $width = $io->input->metadata['flv:exif']['ImageWidth'] ?? NULL;
      $height = $io->input->metadata['flv:exif']['ImageHeight'] ?? NULL;
    }
    $iiifidentifier = urlencode(
      StreamWrapperManager::getTarget( isset($io->input->metadata['url']) ? $io->input->metadata['url'] : NULL)
    );

    if ($iiifidentifier == NULL || empty($iiifidentifier)) {
      return $output;
    }
    $arguments['iiif_image_url'] =   $config['iiif_server']."/{$iiifidentifier}/full/full/0/default.jpg";
    $arguments['labels'] =   [];

    $ML = $nlpClient->get_call($config['ml_method'],  $arguments, 'en');
    $output->searchapi['vector_576'] = $ML['yolo']['vector'] ?? NULL;
    $output->searchapi['service_md5'] = isset($ML['yolo']['modelinfo']) ? md5(json_encode($ML['yolo']['modelinfo'])) : NULL;
    $output->searchapi['plaintext'] = '';
    $output->searchapi['processlang'] = $file_languages;
    $output->searchapi['ts'] = date("c");
    $output->searchapi['label'] = $this->t("ML Image Embeddings & Vectors") . ' ' . $sequence_number;
    return $output;
  }


}
