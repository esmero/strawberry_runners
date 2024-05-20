<?php

namespace Drupal\strawberry_runners\Plugin\views\argument;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Plugin\views\argument\SearchApiStandard;
use Drupal\search_api\Plugin\views\query\SearchApiQuery;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\search_api\Utility\FieldsHelperInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessor\abstractMLPostProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a filter that handles Image Similarity.
 *
 * @ingroup views_argument_handlers
 *
 * @ViewsArgument("sbr_imageml_filter")
 */
class StrawberryRunnersMLImageArgument extends SearchApiStandard
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

    /**
     * Is argument validated.
     */
    public ?bool $argument_validated;
    /**
     * Stores the exposed input for this filter.
     *
     * @var array|null
     */
    public $expanded_argument = NULL;

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
    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
        /** @var static $plugin */
        $plugin = parent::create($container, $configuration, $plugin_id, $plugin_definition);
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

    public function setSbrEntityStorage(EntityStorageInterface $sbrEntityStorage)
    {
        $this->sbrEntityStorage = $sbrEntityStorage;
        return $this;
    }


    protected function valueSubmit($form, FormStateInterface $form_state) {
        $form_state = $form_state;
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
        $options = $form_state->getValue('options');
        $processor_id = $options['ml_strawberry_postprocessor'] ?? NULL;
        $field_id = $options['sbf_fields'];
        if ($processor_id) {
            /* @var $plugin_config_entity \Drupal\strawberry_runners\Entity\strawberryRunnerPostprocessorEntity|null */
            $plugin_config_entity = $this->sbrEntityStorage->load($processor_id);
            if ($plugin_config_entity->isActive()) {
                $sbr_config = $plugin_config_entity->getPluginconfig();
                // Note, we could also restrict to the same image mimetypes that the processor is setup to handle?
                if (isset($sbr_config['ml_method'])) {
                    $vector_size = abstractMLPostProcessor::ML_IMAGE_VECTOR_SIZE[$sbr_config['ml_method']] ?? '';
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


    /**
     * Set the input for this argument.
     *
     * @return TRUE if it successfully validates; FALSE if it does not.
     */
    public function setArgument($arg) {
        $this->argument = $arg;
        return $this->validateArgument($arg);
    }


    public function query($group_by = FALSE) {
        $this->argument_validated;
        if (empty($this->expanded_argument) || ! $this->query) {
            // basically not validated, not present as a value and also someone cancelled/nuklled the query before?
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
        // Just to be sure here bc we have our own way. Who knows if some external code decides to alter the value
        $this->value = $this->expanded_argument;
        // We should only be at this stage if we have validation
        // As always, start by processing all inline, then move to separate code for cleaner methods
        // We need to load the SBR entity first here
        $iiif_image_url = null;
        $processor_id = $this->options['ml_strawberry_postprocessor'];
        /* @var $plugin_config_entity \Drupal\strawberry_runners\Entity\strawberryRunnerPostprocessorEntity|null */
        $plugin_config_entity = $this->sbrEntityStorage->load($processor_id);
        if ($plugin_config_entity->isActive()) {
            $sbr_config = $plugin_config_entity->getPluginconfig();
            // Now we need to actually generate an instance of the runner using the config
            $entity_id = $plugin_config_entity->id();
            $configuration_options = $plugin_config_entity->getPluginconfig();
            $configuration_options['configEntity'] = $entity_id;
            /* @var \Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface $plugin_instance */
            $plugin_instance
                = $this->strawberryRunnerProcessorPluginManager->createInstance(
                $plugin_config_entity->getPluginid(),
                $configuration_options
            );
            if ($plugin_instance instanceof abstractMLPostProcessor) {
                $iiifidentifier = urlencode(
                    StreamWrapperManager::getTarget($this->value->iiif_image_id) ?? NULL
                );
                if ($iiifidentifier == NULL || empty($iiifidentifier)) {
                    return;
                }
                // basically the whole image if no bbox will be used as default
                // Now prep the image for fetching. First pass, just an ID, then deal with the UUID for the file option
                // pct:x,y,w,h
                // !w,h
                $region = 'full';
                if (isset($this->value->bbox->x)) {
                    $region = 'pct:'.($this->value->bbox->x * 100).','.($this->value->bbox->y * 100).','.($this->value->bbox->w * 100).','.($this->value->bbox->h * 100);
                }
                $iiif_image_url =  $sbr_config['iiif_server']."/{$iiifidentifier}/{$region}/!640,640/0/default.jpg";
                try {
                    $response = $plugin_instance->callImageML($iiif_image_url, []);
                }
                catch (\Exception $exception) {
                    // Give user feedback
                    return;
                }
                if (!empty($response['error'])) {
                    // we should log this
                    return;
                }
                elseif (isset($response['message'])) {
                    // Now here is an issue. Each endpoint will return the vector inside a yolo/etc.
                    // We should change that and make it generic (requires new pythong code/rebuilding NLP container)
                    // so for now i will use the ml method config split/last to get the right key.
                    foreach (["error","message","web64"] as $remove) {
                        unset($response[$remove]);
                    }
                    $all_knns =  $this->query->getOption('sbf_knn') ?? [];
                    foreach ($response as $endpoint_key => $values) {
                        if (isset($values['vector']) && is_array($values['vector']) && count($values['vector']) == abstractMLPostProcessor::ML_IMAGE_VECTOR_SIZE[$sbr_config['ml_method']]) {
                            $all_knns[] = $this->buildKNNQuery($this->query, $values['vector']);
                        }
                    }
                    array_filter($all_knns);
                    if (count($all_knns)) {
                        $this->query->setOption('sbf_knn', $all_knns);
                    }
                }
            }
        }
        if (!$iiif_image_url) {
            return;
        }
        return;
    }

    public function validateArgument($arg) {

        $this->expanded_argument = NULL;

        // By using % in URLs, arguments could be validated twice; this eases
        // that pain.
        if (isset($this->argument_validated)) {
            return $this->argument_validated;
        }

        if ($this->isException($arg)) {
            return $this->argument_validated = TRUE;
        }

        $plugin = $this->getPlugin('argument_validator');
        //return $this->argument_validated = $plugin->validateArgument($arg);
        if ($arg) {
            // If already JSON
            $json_input = StrawberryfieldJsonHelper::isValidJsonSchema($arg, static::IMAGEML_INPUT_SCHEMA);
            if ($json_input) {
                // Probably not the place to compress the data for the URL?
                $encoded = base64_encode(gzcompress($arg));
                $form_state->setValue($identifier, $encoded);
                $this->expanded_argument = $json_input;
            } // WE uRL decode because base64 might contain "/" which is the argument separator. So we pre-encoded it.
            elseif ($this->is_base64(urldecode($arg))) {
                $decoded = gzuncompress(base64_decode(urldecode("eJxNzssOgjAQheE1JLyD6ZqB0ivwMqS3wRoVojFqCO9uwZi4%2B7%2FZnFmKPCMxRhzixYxhiJ70B3LnfV2bTtX7EURwjeYCrZDKdoZZJ1xgwgT0lCO2MBt7nuAabg9voOkUBy1pq2lgwLz0IJRk0KKg4IzSqIJ1yHV1mkdSbg9YO73S7pI6I1vRipY73v94JjQ%2FHL9IvRb5%2BgG%2BNTFV")));

                if ($decoded !== FALSE) {
                    $json_input = StrawberryfieldJsonHelper::isValidJsonSchema($decoded, static::IMAGEML_INPUT_SCHEMA);
                    if ($json_input !== FALSE) {
                        $this->expanded_argument = $json_input;
                    }
                }
            }
            if (!$this->expanded_argument) {
                $this->argument_validated = FALSE;
            }
            else {
                if ($this->expanded_argument->iiif_image_id && !(empty($this->expanded_argument->iiif_image_id))) {
                    $image_id = StreamWrapperManager::getTarget($this->expanded_argument->iiif_image_id);
                    // means passed without a streamwrapper
                    if ($image_id) {
                        $this->argument_validated = TRUE;
                    }
                }
            }
        }
        return $this->argument_validated ?? FALSE;
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

    /**
     * @param \Drupal\search_api\Plugin\views\query\SearchApiQuery $query
     *
     * @throws \Drupal\search_api\SearchApiException
     */
    protected function buildKNNQuery(SearchApiQuery $query, array $vector=[]):array|null {
        // We can only use Solr kids.
        $solr_query_string = [];
        $backend = $query->getIndex()->getServerInstance()->getBackend();
        if (!($backend instanceof \Drupal\search_api_solr\SolrBackendInterface)) {
            return FALSE;
        }
        $allfields_translated_to_solr = $backend
            ->getSolrFieldNames($query->getIndex());
        if (isset($allfields_translated_to_solr[$this->options['sbf_fields']])) {
            $solr_query_string[] = "{!knn f={$allfields_translated_to_solr[$this->options['sbf_fields']]} topK={$this->options['topk']}}[" . implode(', ', $vector) . ']';
            // {!knn f=vector topK=3}[-9.01364535e-03, -7.26634488e-02, -1.73818860e-02, ..., -1.16323479e-01]
        }
        return $solr_query_string;
    }
}
