<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 11/11/19
 * Time: 8:18 PM
 */

namespace Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessor;

use Drupal\Core\Form\FormStateInterface;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessor\SystemBinaryPostProcessor;
use Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginBase;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface;


/**
 *
 * System Binary Post processor Plugin Implementation
 *
 * @StrawberryRunnersPostProcessor(
 *    id = "ocr",
 *    label = @Translation("Post processor that Runs OCR/HORC against files"),
 *    input_type = "entity:file",
 *    input_property = "filepath",
 *    input_argument = "page_number"
 * )
 */
class OcrPostProcessor extends SystemBinaryPostProcessor {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'source_type' => 'asstructure',
        'mime_type' => ['application/pdf'],
        'path' => '',
        'path_tesseract' => '',
        'arguments' => '',
        'arguments_tesseract' => '',
        'output_type' => 'json',
        'output_destination' => 'subkey',
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
      '#required' => TRUE
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
      '#description' => $this->t('A single Mimetype type or a coma separed list of mimetypes that qualify to be Processed. Leave empty to apply any file'),
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

    $element['arguments_tesseract'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Any additional argument for your tesseract binary.'),
      '#default_value' => !empty($this->getConfiguration()['arguments_tesseract']) ? $this->getConfiguration()['arguments_tesseract'] : '%file',
      '#description' => t('Any arguments your binary requires to run. Use %file as replacement for the file that is output but the GS binary.'),
      '#required' => TRUE,
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
        'subkey' => 'In the same Source Metadata, as a child structure of each Processed file',
        'ownkey' => 'In the same Source Metadata but inside its own, top level, "as:flavour" subkey based on the given machine name of the current plugin',
        'plugin' => 'As Input for another processor Plugin',
      ],
      '#default_value' => (!empty($this->getConfiguration()['output_destination']) && is_array($this->getConfiguration()['output_destination'])) ? $this->getConfiguration()['output_destination'] : [],
      '#description' => t('As Input for another processor Plugin will only have an effect if another Processor is setup to consume this ouput.'),
      '#required' => TRUE,
    ];

    $element['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout in seconds for this process.'),
      '#default_value' => $this->getConfiguration()['timeout'],
      '#description' => $this->t('If the process runs out of time it can still be processed again.'),
      '#size' => 2,
      '#maxlength' => 2,
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
    error_log('run OCR');

    if (isset($io->input->{$input_property}) && $file_uuid && $node_uuid) {
      // To be used by miniOCR as id in the form of {nodeuuid}/canvas/{fileuuid}/p{pagenumber}
      $page_number = isset($io->input->{$input_argument}) ? (int) $io->input->{$input_argument} : 1;
      $pageid = $node_uuid . '/canvas/' . $file_uuid . '/p' . $page_number;
      setlocale(LC_CTYPE, 'en_US.UTF-8');
      $execstring = $this->buildExecutableCommand($io);
      error_log($execstring);
      if ($execstring) {
        $backup_locale = setlocale(LC_CTYPE, '0');
        setlocale(LC_CTYPE, $backup_locale);
        // Support UTF-8 commands.
        // @see http://www.php.net/manual/en/function.shell-exec.php#85095
        shell_exec("LANG=en_US.utf-8");
        $output = $this->proc_execute($execstring, $timeout);
        if (is_null($output)) {
          throw new \Exception("Could not execute {$execstring} or timed out");
        }

        $miniocr = $this->hOCRtoMiniOCR($output, $pageid);
        error_log($miniocr);
        $io->output = $miniocr;
      }
    }
    else {
      \throwException(new \InvalidArgumentException);
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
    $page_number = isset($io->input->{$input_argument}) ? (int) $io->input->{$input_argument} : 1;
    $config = $this->getConfiguration();
    $execpath_gs = $config['path'];
    $arguments_gs = $config['arguments'];
    $execpath_tesseract = $config['path_tesseract'];
    $arguments_tesseract = $config['arguments_tesseract'];

    if (empty($file_path)) {
      return NULL;
    }

    // This run function executes a 2 step function
    //-- with r300 == 300dpi, should be configurable, etc. All should be configurable
    // First gs -dBATCH -dNOPAUSE -sDEVICE=pnggray -r300 -dUseCropBox -sOutputFile=somepage_pagenumber.png %file

    $command = '';
    $can_run_gs = \Drupal::service('strawberryfield.utility')
      ->verifyCommand($execpath_gs);
    $can_run_tesseract = \Drupal::service('strawberryfield.utility')
      ->verifyCommand($execpath_tesseract);
    $filename = pathinfo($file_path, PATHINFO_FILENAME);
    $sourcefolder = pathinfo($file_path, PATHINFO_DIRNAME);
    $sourcefolder = strlen($sourcefolder) > 0 ? $sourcefolder . '/' : sys_get_temp_dir() . '/';
    $gs_destination_filename = "{$sourcefolder}{$filename}_{$page_number}.png";
    if ($can_run_gs &&
      $can_run_tesseract &&
      (strpos($arguments_gs, '%file') !== FALSE) &&
      (strpos($arguments_tesseract, '%file') !== FALSE)) {
      $arguments_gs = "-dBATCH -dNOPAUSE -r300 -dUseCropBox -dQUIET -sDEVICE=pnggray -dFirstPage={$page_number} -dLastPage={$page_number} -sOutputFile=$gs_destination_filename " . $arguments_gs;
      $arguments_gs = str_replace('%s', '', $arguments_gs);
      $arguments_gs = str_replace_first('%file', '%s', $arguments_gs);
      $arguments_gs = sprintf($arguments_gs, $file_path);

      $arguments_tesseract = str_replace('%s', '', $arguments_tesseract);
      $arguments_tesseract = str_replace_first('%file', '%s', $arguments_tesseract);
      $arguments_tesseract = sprintf($arguments_tesseract, $gs_destination_filename);

      $command_gs = escapeshellcmd($execpath_gs . ' ' . $arguments_gs);
      $command_tesseract = escapeshellcmd($execpath_tesseract . ' ' . $arguments_tesseract);

      $command = $command_gs . ' && ' . $command_tesseract;

    }
    else {
      error_log("missing arguments for OCR");
    }
    // Only return $command if it contains the original filepath somewhere
    if (strpos($command, $file_path) !== FALSE) {
      return $command;
    }
    return '';

  }

  protected function hOCRtoMiniOCR($output, $pageid) {
    error_log($output);
    $hocr = simplexml_load_string($output);
    $internalErrors = libxml_use_internal_errors(TRUE);
    libxml_clear_errors();
    libxml_use_internal_errors($internalErrors);
    if (!$hocr) {
      error_log('Could not convert HOCR to MiniOCR, sources is not valid XML');
      return NULL;
    }
    $miniocr = new \XMLWriter();
    $miniocr->openMemory();
    $miniocr->startDocument('1.0', 'UTF-8');
    $miniocr->startElement("ocr");
    foreach ($hocr->body->children() as $page) {
      $coos = explode(" ", substr($page['title'], 5));
      // To avoid divisions by 0
      $pwidth = (float) $coos[2] ? (float) $coos[2] : 1;
      $pheight = (float) $coos[3] ? (float) $coos[3] : 1;
      if (count($coos)) {
        $miniocr->startElement("p");
        $miniocr->writeAttribute("xml:id", $pageid);
        $miniocr->writeAttribute("wh", $pwidth . " " . $pheight);
        $miniocr->startElement("b");
        $page->registerXPathNamespace('ns', 'http://www.w3.org/1999/xhtml');
        foreach ($page->xpath('.//ns:span[@class="ocr_line"]') as $line) {
          $miniocr->startElement("l");
          foreach ($line->children() as $word) {
            $wcoos = explode(" ", $word['title']);
            if (count($wcoos)) {
              $x0 = (float) $wcoos[1];
              $y0 = (float) $wcoos[2];
              $x1 = (float) $wcoos[3];
              $y1 = (float) $wcoos[4];
              $l = round(($x0 / $pwidth), 3);
              $t = round(($y0 / $pheight), 3);
              $w = round((($x1 - $x0) / $pwidth), 3);
              $h = round((($y1 - $y0) / $pheight), 3);
              $text = (string) $word;
              $miniocr->startElement("w");
              $miniocr->writeAttribute("x", $l . ' ' . $t . ' ' . $w . ' ' . $h);

              $miniocr->text($text);
              $miniocr->endElement();
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

    return $miniocr->outputMemory(TRUE);
  }

}




