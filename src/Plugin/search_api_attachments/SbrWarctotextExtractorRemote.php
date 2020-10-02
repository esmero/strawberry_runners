<?php

namespace Drupal\strawberry_runners\Plugin\search_api_attachments;
use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api_attachments\TextExtractorPluginBase;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystemInterface;
use Mixnode\WarcReader;

/**
 * Provides pdftotext extractor.
 *
 * @SearchApiAttachmentsTextExtractor(
 *   id = "sbr_warctotext_extractor",
 *   label = @Translation("Strawberry Runners Warctotext Extractor with Remote Support"),
 *   description = @Translation("Adds Warctotext extractor support to yourt Search Index with Remote file capabilities."),
 * )
 */
class SbrWarctotextExtractorRemote extends TextExtractorPluginBase {
   /**
   * Extract file with Pdftotext command line tool.
   *
   * @param \Drupal\file\Entity\File $file
   *   A file object.
   *
   * @return string
   *   The text extracted from the file.
   */
  public function extract(File $file) {
    if (in_array($file->getMimeType(), ['application/x-gzip'])) {
      $output = '';
      $warc_path = $this->configuration['warc_path_external'];
      // If the file isn't stored locally make a temporary copy.
     $uri = $file->getFileUri();
     $filepath = $this->getRealpath($file->getFileUri());

      // Local stream.
      $cache_key = md5($uri);
      // Check first if the file is already around in temp?
      // @TODO can be sure its the same one? Ideas?
      if (is_readable(\Drupal::service('file_system')->realpath('temporary://sbr_' . $cache_key . '_' . basename($uri)))) {
        $templocation = \Drupal::service('file_system')->realpath('temporary://sbr_' . $cache_key . '_' . basename($uri));
      }
      else {
        $templocation =  \Drupal::service('file_system')->copy(
          $uri,
          'temporary://sbr_' . $cache_key . '_' . basename($uri),
          FileSystemInterface::EXISTS_REPLACE
        );
        $templocation =  \Drupal::service('file_system')->realpath(
          $templocation
        );
      }
    

    if (!$templocation) {
      $this->loggerFactory->get('PdftotextExtractorRemote')->warning(
        'Could not adquire a local accessible location for text extraction for file with URL @fileurl',
        [
          '@fileurl' => $file->getFileUri(),
        ]
      );
     return;
    }

      // the default C-locale.
      // So temporarily set the locale to UTF-8 so that the filepath remains
      // valid.
      $backup_locale = setlocale(LC_CTYPE, '0');
      setlocale(LC_CTYPE, 'en_US.UTF-8');

      $warc_reader = new WarcReader(escapeshellarg($templocation));
      // Using nextRecord, iterate through the WARC file and output each record.
      while(($record = $warc_reader->nextRecord()) != FALSE){
        // A WARC record is broken into two parts: header and content.
        // header contains metadata about content, while content is the actual resource captured.
        dpm($record['header']);
        dpm($record['content']);
        $output[] = $record['content'];
        echo "------------------------------------\n";
      }
      $output = implode(';',$output);
      //$output = shell_exec($cmd);
      if (is_null($output)) {
        throw new \Exception('We could not extract.');
      }
      return $output;
      
    }
    else {
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['warc_path_external'] = [
      '#type' => 'textfield',
      '#title' => $this->t('warc-extractor.py binary for external processor (Python 3)'),
      '#description' => $this->t('Enter the name of warc-extractor.py python script or the full path. Example: "warc-extractor.py" or "/usr/bin/warc-extractor.py".'),
      '#default_value' => $this->configuration['warc_path_external'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValue(['text_extractor_config']);
    $warc_path = $values['warc_path_external'];

    $is_name = strpos($warc_path, '/') === FALSE && strpos($warc_path, '\\') === FALSE;
    if (!$is_name && !file_exists($warc_path)) {
      $form_state->setError($form['text_extractor_config']['warc_path_external'], $this->t('The file %path does not exist.', ['%path' => $warc_path]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['pdftowarc_path_external'] = $form_state->getValue([
      'text_extractor_config',
      'pdftowarc_path_external',
    ]);
    parent::submitConfigurationForm($form, $form_state);
  }

}
