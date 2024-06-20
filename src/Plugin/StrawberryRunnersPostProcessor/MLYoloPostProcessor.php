<?php
/**
* Created by PhpStorm.
 * User: dpino
* Date: 05/06/24
* Time: 11:32AM
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
    $element['source_type'] = [
      '#type' => 'select',
      '#title' => $this->t('The type of source data this processor works on'),
      '#options' => [
        'asstructure' => 'File entities referenced in the as:filetype JSON structure',
      ],
      '#default_value' => $this->getConfiguration()['source_type'],
      '#description' => $this->t('Select from where the source data this processor needs is fetched'),
      '#required' => TRUE,
    ];
    $element['ml_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('ML endpoint to use (fixed)'),
      '#options' => [
        '/image/yolo' => 'YOLO (Image Object detection (as MiniOCR Annotations) & embedding as a Unit Length Vector)',
      ],
      '#default_value' => $this->getConfiguration()['ml_method'],
      '#description' => $this->t('The ML endpoint/Model. This is fixed for this processor.'),
      '#required' => TRUE,
    ];
    // Only Images for now.
    $element['jsonkey']['#options'] = [ 'as:image' => 'as:image'];
    return $element;
  }

  protected function runTextMLfromJSON($io, NlpClient $nlpClient): \stdClass {
    $output = new \stdClass();
    return $output;
    // TODO: Implement runTextMLfromJSON() method.
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
    //@TODO we know yolov8 takes 640px. We can pass just that to make it faster.
    // But requires us to call info.json and pre-process the sizes.
    $iiif_image_url =  $config['iiif_server']."/{$iiifidentifier}/full/full/0/default.jpg";
    //@TODO we are not filtering here by label yet. Next release.
    $labels = [];
    $page_text = NULL;
    $output->plugin = NULL;
    $labels = [];
    $ML = $this->callImageML($iiif_image_url,$labels);
    $output->searchapi['vector_576'] = isset($ML['yolo']['vector']) && is_array($ML['yolo']['vector']) && count($ML['yolo']['vector'])== 576 ? $ML['yolo']['vector'] : NULL;
    if (isset($ML['yolo']['objects']) && is_array($ML['yolo']['objects']) && count($ML['yolo']['objects']) > 0 ) {
      $miniocr = $this->yoloToMiniOCR($ML['yolo']['objects'], $width, $height, $sequence_number);
      $output->searchapi['fulltext'] = $miniocr;
      $page_text = isset($output->searchapi['fulltext']) ? strip_tags(str_replace("<l>",
        PHP_EOL . "<l> ", $output->searchapi['fulltext'])) : '';
      // What is a good confidence ratio here?
      // based on the % of the bounding box?
      // Just the value?
      foreach($ML['yolo']['objects'] as $object) {
        $labels[$object['name']] =  $object['name'];
      }
    }
    $output->searchapi['metadata'] = $labels;
    $output->searchapi['service_md5'] = isset($ML['yolo']['modelinfo']) ? md5(json_encode($ML['yolo']['modelinfo'])) : NULL;
    $output->searchapi['plaintext'] = $page_text ?? '';
    $output->searchapi['processlang'] = $file_languages;
    $output->searchapi['ts'] = date("c");
    $output->searchapi['label'] = $this->t("ML Image Embeddings & Vectors") . ' ' . $sequence_number;
    $output->plugin['searchapi'] = $output->searchapi;
    return $output;
  }


  protected function yoloToMiniOCR(array $objects, $width, $height, $pageid) {
    $miniocr = new \XMLWriter();
    $miniocr->openMemory();
    $miniocr->startDocument('1.0', 'UTF-8');
    $miniocr->startElement("ocr");
    $atleastone_word = FALSE;
    // To avoid divisions by 0
    $pwidth = (float) $width;
    $pheight = (float) $height;
    // NOTE: floats are in the form of .1 so we need to remove the first 0.
    $miniocr->startElement("p");
    $miniocr->writeAttribute("xml:id", 'ml_yolo_' . $pageid);
    $miniocr->writeAttribute("wh",
      ltrim($pwidth ?? '', 0) . " " . ltrim($pheight ?? '', 0));
    $miniocr->startElement("b");
    foreach ($objects as $object) {
      $notFirstWord = FALSE;
      $miniocr->startElement("l");
      $x0 = (float) $object['box']['x1'];
      $y0 = (float) $object['box']['y1'];
      $x1 = (float) $object['box']['x2'];
      $y1 = (float) $object['box']['y2'];
      $l = ltrim(sprintf('%.3f', $x0)  ?? '', 0);
      $t = ltrim(sprintf('%.3f', $y0) ?? '', 0);
      $w = ltrim(sprintf('%.3f', ($x1 - $x0)) ?? '', 0);
      $h = ltrim(sprintf('%.3f', ($y1 - $y0)) ?? '', 0);
      $text = (string) ($object['name'] ?? 'Unlabeled') .' ~ '. (string) ("{$object['confidence']}" ?? "0");
      if ($notFirstWord) {
        $miniocr->text(' ');
      }
      $notFirstWord = TRUE;
      // New OCR Highlight does not like empty <w> tags at all
      if (strlen(trim($text ?? '')) > 0) {
        $miniocr->startElement("w");
        $miniocr->writeAttribute("x",
          $l . ' ' . $t . ' ' . $w . ' ' . $h);
        $miniocr->text($text);
        // Only assume we have at least one word for <w> tags
        // Since lines? could end empty?
        $atleastone_word = TRUE;
        $miniocr->endElement();
      }
      $miniocr->endElement();
    }
    $miniocr->endElement();
    $miniocr->endElement();
    $miniocr->endElement();
    $miniocr->endDocument();
    if ($atleastone_word) {
      return $miniocr->outputMemory(TRUE);
    }
    else {
      return StrawberryfieldFlavorDatasource::EMPTY_MINIOCR_XML;
    }
  }

  public function callImageML($image_url, $labels):mixed {
    $nlpClient = $this->getNLPClient();
    $config = $this->getConfiguration();
    $arguments['iiif_image_url'] =  $image_url;
    //@TODO we are not filtering here by label yet. Next release.
    $arguments['labels'] = $labels;
    $ML = $nlpClient->get_call($config['ml_method'],  $arguments, 1);
    return $ML;
  }

  public function callTextML($text, $query):mixed {
    return FALSE;
  }

}
