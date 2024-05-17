<?php

namespace Drupal\strawberry_runners\Plugin\views\filter;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
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
   * The vocabulary storage.
   *
   * @var \Drupal\node\NodeStorageInterface
   */
  protected $nodeStorage;

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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container,
    array $configuration, $plugin_id, $plugin_definition
  ) {
    /** @var static $plugin */
    $plugin = parent::create(
      $container, $configuration, $plugin_id, $plugin_definition
    );

    $plugin->setNodeStorage(
      $container->get('entity_type.manager')->getStorage('node')
    );
    $plugin->setFieldsHelper($container->get('search_api.fields_helper'));
    $plugin->setViewStorage(
      $container->get('entity_type.manager')->getStorage('view')
    );
    $plugin->setCache($container->get('cache.default'));
    $plugin->currentUser = $container->get('current_user');
    $plugin->strawberryRunnerUtilityService = $container->get(
      'strawberry_runner.utility'
    );
    return $plugin;
  }


  /**
   * {@inheritdoc}
   */
  public function defineOptions() {
    $options = parent::defineOptions();
    $options['value']['default'] = [];
    $options['sbf_fields'] = ['default' => []];
    return $options;
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

  /**
   * Sets the Node Storage.
   *
   * @param \Drupal\node\NodeStorageInterface $nodestorage
   *   The node storage.
   *
   * @return $this
   */

  public function setNodeStorage(NodeStorageInterface $nodestorage) {
    $this->nodeStorage = $nodestorage;
    return $this;
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

    $active_plugins = $this->strawberryRunnerUtilityService->getActivePluginConfigs();



    $fields = $this->getSbfDenseVectorFields() ?? [];
    $form['sbf_fields'] = [
      '#type' => 'select',
      '#title' => $this->t(
        'KNN Fields query against'
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
    $form['ml_strawberry_postprocessor'] = [

    ];

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

  protected function valueValidate($form, FormStateInterface $form_state) {
    $node_uuids = [];
    if ($values = $form_state->getValue(['options', 'value'])) {
      if (!is_array($values)) { (array) $values;}
      foreach ($values as $value) {
        $node_uuids_or_ids[] = $value;
      }
      sort($node_uuids_or_ids);
    }
    $form_state->setValue(['options', 'value'], $node_uuids_or_ids);
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
    // Select boxes will always generate a single value.
    // I could check here or cast sooner on validation?
    if (!is_array($this->value)) {
      $this->value = (array) $this->value;
    }

    $query = $this->getQuery();

    if (array_filter($this->value, 'is_numeric') === $this->value) {
      $nodes = $this->value ? $this->nodeStorage->loadByProperties(
        ['nid' => $this->value]
      ) : [];
    }
    else {
      $nodes = $this->value ? $this->nodeStorage->loadByProperties(
        ['uuid' => $this->value]
      ) : [];
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
    // Exposed input for this filter is meant for power users.
    // It will be a JSON with the following structure
    /*
     * {
     * "iiif_image_id": "a IIIF id. We won't allow External Images to be used for searching for now.",
     * "bbox": {
     *    "x": float,
     *    "y": float,
     *    "w": float,
     *    "w": float
     * }
     * }
     *
     */

    $identifier = $this->options['expose']['identifier'];
    $input = $form_state->getValue($identifier);

    $values = (array) $input;
    if ($values) {
      if ($this->isExposed()) {
        // If already JSON
        $json_input = json_decode($values[0] ?? '');
        if ($json_input !== JSON_ERROR_NONE) {
          // Probably not the place to compress the data for the URL?
             $encoded = base64_encode(gzcompress($values[0]));
             $form_state->setValue($identifier, $encoded);
             $input = $form_state->getUserInput();
             $input[$identifier] = $encoded;
             $form_state->setUserInput($input);
             $this->validated_exposed_input = $json_input;
             $filter_input = $this->view->getExposedInput();
             $filter_input[$identifier] = $encoded;
             $this->view->setExposedInput($filter_input);
        }
        else {
          // check if base64 encoded then
          if ($this->is_base64()) {

            $decoded = gzdecode(base64_decode($values[0]));
            if ($decoded !== FALSE) {
              $json_input = json_decode($values[0] ?? '');

            }
          }
        }
      }
      else {

      }
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
