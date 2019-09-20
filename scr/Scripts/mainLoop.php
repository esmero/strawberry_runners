<?php

use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\ChildProcess\Process;
use Drupal\Core\State\State;

require __DIR__ . '/../../../../../../vendor/autoload.php';

echo 'MAIN LOOP getmypid: ' . getmypid() . PHP_EOL;

//ToDO: add to main configuration
$alivePeriod = 5;
$queuecheckPeriod = 3;
$idleCycle_timeout = 5;

$loop = Factory::create();
$queue = \Drupal::queue('strawberry_runners');
$cycleBefore_timeout = $idleCycle_timeout;

//Timer to update lastRunTime
$loop->addPeriodicTimer($alivePeriod, function () use ($loop) {
  echo 'MAIN LOOP alive' . PHP_EOL;

  //update lastRunTime
  $data = \Drupal::state()->get('strawberryfield_mainLoop');
  $data = explode(',', $data);
  $data = array_pad($data, 2, 0);
  list($processId, $lastRunTime) = $data;
  $newdata = [
		'processId' => $processId,
		'lastRunTime' => \Drupal::time()->getCurrentTime(),
  ];
  \Drupal::state()->set('strawberryfield_mainLoop', implode(',', $newdata));
});

//Timer to check queue
$loop->addPeriodicTimer($queuecheckPeriod, function () use ($loop, &$cycleBefore_timeout, $queue, $idleCycle_timeout) {
  --$cycleBefore_timeout;
  echo 'cycles before idle timeout ' . $cycleBefore_timeout . PHP_EOL;

  //Count queue element
  $totalItems = $queue->numberOfItems();
  echo 'totalItemsinLoop ' . $totalItems . PHP_EOL;

  //Queue empty and timeout then stop
  if (($totalItems < 1) && ($cycleBefore_timeout < 1 )){
    echo 'Idle timeout' . PHP_EOL;
    $loop->stop();
  }

  //Queue no empty
  if ($totalItems > 0) {
    //reset idle timeout
    $cycleBefore_timeout = $idleCycle_timeout;

    //process item
    $item = $queue->claimItem();
    echo 'Process element:' . $item->item_id . PHP_EOL;

    //remove item if process end with no errors
    $queue->deleteItem($item);
  }
});

//Force mainLoop stop after 60s for test purpose
$loop->addTimer(60, function () use ($loop) {
  echo 'before stop' . PHP_EOL;
  $loop->stop();
});
//

$loop->run();

?>
