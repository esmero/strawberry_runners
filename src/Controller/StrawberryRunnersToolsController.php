<?php

namespace Drupal\node\Controller;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\node\NodeStorageInterface;
use Drupal\node\NodeTypeInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Returns responses for Node routes.
 */
class StrawberryRunnersToolsController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The entity repository service.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * Constructs a StrawberryRunnersToolsController object.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   */
  public function __construct(RendererInterface $renderer, EntityRepositoryInterface $entity_repository) {
    $this->renderer = $renderer;
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


  /**
   * Generates an overview table of all Tools.
   *
   * @param \Drupal\node\NodeInterface $node
   *   A node object.
   *
   * @return array
   *   An array as expected by \Drupal\Core\Render\RendererInterface::render().
   */
  public function toolsOverview(NodeInterface $node) {


    $build['test_jmespath'] = [
      '#name' => 'jmespath_tester',
      '#type' => 'textfield',
      '#title' => $this->t('Email address'),
      '#ajax' => [
        'callback' => [$this, 'callJmesPathprocess'],
        'event' => 'change',
        'keypress' => TRUE,
        'disable-refocus' => TRUE
        ]
    ];

    /*
    $account = $this->currentUser();
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $type = $node->getType();

    $build['#title'] = $this->t('Associated Media for %title', ['%title' => $node->label()]);
    $header = [$this->t('Revision'), $this->t('Operations')];
    $delete_permission = (($account->hasPermission("delete $type revisions") || $account->hasPermission('delete all revisions') || $account->hasPermission('administer nodes')) && $node->access('delete'));

    $rows = [];


    foreach ($this->getRevisionIds($node, $node_storage) as $vid) {
      $revision = $node_storage->loadRevision($vid);

        $row = [];
        $column = [
          'data' => [
            '#type' => 'inline_template',
            '#template' => '{% trans %}{{ date }} by {{ username }}{% endtrans %}{% if message %}<p class="revision-log">{{ message }}</p>{% endif %}',
            '#context' => [
              'date' => 'blabal',
              'username' => $this->renderer->renderPlain($username),
              'message' => ['#markup' => $revision->revision_log->value, '#allowed_tags' => Xss::getHtmlTagList()],
            ],
          ],
        ];
        $this->renderer->addCacheableDependency($column['data'], $username);
        $row[] = $column;


          $links = [];

          if ($delete_permission) {
            $links['delete'] = [
              'title' => $this->t('Delete'),
              'url' => Url::fromRoute('node.revision_delete_confirm', ['node' => $node->id(), 'node_revision' => $vid]),
            ];
          }

          $row[] = [
            'data' => [
              '#type' => 'operations',
              '#links' => $links,
            ],
          ];

          $rows[] = $row;
        }


    $build['file_table'] = [
      '#theme' => 'table',
      '#rows' => $rows,
      '#header' => $header,
    ];

    $build['pager'] = ['#type' => 'pager'];
    */

    return $build;
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
