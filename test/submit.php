<?php

use Drupal\Core\State\State;

//launch it with:
//$ sudo -u www-data vendor/drush/drush/drush scr submit --script-path=/var/www/archipelago/web/modules/contrib/strawberry_runners/test
//the submit script exit and mailLoop script run in background
//to see mailLoop output check /tmp/runners.log file
//i.e. tail -f /tmp/runners.log

//ToDO: add to main configuration
$queuecheckPeriod = 3;

//build command
$drush_path = "/var/www/archipelago/vendor/drush/drush/";
$mainLoop_path = "/var/www/archipelago/web/modules/contrib/strawberry_runners/src/Scripts";
$cmd = $drush_path . 'drush scr mainLoop --script-path=' . $mainLoop_path;
$outputfile = "/tmp/runners.log";

//check mainLoop alive
$submitTime = \Drupal::time()->getCurrentTime();
$lastRunTime = \Drupal::state()->get('strawberryfield_mainLoop_keepalive');
$delta = $submitTime - $lastRunTime;

if ($delta > (2 * $queuecheckPeriod)) {
  echo 'mainLoop to start' . PHP_EOL;
  $pid = shell_exec(sprintf("%s > %s 2>&1 & echo $!", $cmd, $outputfile));
  \Drupal::state()->set('strawberryfield_mainLoop_pid', $pid);
  \Drupal::state()->set('strawberryfield_mainLoop_keepalive', $submitTime);
}
else { echo 'mainLoop running' . PHP_EOL; }

//add elements to queue
echo 'Push 1 item on queue' . PHP_EOL;
$queue = \Drupal::queue('strawberry_runners');
for ($x = 1; $x <= 1; $x++) {
  $element = "Element " . $x;
  $queue->createItem($element);
}


//check mainLoop not stopped before queue item is processed
echo 'mainLoop check ...' . PHP_EOL;
$NxqueuecheckPeriod = 3 * $queuecheckPeriod;
do {
  \Drupal::state()->resetCache();
  $lastRunTime = intval(\Drupal::state()->get('strawberryfield_mainLoop_keepalive'));
  $currentTime = \Drupal::time()->getCurrentTime();
  $delta1 = $submitTime - $lastRunTime;
  $delta2 = $currentTime - $submitTime - $NxqueuecheckPeriod;
} while( ($delta1 >= 0) && ($delta2 < 0) );

if (!($delta2 < 0)) {
  //mainLoop stopped before start to process queue
  echo 'mainLoop to start again' . PHP_EOL;
  $pid = shell_exec(sprintf("%s > %s 2>&1 & echo $!", $cmd, $outputfile));
  \Drupal::state()->set('strawberryfield_mainLoop_pid', $pid);
  \Drupal::state()->set('strawberryfield_mainLoop_keepalive', $submitTime);
}

$totalItems = $queue->numberOfItems();
echo 'TotalItems on queue ' . $totalItems . PHP_EOL;
?>
