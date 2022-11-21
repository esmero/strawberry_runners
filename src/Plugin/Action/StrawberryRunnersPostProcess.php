<?php

namespace Drupal\strawberry_runners\Plugin\Action;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\views\ViewExecutable;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionCompletedTrait;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\strawberryfield\Plugin\Action\StrawberryfieldJsonPatch;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsPreconfigurationInterface;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an action that can Modify Entity attached SBFs via JSON Patch.
 *
 * @Action(
 *   id = "entity:sbr_postprocess_action",
 *   action_label = @Translation("Trigger Strawberry Runners process/reprocess for Archipelago Digital Objects"),
 *   category = @Translation("Strawberry Runners"),
 *   deriver = "Drupal\strawberry_runners\Plugin\Action\Derivative\EntitySbfActionDeriver",
 *   type = "node",
 *   confirm = "true"
 * )
 */
class StrawberryRunnersPostProcess extends StrawberryfieldJsonPatch implements ViewsBulkOperationsActionInterface, ViewsBulkOperationsPreconfigurationInterface, PluginFormInterface {

  use ViewsBulkOperationsActionCompletedTrait;

  /**
   * Action context.
   *
   * @var array
   *   Contains view data and optionally batch operation context.
   */
  protected $context;

  /**
   * The processed view.
   *
   * @var \Drupal\views\ViewExecutable
   */
  protected $view;

  /**
   * The Strawberry Runners Utility Service.
   *
   * @var \Drupal\strawberry_runners\strawberryRunnerUtilityServiceInterface
   */
  protected $strawberryRunnerUtilityService;

  /**
   * Configuration array.
   *
   * @var array
   */
  protected $configuration;


  public static function create(ContainerInterface $container,
    array $configuration, $plugin_id, $plugin_definition
  ) {
    $instance = parent::create(
      $container, $configuration, $plugin_id, $plugin_definition
    );
    $instance->strawberryRunnerUtilityService = $container->get(
      'strawberry_runner.utility'
    );
    return $instance;
  }


  /**
   * {@inheritdoc}
   */
  public function setContext(array &$context) {
    $this->context['sandbox'] = &$context['sandbox'];
    foreach ($context as $key => $item) {
      if ($key === 'sandbox') {
        continue;
      }
      $this->context[$key] = $item;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setView(ViewExecutable $view) {
    $this->view = $view;
  }

  /**
   * {@inheritdoc}
   */
  public function executeMultiple(array $objects) {
    $results = [];
    foreach ($objects as $entity) {

      $results[] = $this->execute($entity);
    }
    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {

    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $invoked = FALSE;
    if ($entity) {
      if ($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield(
        $entity
      )
      ) {
        $force = $this->configuration['force'] ?? FALSE;
        $force = (bool) $force;
        $filter = $this->configuration['plugins'] ?? [];
        $filter = array_filter($filter);
        if (!empty($filter)) {
          $this->strawberryRunnerUtilityService->invokeProcessorForAdo(
            $entity, $sbf_fields, $force, $filter
          );
          $invoked = TRUE;
        }

      }

    }
    return $invoked;
  }



  public function buildPreConfigurationForm(array $element, array $values, FormStateInterface $form_state) {
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {

    $active_plugins = $this->strawberryRunnerUtilityService->getActivePluginConfigs();
    $options = [];
    foreach ($active_plugins as $source => $processors) {
      $options = array_combine(array_keys($processors), array_keys($processors));
    }
    $options = array_unique($options);
    $form['plugins'] = [
      '#type' => 'checkboxes',
      '#title' => t('Processors you want to run'),
      '#default_value' => $this->configuration['plugins'] ?? [],
      '#options' => $options,
      '#description' => t('If it runs and is enqueued or not will depend on each processor running condition like ADO type, mimetype, etc., matched against each ADO you selected.'),
    ];

    $form['force'] = [
      '#title' => $this->t('Check to force processing even if these were previously processed and exist.'),
      '#type' => 'checkbox',
      '#default_value' => $this->configuration['force'] ?? FALSE,
      '#description' => $this->t('Output of <em>post processor</em> might be many and is configured for post processor, e.g a Search API document or an ADO attached file.')
    ];
    return $form;
  }

  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['plugins'] = $form_state->getValue('plugins');
    $this->configuration['force'] = $form_state->getValue('force');
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {

  }


  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'plugins' => [],
      'force' => FALSE,
    ];
  }

  /**
   * Default custom access callback.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user the access check needs to be preformed against.
   * @param \Drupal\views\ViewExecutable $view
   *   The View Bulk Operations view data.
   *
   * @return bool
   *   Has access.
   */
  public static function customAccess(AccountInterface $account, ViewExecutable $view) {
    return TRUE;
  }

  public function getPluginId() {
    return parent::getPluginId(); // TODO: Change the autogenerated stub
  }

  public function getPluginDefinition() {
    return parent::getPluginDefinition(); // TODO: Change the autogenerated stub
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }


}
