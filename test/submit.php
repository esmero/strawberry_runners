<?php

use Drupal\Core\State\State;

//copy this script into site root i.e. /var/www/archipelago
//then launch it from site root with:
//$ sudo -u www-data vendor/drush/drush/drush scr submit
//after launched the submit script exit and mailLoop script run in background
//to see mailLoop output check /tmp/runners.log file
//i.e. tail -f /tmp/runners.log

//build command
$drush_path = "/var/www/archipelago/vendor/drush/drush/";
$mainLoop_path = "/var/www/archipelago/web/modules/contrib/strawberry_runners/src/Scripts";
$cmd = $drush_path . 'drush scr mainLoop --script-path=' . $mainLoop_path;
$outputfile = "/tmp/runners.log";


//check state
$data = unserialize(\Drupal::state()->get('strawberryfield_mainLoop'));
if (!($data)) {
  $lastRunTime = 0;
}
else{
  $lastRunTime = $data['lastRunTime'];
}
$delta = \Drupal::time()->getCurrentTime() - $lastRunTime;

if ($delta < 10) {
  echo 'mainLoop running' . PHP_EOL;

  //add elements to queue
  $queue = \Drupal::queue('strawberry_runners');
  for ($x = 1; $x <= 2; $x++) {
    $element = "Element " . $x;
    $queue->createItem($element);
  }
  $totalItems = $queue->numberOfItems();
}
else {
  echo 'mainLoop to start' . PHP_EOL;

  //clear and populate queue
  $queue = \Drupal::queue('strawberry_runners');
  $queue->deleteQueue();
  for ($x = 1; $x <= 2; $x++) {
    $element = "Element " . $x;
    $queue->createItem($element);
  }
  $totalItems = $queue->numberOfItems();

  $pid = shell_exec(sprintf("%s > %s 2>&1 & echo $!", $cmd, $outputfile));
  $data = [
    'processId' => $pid,
    'lastRunTime' => \Drupal::time()->getCurrentTime(),
  ];
  \Drupal::state()->set('strawberryfield_mainLoop', serialize($data));
}
  echo 'Queue totalItems ' . $totalItems . PHP_EOL;

?>
