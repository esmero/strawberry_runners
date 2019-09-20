<?php

use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\ChildProcess\Process;
use Drupal\Core\State\State;

require __DIR__ . '/../../../../../../vendor/autoload.php';

//ToDO: add this to main configuration
$cyclePeriod = 5;

echo 'MAIN LOOP getmypid: ' . getmypid() . PHP_EOL;

$loop = Factory::create();

//Timer to update lastRunTime
$loop->addPeriodicTimer($cyclePeriod, function () use ($loop) {
  echo 'MAIN LOOP timer' . PHP_EOL;

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

//Force mainLoop stop after 60s for test purpose
$loop->addTimer(60, function () use ($loop) {
  echo 'before stop' . PHP_EOL;
  $loop->stop();
});
//

$loop->run();

?>
