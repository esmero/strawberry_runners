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
        'path_pdf2djvu' => '',
        'path_djvudump' => '',
        'path_djvu2hocr' => '',
        'arguments' => '',
        'arguments_tesseract' => '',
        'arguments_pdf2djvu' => '',
        'arguments_djvudump' => '',
        'arguments_djvu2hocr' => '',
        'output_type' => 'json',
        'output_destination' => 'searchapi',
        'processor_queue_type' => 'background',
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
      '#description' => t('Any arguments your binary requires to run. Use %file as replacement for the file that is output by the GS binary.'),
      '#required' => TRUE,
    ];

    $element['path_pdf2djvu'] = [
      '#type' => 'textfield',
      '#title' => $this->t('The system path to the pdf2djvu binary that will be executed by this processor.'),
      '#default_value' => $this->getConfiguration()['path_pdf2djvu'],
      '#description' => t('A full system path to the pdf2djvu binary present in the same environment your PHP runs, e.g  <em>/usr/bin/pdf2djvu</em>'),
      '#required' => TRUE,
    ];

    $element['arguments_pdf2djvu'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Any additional argument for your pdf2djvu binary.'),
      '#default_value' => !empty($this->getConfiguration()['arguments_pdf2djvu']) ? $this->getConfiguration()['arguments_pdf2djvu'] : '%file',
      '#description' => t('Any arguments your binary requires to run. Use %file as replacement for the file that is output by the pdf2djvu binary.'),
      '#required' => TRUE,
    ];

    $element['path_djvudump'] = [
      '#type' => 'textfield',
      '#title' => $this->t('The system path to the djvudump binary that will be executed by this processor.'),
      '#default_value' => $this->getConfiguration()['path_djvudump'],
      '#description' => t('A full system path to the djvudump binary present in the same environment your PHP runs, e.g  <em>/usr/bin/djvudump</em>'),
      '#required' => TRUE,
    ];

    $element['arguments_djvudump'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Any additional argument for your djvudump binary.'),
      '#default_value' => !empty($this->getConfiguration()['arguments_djvudump']) ? $this->getConfiguration()['arguments_djvudump'] : '%file',
      '#description' => t('Any arguments your binary requires to run. Use %file as replacement for the file that is output by the djvudump binary.'),
      '#required' => TRUE,
    ];

    $element['path_djvu2hocr'] = [
      '#type' => 'textfield',
      '#title' => $this->t('The system path to the djvu2hocr binary that will be executed by this processor.'),
      '#default_value' => $this->getConfiguration()['path_djvu2hocr'],
      '#description' => t('A full system path to the djvu2hocr binary present in the same environment your PHP runs, e.g  <em>/usr/bin/djvu2hocr</em>'),
      '#required' => TRUE,
    ];

    $element['arguments_djvu2hocr'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Any additional argument for your djvu2hocr binary.'),
      '#default_value' => !empty($this->getConfiguration()['arguments_djvu2hocr']) ? $this->getConfiguration()['arguments_djvu2hocr'] : '%file',
      '#description' => t('Any arguments your binary requires to run. Use %file as replacement for the file that is output by the djvu2hocr binary.'),
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
        'plugin' => 'As Input for another processor Plugin',
        'searchapi' => 'In a Search API Document using the Strawberryfield Flavor Data Source (e.g used for HOCR highlight)'
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

      //check if PDF is searchable: has DJVU file the layer TXTz?
      //pdf2djvu -q --no-metadata -p 2 -j0 -o page2.djv some_file.pdf && djvudump page2.djv |grep TXTz |wc -l
      //
      setlocale(LC_CTYPE, 'en_US.UTF-8');
      $execstring_checkSearchable = $this->buildExecutableCommand_checkSearchable($io);
      error_log($execstring_checkSearchable);
      if ($execstring_checkSearchable) {
        $backup_locale = setlocale(LC_CTYPE, '0');
        setlocale(LC_CTYPE, $backup_locale);
        // Support UTF-8 commands.
        // @see http://www.php.net/manual/en/function.shell-exec.php#85095
        shell_exec("LANG=en_US.utf-8");
        $proc_output_checkS = $this->proc_execute($execstring_checkSearchable, $timeout);
        if (is_null($proc_output_checkS)) {
          throw new \Exception("Could not execute {$execstring_checkSearchable} or timed out");
        }

        error_log($proc_output_checkS);

      }


      if ($proc_output_checkS == 1) {

        //if searchable run djvu2hocr
        //
        setlocale(LC_CTYPE, 'en_US.UTF-8');
        $execstring_djvu2hocr = $this->buildExecutableCommand_djvu2hocr($io);
        error_log($execstring_djvu2hocr);
        if ($execstring_djvu2hocr) {
          $backup_locale = setlocale(LC_CTYPE, '0');
          setlocale(LC_CTYPE, $backup_locale);
          // Support UTF-8 commands.
          // @see http://www.php.net/manual/en/function.shell-exec.php#85095
          shell_exec("LANG=en_US.utf-8");
          $proc_output = $this->proc_execute($execstring_djvu2hocr, $timeout);
          if (is_null($proc_output)) {
            throw new \Exception("Could not execute {$execstring_djvu2hocr} or timed out");
          }

          //djvu2hocr output uses ocrx_line while tesseract uses ocr_line
          //
          $proc_output_mod = str_replace('ocrx_line', 'ocr_line', $proc_output);

          $miniocr = $this->hOCRtoMiniOCR($proc_output_mod, $page_number);
          error_log($miniocr);
          $output = new \stdClass();
          $output->searchapi = $miniocr;
          $output->plugin = $miniocr;
          $io->output = $output;

        }

        //Do we have to remove djvu file?

      }
      else {

        //if not searchable run tesseract
        //
        setlocale(LC_CTYPE, 'en_US.UTF-8');
        $execstring = $this->buildExecutableCommand($io);
        error_log($execstring);
        if ($execstring) {
          $backup_locale = setlocale(LC_CTYPE, '0');
          setlocale(LC_CTYPE, $backup_locale);
          // Support UTF-8 commands.
          // @see http://www.php.net/manual/en/function.shell-exec.php#85095
          shell_exec("LANG=en_US.utf-8");
          $proc_output = $this->proc_execute($execstring, $timeout);
          if (is_null($proc_output)) {
            throw new \Exception("Could not execute {$execstring} or timed out");
          }

          $miniocr = $this->hOCRtoMiniOCR($proc_output, $page_number);
          error_log($miniocr);
          $output = new \stdClass();
          $output->searchapi = $miniocr;
          $output->plugin = $miniocr;
          $io->output = $output;

        }

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
      $titleparts =  explode(';', $page['title']);
      $pagetitle = NULL;
      foreach ($titleparts as $titlepart) {
        $titlepart = trim($titlepart);
        if (strpos($titlepart, 'bbox') === 0 ) {
          $pagetitle = substr($titlepart, 5);
        }
      }
      if ($pagetitle == NULL) {
        $miniocr->flush();
        error_log('Could not convert HOCR to MiniOCR, no valid page dimensions found');
        return NULL;
      }
      $coos = explode(" ", $pagetitle);
      // To avoid divisions by 0
      $pwidth = (float) $coos[2] ? (float) $coos[2] : 1;
      $pheight = (float) $coos[3] ? (float) $coos[3] : 1;
      // NOTE: floats are in the form of .1 so we need to remove the first 0.
      if (count($coos)) {
        $miniocr->startElement("p");
        $miniocr->writeAttribute("xml:id", 'sequence_'.$pageid);
        $miniocr->writeAttribute("wh", ltrim($pwidth, 0) . " " . ltrim($pheight, 0));
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
              $miniocr->writeAttribute("x", ltrim($l, '0') . ' ' . ltrim($t, 0) . ' ' . ltrim($w, 0) . ' ' . ltrim($h, 0));
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
  public function buildExecutableCommand_checkSearchable(\stdClass $io) {
    $input_property = $this->pluginDefinition['input_property'];
    $input_argument = $this->pluginDefinition['input_argument'];
    // Sets the default page to 1 if not passed.
    $file_path = isset($io->input->{$input_property}) ? $io->input->{$input_property} : NULL;
    $page_number = isset($io->input->{$input_argument}) ? (int) $io->input->{$input_argument} : 1;
    $config = $this->getConfiguration();
    $execpath_pdf2djvu = $config['path_pdf2djvu'];
    $arguments_pdf2djvu = $config['arguments_pdf2djvu'];
    $execpath_djvudump = $config['path_djvudump'];
    $arguments_djvudump = $config['arguments_djvudump'];

    if (empty($file_path)) {
      return NULL;
    }

    // This run function executes a 2 step function
    // First pdf2djvu -q --no-metadata -p 2 -j0 -o some_output_file.djv %file
    // Second djvudump some_output_file.djv |grep TXTz |wc -l

    $command = '';
    $can_run_pdf2djvu = \Drupal::service('strawberryfield.utility')
      ->verifyCommand($execpath_pdf2djvu);
    $can_run_djvudump = \Drupal::service('strawberryfield.utility')
      ->verifyCommand($execpath_djvudump);
    $filename = pathinfo($file_path, PATHINFO_FILENAME);
    $sourcefolder = pathinfo($file_path, PATHINFO_DIRNAME);
    $sourcefolder = strlen($sourcefolder) > 0 ? $sourcefolder . '/' : sys_get_temp_dir() . '/';
    $pdf2djvu_destination_filename = "{$sourcefolder}{$filename}_{$page_number}.djv";
    if ($can_run_pdf2djvu &&
      $can_run_djvudump &&
      (strpos($arguments_pdf2djvu, '%file') !== FALSE) &&
      (strpos($arguments_djvudump, '%file') !== FALSE)) {
      $arguments_pdf2djvu = "-q --no-metadata -j0 -p {$page_number} -o $pdf2djvu_destination_filename " . $arguments_pdf2djvu;
      $arguments_pdf2djvu = str_replace('%s', '', $arguments_pdf2djvu);
      $arguments_pdf2djvu = str_replace_first('%file', '%s', $arguments_pdf2djvu);
      $arguments_pdf2djvu = sprintf($arguments_pdf2djvu, $file_path);

      $arguments_djvudump = str_replace('%s', '', $arguments_djvudump);
      $arguments_djvudump = str_replace_first('%file', '%s', $arguments_djvudump);
      $arguments_djvudump = sprintf($arguments_djvudump, $pdf2djvu_destination_filename);

      $command_pdf2djvu = escapeshellcmd($execpath_pdf2djvu . ' ' . $arguments_pdf2djvu);
      $command_djvudump = escapeshellcmd($execpath_djvudump . ' ' . $arguments_djvudump);

      $command = $command_pdf2djvu . ' && ' . $command_djvudump . ' |grep TXTz |wc -l';

    }
    else {
      error_log("missing arguments for PDF2DJVU");
    }
    // Only return $command if it contains the original filepath somewhere
    if (strpos($command, $file_path) !== FALSE) {
      return $command;
    }
    return '';

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
  public function buildExecutableCommand_djvu2hocr(\stdClass $io) {
    $input_property = $this->pluginDefinition['input_property'];
    $input_argument = $this->pluginDefinition['input_argument'];
    // Sets the default page to 1 if not passed.
    $file_path = isset($io->input->{$input_property}) ? $io->input->{$input_property} : NULL;
    $page_number = isset($io->input->{$input_argument}) ? (int) $io->input->{$input_argument} : 1;
    $config = $this->getConfiguration();
    $execpath_djvu2hocr = $config['path_djvu2hocr'];
    $arguments_djvu2hocr = $config['arguments_djvu2hocr'];

    if (empty($file_path)) {
      return NULL;
    }

    // This run function executes a 1 step function
    // First djvu2hocr some_output_file.djv

    $command = '';
    $can_run_djvu2hocr = \Drupal::service('strawberryfield.utility')
      ->verifyCommand($execpath_djvu2hocr);
    $filename = pathinfo($file_path, PATHINFO_FILENAME);
    $sourcefolder = pathinfo($file_path, PATHINFO_DIRNAME);
    $sourcefolder = strlen($sourcefolder) > 0 ? $sourcefolder . '/' : sys_get_temp_dir() . '/';
    $pdf2djvu_destination_filename = "{$sourcefolder}{$filename}_{$page_number}.djv";
    if ($can_run_djvu2hocr &&
      (strpos($arguments_djvu2hocr, '%file') !== FALSE)) {

      $arguments_djvu2hocr = str_replace('%s', '', $arguments_djvu2hocr);
      $arguments_djvu2hocr = str_replace_first('%file', '%s', $arguments_djvu2hocr);
      $arguments_djvu2hocr = sprintf($arguments_djvu2hocr, $pdf2djvu_destination_filename);

      $command_djvu2hocr = escapeshellcmd($execpath_djvu2hocr . ' ' . $arguments_djvu2hocr);

      $command = $command_djvu2hocr;

    }
    else {
      error_log("missing arguments for djvu 2 OCR");
    }

    return $command;

  }

}
