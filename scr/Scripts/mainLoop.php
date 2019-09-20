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
$max_childProcess = 2;

$loop = Factory::create();
$queue = \Drupal::queue('strawberry_runners');
$cycleBefore_timeout = $idleCycle_timeout;


//Timer to update lastRunTime
$loop->addPeriodicTimer($alivePeriod, function () use ($loop) {
  echo 'MAIN LOOP alive' . PHP_EOL;

  //update lastRunTime
  $data = unserialize(\Drupal::state()->get('strawberryfield_mainLoop'));
  $data['lastRunTime'] = \Drupal::time()->getCurrentTime();
  \Drupal::state()->set('strawberryfield_mainLoop', serialize($data));
});

//Timer to check queue
$loop->addPeriodicTimer($queuecheckPeriod, function () use ($loop, &$cycleBefore_timeout, $queue, $idleCycle_timeout, $max_childProcess) {
  --$cycleBefore_timeout;
  echo 'cycles before idle timeout ' . $cycleBefore_timeout . PHP_EOL;

  //Count queue element
  $totalItems = $queue->numberOfItems();
  echo 'totalItemsinLoop ' . $totalItems . PHP_EOL;

  //Queue empty and timeout then stop
  if (($totalItems < 1) && ($cycleBefore_timeout < 1 )){
    echo 'Idle timeout' . PHP_EOL;
    \Drupal::state()->delete('strawberryfield_mainLoop');
    \Drupal::state()->delete('strawberryfield_childList');
    $loop->stop();
  }

  //Queue no empty
  if ($totalItems > 0) {
    //reset idle timeout
    $cycleBefore_timeout = $idleCycle_timeout;

    //process item
    $item = $queue->claimItem();
    //if not released then next claim will be of next item in queue
    $queue->releaseItem($item);
    $item_id = $item->item_id;
    echo 'Process element:' . $item_id . PHP_EOL;

    //check current item
    $data = \Drupal::state()->get('strawberryfield_runningItem');

    //no running item
    if (is_null($data)) {
      echo 'NO running item' . PHP_EOL;

      //set item status to init (1)
      $item_state_data = [
        'itemId' => $item_id,
        'itemStatus' => 1,
      ];
      \Drupal::state()->set('strawberryfield_runningItem', serialize($item_state_data));
      echo 'Item ' . $item_id . ' set status ' . $item_state_data['itemStatus'] . PHP_EOL;

      //clear child state
      \Drupal::state()->delete('strawberryfield_childList');

      //init
      //extract childs to process from item
      //child_list[child_uuid] = 1:to process 2:processing 0:OK processed -1:error processing

      //TEST. build child_list
      $child_number = 3;
      for ($x = 1; $x <= $child_number; $x++) {
        $child_uuid = "Child_" . $x;
        $child_list[$child_uuid] = 0;
      }
      //TEST

      if ($child_number > 0) {
        //Item has child to process, push on state
        \Drupal::state()->set('strawberryfield_childList', serialize($child_list));
      }
      else {
        //No child to process, set item status All_done (3)
        $item_state_data = [
          'itemId' => $item_id,
          'itemStatus' => 3,
        ];
        \Drupal::state()->set('strawberryfield_runningItem', serialize($item_state_data));
        echo 'Item ' . $item_id . ' set status ' . $item_state_data['itemStatus'] . PHP_EOL;
      }
    }
    //running item
    else {
      //item initialized(1)/running(2)/allDone(3)/allDone with errors(4)
      $item_state_data = unserialize($data);
      $itemId = $item_state_data['itemId'];
      $itemStatus = $item_state_data['itemStatus'];
      echo 'Item ' . $itemId . ' status ' . $itemStatus . PHP_EOL;

      //initialized, switch to running, childList already on state
      if ($itemStatus == 1) {
        $child_status = unserialize(\Drupal::state()->get('strawberryfield_childList'));
        foreach ($child_status as $child_uuid => $cstatus) {
          echo "{$child_uuid} => {$cstatus} " . PHP_EOL;;
        }
        //Set item status running(2)
        $item_state_data['itemStatus'] = 2;
        \Drupal::state()->set('strawberryfield_runningItem', serialize($item_state_data));
        echo 'Item ' . $item_id . ' set status ' . $item_state_data['itemStatus'] . PHP_EOL;
      }

      //process child. child status = 0:to process 1:processing 2:OK processed 3:error processing
      if ($itemStatus == 2) {

        //TEST. set all child done OK (2)
//        $child_status = unserialize(\Drupal::state()->get('strawberryfield_childList'));
//        foreach ($child_status as $child_uuid => $cstatus) {
//          $child_status[$child_uuid] = 2;
//        }
//        \Drupal::state()->set('strawberryfield_childList', serialize($child_status));
        //

        //check child status then ...
        $totalChild = 0;
        $totalChild_status = array_fill(0, 4, 0);
        $child_status = unserialize(\Drupal::state()->get('strawberryfield_childList'));
        foreach ($child_status as $child_uuid => $cstatus) {
          $totalChild++;
          $totalChild_status[$cstatus]++;
        }
print_r($totalChild_status);


        //allDONE OK
        if ($totalChild_status[2] == $totalChild){
          //Set item status allDone(3) without errors
          $item_state_data['itemStatus'] = 3;
          \Drupal::state()->set('strawberryfield_runningItem', serialize($item_state_data));
          echo 'Item ' . $item_id . ' set status ' . $item_state_data['itemStatus'] . PHP_EOL;
        }

        //allDONE with errors
        if (($totalChild_status[2] + $totalChild_status[3])== $totalChild){
          //Set item status allDone(4) with errors
          $item_state_data['itemStatus'] = 4;
          \Drupal::state()->set('strawberryfield_runningItem', serialize($item_state_data));
          echo 'Item ' . $item_id . ' set status ' . $item_state_data['itemStatus'] . PHP_EOL;
        }

        //processing = max
        if ($totalChild_status[1] == $max_childProcess){
          //check running process alive
          //wait next cycle
        }
        //some to process
        elseif ($totalChild_status[0] > 0){
          $child_uuid = array_search(0, $child_status);
          $child_status[$child_uuid] = 1;
          \Drupal::state()->set('strawberryfield_childList', serialize($child_status));

          //start process $child_uuid

          //TEST. set child done ok
          $child_status[$child_uuid] = 2;
          \Drupal::state()->set('strawberryfield_childList', serialize($child_status));
          //TEST.

        }
      }

      if ($itemStatus == 3) {
        //allDone without errors

        //remove runningItem and childList state
        \Drupal::state()->delete('strawberryfield_runningItem');
        \Drupal::state()->delete('strawberryfield_childList');
        //remove item from queue
        $queue->deleteItem($item);
      }
      if ($itemStatus == 4) {
        //allDone with errors
        //ToDO!!

        //remove runningItem and childList state
        \Drupal::state()->delete('strawberryfield_runningItem');
        \Drupal::state()->delete('strawberryfield_childList');
        //remove item from queue
        $queue->deleteItem($item);
      }
    }
  }
});

//Force mainLoop stop after 120s for test purpose
$loop->addTimer(120, function () use ($loop) {
  echo 'before stop' . PHP_EOL;
  $loop->stop();
});
//

$loop->run();

?>
