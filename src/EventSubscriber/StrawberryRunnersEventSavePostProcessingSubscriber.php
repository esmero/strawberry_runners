<?php

namespace Drupal\strawberry_runners\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\strawberryfield\EventSubscriber\StrawberryfieldEventSaveSubscriber;
use Drupal\strawberry_runners\strawberryRunnerUtilityServiceInterface;

/**
 * Event subscriber for SBF bearing entity json process event.
 */
class StrawberryRunnersEventSavePostProcessingSubscriber extends StrawberryfieldEventSaveSubscriber {


  use StringTranslationTrait;

  /**
   *
   * Run as late as possible.
   *
   * @var int
   */
  protected static $priority = -2000;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The Strawberry Runners Utility Service.
   *
   * @var \Drupal\strawberry_runners\strawberryRunnerUtilityServiceInterface
   */
  protected $strawberryRunnerUtilityService;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * StrawberryRunnersEventSavePostProcessingSubscriber constructor.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface                $string_translation
   * @param \Drupal\Core\Messenger\MessengerInterface                          $messenger
   * @param \Drupal\Core\Session\AccountInterface                              $account
   * @param \Drupal\strawberry_runners\strawberryRunnerUtilityServiceInterface $utility
   */
  public function __construct(
    TranslationInterface $string_translation,
    MessengerInterface $messenger,
    AccountInterface $account,
    strawberryRunnerUtilityServiceInterface $utility
  ) {
    $this->stringTranslation = $string_translation;
    $this->messenger = $messenger;
    $this->account = $account;
    $this->strawberryRunnerUtilityService = $utility;
  }

  /**
   *  Method called when Event occurs.
   *
   * @param \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent $event
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function onEntitySave(StrawberryfieldCrudEvent $event) {

    $entity = $event->getEntity();
    $sbf_fields = $event->getFields();
    $this->strawberryRunnerUtilityService->invokeProcessorForAdo($entity, $sbf_fields);
    $current_class = get_called_class();
    $event->setProcessedBy($current_class, TRUE);
    if ($this->account->hasPermission('display strawberry messages')) {
      $this->messenger->addStatus($this->t('Post processor was invoked'));
    }
  }

}
