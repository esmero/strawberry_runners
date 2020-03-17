<?php

namespace Drupal\strawberry_runners\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\strawberry_runners\Entity\strawberryRunnerPostprocessorEntity;
use Drupal\Component\Utility\NestedArray;

/**
 * Builds the form to setup/create Strawberry Runner Processor config entities.
 */
class strawberryRunnerPostprocessorEntityForm extends EntityForm {


  /**
   * The StrawberryRunnersPostProcessor Plugin Manager.
   *
   * @var StrawberryRunnersPostProcessorPluginManager strawberryRunnersPostProcessorPluginManager;
   */
  protected $strawberryRunnersPostProcessorPluginManager;

  public function __construct(StrawberryRunnersPostProcessorPluginManager $strawberryRunnersPostProcessorPluginManager) {
    $this->strawberryRunnersPostProcessorPluginManager = $strawberryRunnersPostProcessorPluginManager;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('strawberry_runner.processor_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    /* @var strawberryRunnerPostprocessorEntity $strawberry_processor */
    $strawberry_processor = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $strawberry_processor->label(),
      '#description' => $this->t("Label for this Processor"),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $strawberry_processor->id(),
      '#machine_name' => [
        'exists' => '\Drupal\strawberry_runners\Entity\strawberryRunnerPostprocessorEntity::load',
      ],
      '#disabled' => !$strawberry_processor->isNew(),
    ];

    $ajax = [
      'callback' => [get_class($this), 'ajaxCallback'],
      'wrapper' => 'postprocessorentity-ajax-container',
    ];
    /* @var \Drupal\strawberryfield\Plugin\StrawberryfieldKeyNameProviderManager $keyprovider_plugin_definitions */
    $plugin_definitions = $this->strawberryRunnersPostProcessorPluginManager->getDefinitions();
    foreach ($plugin_definitions as $id => $definition) {
      $options[$id] = $definition['label'];
    }

    $form['pluginid'] = [
      '#type' => 'select',
      '#title' => $this->t('Strawberry Runner Post Processor Plugin'),
      '#default_value' => $strawberry_processor->getPluginid(),
      '#options' => $options,
      "#empty_option" =>t('- Select One -'),
      '#required'=> true,
      '#ajax' => $ajax
    ];

    $form['container'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'postprocessorentity-ajax-container'],
      '#weight' => 100,
      '#tree' => true
    ];

    $pluginid = $form_state->getValue('pluginid')?:$strawberry_processor->getPluginid();
    if (!empty($pluginid))  {
      $this->messenger()->addMessage($form_state->getValue('pluginid'));
      $form['container']['pluginconfig'] = [
        '#type' => 'container',
        '#parents' => ['pluginconfig']
      ];
      $parents = ['container','pluginconfig'];
      $elements = $this->strawberryRunnersPostProcessorPluginManager->createInstance($pluginid,[])->settingsForm($parents, $form_state);
      $pluginconfig = $strawberry_processor->getPluginconfig();

      $form['container']['pluginconfig'] = array_merge($form['container']['pluginconfig'],$elements);
      if (!empty($pluginconfig)) {
        foreach ($pluginconfig as $key => $value) {
            if (isset($form['container']['pluginconfig'][$key])) {
              ($form['container']['pluginconfig'][$key]['#default_value'] = $value);
            }
        }
      }
    } else {
      $form['container']['pluginconfig'] = [
        '#type' => 'container',
      ];

    }

    $form['active'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Is this processor plugin active?'),
      '#return_value' => TRUE,
      '#default_value' => $strawberry_processor->isActive(),
    ];

    //@TODO allow a preview of the processing via ajax

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $strawberry_processor = $this->entity;

    $status = $strawberry_processor->save();

    switch ($status) {
      case SAVED_NEW:
        $this->messenger->addStatus($this->t('Created the %label Strawberry Runner Post Processor.', [
          '%label' => $strawberry_processor->label(),
        ]));
        break;

      default:
        $this->messenger->addStatus($this->t('Saved the %label Strawberry Runner Post Processor.', [
          '%label' => $strawberry_processor->label(),
        ]));
    }
   $form_state->setRedirectUrl($strawberry_processor->toUrl('collection'));
  }

  /**
   * Ajax callback.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   An associative array containing entity reference details element.
   */
  public static function ajaxCallback(array $form, FormStateInterface $form_state) {
    $form_state->setRebuild();
    return $form['container'];
  }


}
