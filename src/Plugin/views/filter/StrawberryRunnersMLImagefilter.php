<?php

namespace Drupal\strawberry_runners\Plugin\views\filter;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\TypedData\FieldItemDataDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\OptGroup;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\node\NodeStorageInterface;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\ParseMode\ParseModePluginManager;
use Drupal\search_api\Plugin\views\filter\SearchApiFulltext;
use Drupal\search_api\Plugin\views\query\SearchApiQuery;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_solr\Utility\Utility;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\search_api\Plugin\views\filter\SearchApiFilterTrait;
use Drupal\views\Plugin\views\filter\InOperator;
use Drupal\views\Views;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\search_api\Utility\FieldsHelperInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Render\RenderContext;

/**
 * Defines a filter that handles Image Similarity.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("sbr_imageml_filter")
 */
class StrawberryRunnersMLImagefilter extends FilterPluginBase /* FilterPluginBase */
{

  const IMAGEML_INPUT_SCHEMA = <<<'JSON'
{
    "title": "Image ML filter Input structure",
    "description": "A JSON Schema describing what this filter accepts.",
    "type": "object",
    "properties": {
      "iiif_image_id": {
        "type": "string"
      },
      "image_uuid": {
        "type": "string"
      },
      "bbox": {
        "type": "object",
        "properties": {
          "x": {
            "type": "number"
          },
          "y": {
            "type": "number"
          },
          "w": {
            "type": "number"
          },
          "h": {
            "type": "number"
          }
        },
        "required": [
          "x",
          "y",
          "w",
          "h"
        ]
      }
    },
    "oneOf": [
      {
        "required": [
          "iiif_image_id"
        ]
      },
      {
        "required": [
          "image_uuid"
        ]
      }
    ],
    "required": [
      "bbox"
    ]
}
JSON;


  use SearchApiFilterTrait;

  protected $alwaysMultiple = TRUE;

  public $no_operator = TRUE;

  /**
   * Stores the exposed input for this filter.
   *
   * @var array|null
   */
  public $validated_exposed_input = NULL;

  /**
   * The Entity Type manager
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $sbrEntityStorage;

  /**
   * The vocabulary storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $viewStorage;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The fields helper.
   *
   * @var \Drupal\search_api\Utility\FieldsHelperInterface
   */
  protected $fieldsHelper;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The Strawberry Runners Utility Service.
   *
   * @var \Drupal\strawberry_runners\strawberryRunnerUtilityServiceInterface
   */
  private $strawberryRunnerUtilityService;

  /**
   * The StrawberryRunner Processor Plugin Manager.
   *
   * @var \Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginManager
   */
  private $strawberryRunnerProcessorPluginManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container,
                                array $configuration, $plugin_id, $plugin_definition
  ) {
    /** @var static $plugin */
    $plugin = parent::create(
      $container, $configuration, $plugin_id, $plugin_definition
    );

    $plugin->setSbrEntityStorage(
      $container->get('entity_type.manager')->getStorage('strawberry_runners_postprocessor')
    );
    $plugin->setFieldsHelper($container->get('search_api.fields_helper'));
    $plugin->setViewStorage(
      $container->get('entity_type.manager')->getStorage('view')
    );
    $plugin->setViewStorage(
      $container->get('entity_type.manager')->getStorage('view')
    );
    $plugin->setCache($container->get('cache.default'));
    $plugin->currentUser = $container->get('current_user');
    $plugin->strawberryRunnerUtilityService = $container->get(
      'strawberry_runner.utility'
    );
    $plugin->strawberryRunnerProcessorPluginManager  = $container->get(
      'strawberry_runner.processor_manager'
    );
    return $plugin;
  }


  /**
   * {@inheritdoc}
   */
  public function defineOptions() {
    $options = parent::defineOptions();
    $options['value']['default'] = [];
    $options['sbf_fields'] = ['default' => NULL];
    $options['pre_query'] = ['default' => TRUE];
    $options['pre_query_facets'] = ['default' => TRUE];
    $options['topk'] = ['default' => 3];
    $options['ml_strawberry_postprocessor'] = ['default' => NULL];
    return $options;
  }

  public function setSbrEntityStorage(EntityStorageInterface $sbrEntityStorage): StrawberryRunnersMLImagefilter
  {
    $this->sbrEntityStorage = $sbrEntityStorage;
    return $this;
  }

  protected function canBuildGroup() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultExposeOptions() {
    parent::defaultExposeOptions();
    $this->options['expose']['reduce'] = FALSE;
  }

  protected function valueSubmit($form, FormStateInterface $form_state) {
    $form_state = $form_state;
  }

  protected function valueValidate($form, FormStateInterface $form_state) {
    $form_state->setValue(['options', 'value'], []);
  }


  public function setFieldsHelper(FieldsHelperInterface $fieldsHelper) {
    $this->fieldsHelper = $fieldsHelper;
    return $this;
  }

  /**
   * Sets the View Storage.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $viewstorage
   *   The view Storage.
   *
   * @return $this
   */
  public function setViewStorage(EntityStorageInterface $viewstorage) {
    $this->viewStorage = $viewstorage;
    return $this;
  }

  /**
   * Sets the Cache Backed.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend. Use to store complex calculations of property paths.
   *
   * @return $this
   */
  public function setCache(CacheBackendInterface $cache) {
    $this->cache = $cache;
    return $this;
  }

  public function showOperatorForm(&$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
    $active_plugins = $this->strawberryRunnerUtilityService->getActivePluginConfigs(FALSE);

    foreach ($active_plugins as $by_source => $plugins) {
      foreach ($plugins as $entity_id => $active_plugin) {
        if (isset($active_plugin['ml_method'])) {
          $post_processor_options[$entity_id] = $active_plugin['ml_method'] ."({$entity_id})";
        }
      }
    }

    $fields = $this->getSbfDenseVectorFields() ?? [];
    $form['sbf_fields'] = [
      '#type' => 'select',
      '#title' => $this->t(
        'KNN Dense Vector Field to query against'
      ),
      '#description' => $this->t(
        'Select the fields that will be used to query against.'
      ),
      '#options' => $fields,
      '#multiple' => FALSE,
      '#default_value' => $this->options['sbf_fields'],
      '#required' => TRUE,
    ];
    $form['pre_query'] = [
      '#type' => 'checkbox',
      '#default_value' => $this->options['pre_query'],
      '#title' => $this->t('Treat previous filters to this as prequeries'),
      '#description'=> $this->t(
        'If any other filter setup before this one will be treated as pre-queries to the actual KNN query.'
      ),
    ];
    $form['pre_query_facets'] = [
      '#type' => 'checkbox',
      '#default_value' => $this->options['pre_query_facets'],
      '#title' => $this->t('Treat also facets, if any, as prequeries'),
      '#description'=> $this->t(
        'If any other facets will be treated as pre-queries to the actual KNN query.'
      ),
    ];
    $form['topk'] = [
      '#type' => 'number',
      '#default_value' => $this->options['topk'],
      '#title' => $this->t('Top Similarity KNN hits to request to the backend.'),
      '#description'=> $this->t(
        'The more, the slower'
      ),
      '#min' => 1,
      '#max' => 100,
    ];
    $form['ml_strawberry_postprocessor'] =  [
      '#type' => 'select',
      '#title' => $this->t(
        'Strawberry Runners processor to extract the on-the fly embedding'
      ),
      '#description' => $this->t(
        'Select the ML Strawberry Runners Processor that was used to index Vectors into the field you are going to search against. These need to match'
      ),
      '#options' => $post_processor_options,
      '#multiple' => FALSE,
      '#default_value' => $this->options['ml_strawberry_postprocessor'],
      '#required' => TRUE,
    ];
  }
  /**
   * Validate the options form.
   */
  public function validateOptionsForm(&$form, FormStateInterface $form_state) {
    // We need to validate that the selected field is of the same source/size as model that will
    // be used to generate the on the fly vectors.
    // So we need to load the SBR entity passed, compare the model against the constant present in
    // \Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessor\abstractMLPostProcessor::ML_IMAGE_VECTOR_SIZE
    // and then load the field and see if the source (is of the same SBFlavor property/size (vector_576, etc)
    $valid = FALSE;
    $options = $form_state->getValue('options');
    $processor_id = $options['ml_strawberry_postprocessor'] ?? NULL;
    $field_id = $options['sbf_fields'];
    if ($processor_id) {
      /* @var $plugin_config_entity \Drupal\strawberry_runners\Entity\strawberryRunnerPostprocessorEntity|null */
      $plugin_config_entity = $this->sbrEntityStorage->load($processor_id);
      if ($plugin_config_entity->isActive()) {
        $config = $plugin_config_entity->getPluginconfig();
        // Note, we could also restrict to the same image mimetypes that the processor is setup to handle?
        if (isset($config['ml_method'])) {
          $vector_size = \Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessor\abstractMLPostProcessor::ML_IMAGE_VECTOR_SIZE[$config['ml_method']] ?? '';
          $field_info = $this->getSbfDenseVectorFieldSource($field_id);
          if ($field_info) {
            // We do allow mixed data sources. One can be a node of course even if the source is a flavor. This is because each source could inherit properties from the other.
            $propath_pieces = explode('/', $field_info->getCombinedPropertyPath());
            if (!(end($propath_pieces) == 'vector_' .$vector_size && $field_info->getType() == 'densevector_' . $vector_size)) {
              $form_state->setErrorByName('options][ml_strawberry_postprocessor', $this->t('The Field/Processor combination is not right. Make sure your Configured KNN Dense Vector Field and the Strawberry Processor are targeting the same Vector Dimensions (e.g first one is from a vector_576 data source property and the field type is densevector_576 and the processor is calling YOLO)'));
            }
          }
          else {
            // The field is gone.
            $form_state->setErrorByName('options][sbf_fields', $this->t('CConfigured KNN Dense Vector Field does not longer exists. Please replace your config with a valid/indexed field.'));
          }
        }
      }
    }
  }

  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    parent::submitOptionsForm(
      $form, $form_state
    );
  }

  protected function valueForm(&$form, FormStateInterface $form_state) {
    // At this stage  $this->value is not set?
    $this->value = is_array($this->value) ? $this->value : (array) $this->value;
    if (!$form_state->get('exposed')) {
      $form['value'] = [
        '#type' => 'textarea',
        '#title' => t('JSON used to query internal form'),
        '#prefix' => '<div class="views-group-box">',
        '#suffix' => '</div>'
      ];
    }
    elseif ($this->isExposed()) {
      $form['value'] = [
        '#type' => 'textarea',
        '#title' => t('JSON used to query public form'),
        '#prefix' => '<div class="views-group-box">',
        '#suffix' => '</div>'
      ] ;
    }
  }

  public function hasExtraOptions() {
    return FALSE;
  }

  /**
   * @inheritDoc
   */
  protected function operatorForm(&$form, FormStateInterface $form_state) {
    parent::operatorForm($form, $form_state); // TODO: Change the autogenerated stub
  }


  /**
   * {@inheritdoc}
   */
  public function buildExposeForm(&$form, FormStateInterface $form_state) {
    parent::buildExposeForm($form, $form_state);
    unset($form['expose']['reduce']);
  }


  public function query() {
    if (empty($this->value)) {
      return;
    }
    /*
     * $this->value = {stdClass}
 iiif_image_id = "3b9%2Fimage-dcpl-p034-npsncr-00015-rexported-f2c69aeb-7bcb-434a-a781-e580cb3695b7.tiff"
 bbox = {stdClass}
  x = {float} 0.0
  y = {float} 0.0
  w = {float} 1.0
  h = {float} 1.0
     */

    // Select boxes will always generate a single value.
    // I could check here or cast sooner on validation?
    if (!is_array($this->value)) {
      $this->value = (array) $this->value;
    }

    $query = $this->getQuery();

    if (array_filter($this->value, 'is_numeric') === $this->value) {

    }
    else {

    }
    return;
  }


  public function validate() {

    // For values passed by direct reference we will require/assume
    // $json_for_url = base64_encode(gzcompress($json));
    // And this operation will happen on reading/setting back and forth.
    $errors = parent::validate();
    if (is_array($this->value)) {
      if ($this->options['exposed'] && !$this->options['expose']['required']
        && empty($this->value)
      ) {
        // Don't validate if the field is exposed and no default value is provided.
        return $errors;
      }
      // Choose different kind of output for 0, a single and multiple values.
      if (count($this->value) == 0) {
        $errors[] = $this->t(
          'No valid values found on filter: @filter.',
          ['@filter' => $this->adminLabel(TRUE)]
        );
      }
    }
    return $errors;
  }

  public function validateExposed(&$form, FormStateInterface $form_state) {
    // Only validate exposed input.
    // In theory this is where i can alter the actual form state input
    // to set a different URL argument? compress?
    if (empty($this->options['exposed'])
      || empty($this->options['expose']['identifier'])
    ) {
      return;
    }

    $this->validated_exposed_input = NULL;
    $identifier = $this->options['expose']['identifier'];
    $input = $form_state->getValue($identifier);
    $values = (array) $input;
    if ($values) {
      if ($this->isExposed()) {
        // If already JSON
        $json_input = StrawberryfieldJsonHelper::isValidJsonSchema($values[0], static::IMAGEML_INPUT_SCHEMA);
        if ($json_input) {
          // Probably not the place to compress the data for the URL?
          $encoded = base64_encode(gzcompress($values[0]));

          $this->validated_exposed_input = $json_input;
        }
        elseif ($this->is_base64($values[0])) {
          $decoded = gzdecode(base64_decode($values[0]));
          if ($decoded !== FALSE) {
            $json_input = StrawberryfieldJsonHelper::isValidJsonSchema($values[0], static::IMAGEML_INPUT_SCHEMA);
            if ($json_input === JSON_ERROR_NONE) {
              $this->validated_exposed_input = $json_input;
            }
          }
        }
      }
      if (!$this->validated_exposed_input) {
        // Check if the JSON is the right structure.
        $form_state->setErrorByName($identifier, $this->t("Wrong format for the ML Image filter input"));
      }
    }
    else {
      // Do for non exposed. Should be directly a JSON
    }
  }


  public function acceptExposedInput($input) {
    // Called during the form submit itself..
    $rc = parent::acceptExposedInput($input);
    // a False means it won't be included/alter the generated query.
    // This is useful!
    if ($rc) {
      // If we have previously validated input, override.
      if (isset($this->validated_exposed_input)) {
        $this->value = $this->validated_exposed_input;
      }
    }

    return $rc;
  }

  /**
   * Retrieves a list of all fields that contain in its path a Node Entity.
   *
   * @return string[]
   *   An options list of field identifiers mapped to their prefixed
   *   labels.
   */
  protected function getSbfDenseVectorFields() {
    $fields = [];
    /** @var \Drupal\search_api\IndexInterface $index */
    $index = Index::load(substr($this->table, 17));

    $fields_info = $index->getFields();
    foreach ($fields_info as $field_id => $field) {
      //if (($field->getDatasourceId() == 'strawberryfield_flavor_datasource') && ($field->getType() == "integer")) {
      // Anything except text, fulltext or any solr_text variations. Also skip direct node id and UUIDs which would
      // basically return the same ADO as input filtered, given that those are unique.
      $property_path = $field->getPropertyPath();
      $datasource_id = $field->getDatasourceId();
      if (str_starts_with($field->getType(), 'densevector_') === TRUE) {
        $field->getDataDefinition();
        $fields[$field_id] = $field->getPrefixedLabel() . '('. $field->getFieldIdentifier() .')';
      }
    }
    return $fields;
  }

  protected function getSbfDenseVectorFieldSource($field_id) {
    $fields = [];
    /** @var \Drupal\search_api\IndexInterface $index */
    $index = Index::load(substr($this->table, 17));
    $fields_info = $index->getField($field_id);
    return $fields_info;
  }

  protected function getExistingDenseVectorForImage($uri, $field) {

  }

  protected function is_base64($s){
    // Check if there are valid base64 characters
    if (!preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $s)) return false;

    // Decode the string in strict mode and check the results
    $decoded = base64_decode($s, true);
    if(false === $decoded) return false;

    // Encode the string again
    if(base64_encode($decoded) != $s) return false;

    return true;
  }
}
