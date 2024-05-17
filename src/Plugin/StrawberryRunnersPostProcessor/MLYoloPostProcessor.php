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
    //@TODO we know yolov8 takes 640px. We can pass just that to make it faster.
    // But requires us to call info.json and pre-process the sizes.
    $arguments['iiif_image_url'] =   $config['iiif_server']."/{$iiifidentifier}/full/full/0/default.jpg";
    //@TODO we are not filtering here by label yet. Next release.
    $arguments['labels'] =   [];
    $page_text = NULL;
    $output->plugin = NULL;
    $labels = [];
    $ML = $nlpClient->get_call($config['ml_method'],  $arguments, 'en');
    $output->searchapi['vector_576'] = isset($ML['yolo']['vector']) && is_array($ML['yolo']['vector']) && count($ML['yolo']['vector'])== 576 ? $ML['yolo']['vector'] : NULL;
    if (isset($ML['yolo']['objects']) && is_array($ML['yolo']['objects']) && count($ML['yolo']['objects']) > 0 ) {
      $miniocr = $this->yolotToMiniOCR($ML['yolo']['objects'], $width, $height, $sequence_number);
      $output->searchapi['fulltext'] = $miniocr;
      $output->plugin = $miniocr;
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
    return $output;
  }


  protected function yolotToMiniOCR(array $objects, $width, $height, $pageid) {
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
}