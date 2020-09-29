<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 4/9/20
 * Time: 11:14 AM
 */

namespace Drupal\strawberry_runners;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\ChildProcess\Process;

/**
 * Provides a the Strawberry Runners React Event Loop Service
 */
class StrawberryRunnersLoopService {

  use StringTranslationTrait;

  /**
   * File system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   */
  protected $configFactory;

  /**
   * The Module Handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface;
   */
  protected $moduleHandler;

  /**
   * The lock service.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * The queue service.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The account switcher service.
   *
   * @var \Drupal\Core\Session\AccountSwitcherInterface
   */
  protected $accountSwitcher;


  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The queue plugin manager.
   *
   * @var \Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  protected $queueManager;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * A list of Field names of type SBF
   *
   * @var array|NULL
   *
   */
  protected $strawberryfieldMachineNames = NULL;

  /**
   * StrawberryRunnersLoopService constructor.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   * @param \Drupal\strawberry_runners\LockBackendInterface $lock
   * @param \Drupal\strawberry_runners\QueueFactory $queue_factory
   * @param \Drupal\strawberry_runners\StateInterface $state
   * @param \Drupal\strawberry_runners\AccountSwitcherInterface $account_switcher
   * @param \Drupal\strawberry_runners\LoggerInterface $logger
   * @param \Drupal\strawberry_runners\QueueWorkerManagerInterface $queue_manager
   * @param \Drupal\strawberry_runners\TimeInterface|NULL $time
   */
  public function __construct(
    FileSystemInterface $file_system,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler,
    LockBackendInterface $lock,
    QueueFactory $queue_factory,
    StateInterface $state,
    AccountSwitcherInterface $account_switcher,
    LoggerInterface $logger,
    QueueWorkerManagerInterface $queue_manager,
    TimeInterface $time = NULL
  ) {
    $this->fileSystem = $file_system;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
    $this->lock = $lock;
    $this->queueFactory = $queue_factory;
    $this->state = $state;
    $this->accountSwitcher = $account_switcher;
    $this->logger = $logger;
    $this->queueManager = $queue_manager;
    $this->time = $time ?: \Drupal::service('datetime.time');
  }



  public function mainLoop() {

  }

  /**
   * Push an Item on 'strawberry_runners' queue
   */
  public function pushItemOnQueue($node_id, $jsondata, $flavour) {
    $element[0] = $node_id;
    $element[1] = $jsondata;
    $element[2] = $flavour;
    //add element to queue
    echo 'Push 1 item on queue' . PHP_EOL;

    $queue = $this->queueFactory->get('strawberry_runners', TRUE);
    $queue->createItem(serialize($element));

    $totalItems = $queue->numberOfItems();
    echo 'TotalItems on queue ' . $totalItems . PHP_EOL;
  }



}
