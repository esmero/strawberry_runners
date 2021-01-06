<?php

namespace Drupal\strawberry_runners\Form;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InsertCommand;

/**
 * Returns responses for Node routes.
 */
class StrawberryRunnersToolsForm extends FormBase {

  /**
   * The entity repository service.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * Constructs a StrawberryRunnersToolsForm object.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   */
  public function __construct(RendererInterface $renderer, EntityRepositoryInterface $entity_repository) {
    $this->entityRepository = $entity_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('renderer'),
      $container->get('entity.repository')
    );
  }

  public function getFormId() {
    return 'strawberry_runners_tools_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, NodeInterface $node = NULL) {

    // For code Mirror
    // @TODO make this module dependant
    $settings['mode'] = 'application/ld+json';
    $settings['readOnly'] = TRUE;
    $settings['toolbar'] = FALSE;
    $settings['lineNumbers'] = TRUE;

    if ($sbf_fields = \Drupal::service('strawberryfield.utility')->bearsStrawberryfield($node)) {
      foreach ($sbf_fields as $field_name) {
        /* @var $field \Drupal\Core\Field\FieldItemInterface */
        $field = $node->get($field_name);
        if (!$field->isEmpty()) {
          /** @var $field \Drupal\Core\Field\FieldItemList */
          foreach ($field->getIterator() as $delta => $itemfield) {
            // Note: we are not longer touching the metadata here.
            /** @var $itemfield \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem */
            $json = json_encode(json_decode($itemfield->value), JSON_PRETTY_PRINT);
            $form_state->set('itemfield', $itemfield);
            $form['test_jmespath'] = [
              '#type' => 'textfield',
              '#default_value' => $form_state->getValue('test_jmespath'),
              '#title' => $this->t('JMESPATH'),
              '#description' => $this->t(
                'Evaluate a JMESPath Query against this ADO\'s JSON. See <a href=":href" target="_blank">JMESPath Tutorial</a>.',
                [':href' => 'http://jmespath.org/tutorial.html']
              ),

              '#ajax' => [
                'callback' => [$this, 'callJmesPathprocess'],
                'event' => 'change',
                'keypress' => FALSE,
                'disable-refocus' => FALSE,
                'progress' => [
                  // Graphic shown to indicate ajax. Options: 'throbber' (default), 'bar'.
                  'type' => 'throbber',
                ],
              ],
              '#required' => TRUE,
              '#executes_submit_callback' => TRUE,
              '#submit' =>  ['::submitForm']
            ];
            $form['test_jmespath_input'] = [
              '#type' => 'codemirror',
              '#codemirror' => $settings,
              '#default_value' => $json,
              '#rows' => 15,
            ];
            $form['test_output'] = [
              '#type' => 'codemirror',
              '#prefix' => '<div id="jmespathoutput">',
              '#suffix' => '</div>',
              '#codemirror' => $settings,
              '#default_value' => '{}',
              '#rows' => 15,
              '#attached' => [
                'library' => [
                  'strawberry_runners/jmespath_codemirror_strawberry_runners',
                ],
              ],
            ];
          }
        }
      }
    }
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => t('Submit'),
      '#attributes' => ['class' => ['js-hide']],
      '#submit' =>  [[$this,'submitForm']]
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {

    $form_state->setRebuild();
  }

  public function callJmesPathprocess(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    /** @var $itemfield \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem */
    $itemfield = $form_state->get('itemfield');
    try {
      $result = $itemfield->searchPath($form_state->getValue('test_jmespath'));
    }
    catch (\Exception $exception) {
      $result = $exception->getMessage();
    }

    $response->addCommand(new \Drupal\strawberry_runners\Ajax\UpdateCodeMirrorCommand('#jmespathoutput', json_encode($result,JSON_PRETTY_PRINT)));

    return $response;
  }

  /**
   * Limit access to the Tools according to their restricted state.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account object.
   * @param int $node
   *   The node id.
   */
  public function accessTools(AccountInterface $account, $node) {
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $node = $node_storage->load($node);
    $type = $node->getType();
    // @TODO for now...
    if ($sbf_fields = \Drupal::service('strawberryfield.utility')->bearsStrawberryfield($node)) {
      $access = AccessResult::allowedIfHasPermission($account, 'edit any ' . $type . ' content');
      if (!$access->isAllowed() && $account->hasPermission('edit own ' . $type . ' content')) {
        $access = $access->orIf(AccessResult::allowedIf($account->id() == $node->getOwnerId())->cachePerUser()->addCacheableDependency($node));
      }
    } else {
      $access = AccessResult::forbidden();
    }

    $access->addCacheableDependency($node);
    return AccessResult::allowedIf($access)->cachePerPermissions();


  }

}
