<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 11/11/19
 * Time: 8:18 PM
 */

namespace Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessor;

use Drupal\Core\File\Exception\FileNotExistsException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginBase;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface;


/**
 *
 * System Binary Post processor Plugin Implementation
 *
 * @StrawberryRunnersPostProcessor(
 *    id = "binary",
 *    label = @Translation("Post processor that uses a System Binary to process
 *   files"), input_type = "entity:file", input_property = "filepath",
 *   input_argument = NULL
 * )
 */
class SystemBinaryPostProcessor extends StrawberryRunnersPostProcessorPluginBase {

  /**
   * Keeps track of a the expected output file in case there is any of course.
   *
   * @var string|null
   */
  protected $out_file_path = NULL;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'source_type' => 'asstructure',
        'mime_type' => ['application/pdf'],
        'path' => '',
        'arguments' => '',
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
      '#description' => $this->t('A single Mimetype type or a coma separed list of mimetypes that qualify to be Processed. Leave empty to apply any file'),
    ];
    $element['path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('The system path to the binary that will be executed by this processor.'),
      '#default_value' => $this->getConfiguration()['path'],
      '#description' => t('A full system path to a binary present in the same environment your PHP runs, e.g  <em>/usr/local/bin/exif</em>'),
      '#required' => TRUE,
    ];

    $element['arguments'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Any additional argument your executable binary requires.'),
      '#default_value' => !empty($this->getConfiguration()['arguments']) ? $this->getConfiguration()['arguments'] : '%file',
      '#description' => t('Any arguments your binary requires to run. Use %file as replacement for the file if the executable requires the filename to be passed under a specific argument. Use %outfile if the binary is intended to generate a new file and the output is going to be a file entity. If you know the extension please add it in the form of %outfile.extension'),
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
        'file' => 'A new file to be attached to the source ADO',
        'plugin' => 'As Input for another processor Plugin',
        'searchapi' => 'In a Search API Document using the Strawberryfield Flavor Data Source (e.g used for HOCR highlight)',
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
      '#size' => 3,
      '#maxlength' => 3,
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
   *    $io->input needs to contain property and the arguments if any
   *        \Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor::$input_property
   *        \Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor::$input_arguments
   *    $io->output will contain the result of the processor
   * @param string $context
   */
  public function run(\stdClass $io, $context = StrawberryRunnersPostProcessorPluginInterface::PROCESS) {
    // Specific input key as defined in the annotation
    // In this case it will contain an absolute Path to a File.
    // Needed since this executes locally on the server via SHELL.
    $input_property = $this->pluginDefinition['input_property'];
    $input_argument = $this->pluginDefinition['input_argument'];
    // NOT user here?
    $config = $this->getConfiguration();
    $output_type = $config['output_type'];
    $output_destination = $config['output_destination'];
    $timeout = $config['timeout']; // in seconds
    // TODO how do we map $input_argument to the callable executable binary?
    if (isset($io->input->{$input_property})) {
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
          throw new \Exception("Could not execute {$execstring} or timed out");
        }
        $output = new \stdClass();

        // If this should generate
        if (($output_type == 'entity:file') && in_array('file', $output_destination)) {

          if (!file_exists($this->out_file_path)) {
            throw new FileNotExistsException('The output file for this processor failed to be generated and was required');
          }
          $output->file = "temporary://" . substr($this->out_file_path, strlen($this->temporary_directory) + 1);
          $output->searchapi = NULL;
          $output->plugin = $this->out_file_path;
        }
        else {
          $output->file = NULL;
          $output->searchapi = $proc_output;
          $output->plugin = $proc_output;
        }
        $io->output = $output;


      }
    }
    else {
      throw new \InvalidArgumentException(\sprintf("Invalid arguments passed to %s", $this->getPluginId()));
    }
  }

  /**
   * Builds a clean Command string using a File path.
   *
   * @param \stdClass $io
   *
   * @return null|string
   */
  public function buildExecutableCommand(\stdClass $io) {
    $config = $this->getConfiguration();
    $execpath = $config['path'];
    $arguments = $config['arguments'];
    $output_type = $config['output_type'];
    $output_destination = $config['output_destination'];
    $command = '';
    $input_property = $this->pluginDefinition['input_property'];
    $input_argument = $this->pluginDefinition['input_argument'];

    // Sets the default page to 1 if not passed.
    $file_path = isset($io->input->{$input_property}) ? $io->input->{$input_property} : NULL;
    $out_file_path = NULL;
    //@ todo all this should be base methods, each method should check and validate what is needed
    // So one of the output types and output destinations can operate or not
    if (($file_path) && ($output_type == 'entity:file') && in_array('file', $output_destination)) {
      $extension = '';
      $pos = strpos(utf8_encode($arguments), utf8_encode('%outfile'));
      if ($pos === FALSE) {
        // No input, returning
        return NULL;
      }
      //Ok, let's try to get the an extension if there is one
      // will be 4 characters after
      $extension = substr($arguments, (int) $pos + 8, 5);
      $extension = trim($extension);
      $extension = (strpos($extension, '.') === 0) && strlen($extension) >= 4 ? $extension : '';

      $out_file_path = $this->temporary_directory . '/' . pathinfo($file_path, PATHINFO_FILENAME);
      $this->out_file_path = $out_file_path . $extension;
    }

    if (empty($file_path)) {
      return NULL;
    }

    if (\Drupal::service('strawberryfield.utility')
        ->verifyCommand($execpath) && (strpos($arguments, '%file') !== FALSE)) {

      $arguments = str_replace('%s', '', $arguments);
      $arguments = str_replace_first('%file', '%s', $arguments);
      $arguments = str_replace_first('%outfile', '%s', $arguments);
      if ($out_file_path) {
        // WE need the original one without extension here.
        $arguments = sprintf($arguments, $file_path, $out_file_path);
      }
      else {
        $arguments = sprintf($arguments, $file_path);
      }
      $command = escapeshellcmd($execpath . ' ' . $arguments);
    }
    // Only return $command if it contains the original filepath somewhere
    if (strpos($command, $file_path) !== FALSE) {
      return $command;
    }
    return '';
  }

}
