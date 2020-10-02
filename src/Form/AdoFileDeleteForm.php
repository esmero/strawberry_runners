<?php

namespace Drupal\strawberry_runners\Form;


use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\file\FileInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\strawberryfield\StrawberryfieldFilePersisterService;

/**
 * Provides a form for deleting a node revision.
 *
 * @internal
 */
class AdoFileDeleteForm extends ConfirmFormBase {

  /**
   * The Node.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $node;

  /**
   * The File.
   *
   * @var \Drupal\file\FileInterface
   */
  protected $file;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;


  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;


  /**
   * AdoFileDeleteForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\strawberry_runners\Form\MessengerInterface $messenger
   * @param \Drupal\strawberryfield\StrawberryfieldFilePersisterService $filePersisterService
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger, StrawberryfieldFilePersisterService $filePersisterService) {
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->sbffileservice = $filePersisterService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.manager'),
      $container->get('messenger'),
      $container->get('strawberryfield.file_persister')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ado_file_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return t('Are you sure you want to remove the file %file attached to this ADO %node', [
      '%node' => $this->node->label(),
      '%file' => $this->file->getFilename(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('strawberry_runners.ado_tools', ['node' => $this->node->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return t('Remove');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $node = NULL, $file = NULL) {
    $this->node = $this->entityTypeManager->getStorage('node')->load($node);
    $this->file = $this->entityTypeManager->getStorage('file')->load($file);

    $form = parent::buildForm($form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $this->logger('content')->notice('@type: deleted %title revision %revision.', ['@type' => $this->revision->bundle(), '%title' => $this->revision->label(), '%revision' => $this->revision->getRevisionId()]);
    $this->messenger->addStatus('Done!');
    $form_state->setRedirect(
      'strawberry_runners.ado_tools',
      ['node' => $this->node->id()]
    );
  }

}
