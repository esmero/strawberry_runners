<?php

use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\ChildProcess\Process;
use Drupal\Core\State\State;
use Drupal\Core\Queue;

require __DIR__ . '/../../../../../../vendor/autoload.php';

echo 'MAIN LOOP getmypid: ' . getmypid() . PHP_EOL;

//ToDO: add to main configuration
$queuecheckPeriod = 3;
$idleCycle_timeout = 5;
$max_childProcess = 2;

$loop = Factory::create();
$queue = \Drupal::queue('strawberry_runners');
$cycleBefore_timeout = $idleCycle_timeout;

//§
//§init queues
// _init + _started = total
// item pulled from _init and pushed into _started
// item NOT pulled from _started
// item pushed into _done or _error
if (\Drupal::queue('strawberryfields_child_init')->numberOfItems > 0) {\Drupal::queue('strawberryfields_child_init')->deleteQueue;}
if (\Drupal::queue('strawberryfields_child_started')->numberOfItems > 0) {\Drupal::queue('strawberryfields_child_started')->deleteQueue;}
if (\Drupal::queue('strawberryfields_child_done')->numberOfItems > 0) {\Drupal::queue('strawberryfields_child_done')->deleteQueue;}
if (\Drupal::queue('strawberryfields_child_error')->numberOfItems > 0) {\Drupal::queue('strawberryfields_child_error')->deleteQueue;}
$childQueue_init = \Drupal::queue('strawberryfields_child_init');
$childQueue_started = \Drupal::queue('strawberryfields_child_started');
$childQueue_done = \Drupal::queue('strawberryfields_child_done');
$childQueue_error = \Drupal::queue('strawberryfields_child_error');

//Timer to check queue
$loop->addPeriodicTimer($queuecheckPeriod, function () use ($loop, &$cycleBefore_timeout, $queue, $idleCycle_timeout, $max_childProcess, $childQueue_init, $childQueue_started, $childQueue_done, $childQueue_error) {
  \Drupal::state()->set('strawberryfield_mainLoop_keepalive', \Drupal::time()->getCurrentTime());
  //Count queue element
  $totalItems = $queue->numberOfItems();
  echo 'totalItems on queue ' . $totalItems . PHP_EOL;

  if ($totalItems == 0) { //Queue empty

    //decrement idle timeout counter
    --$cycleBefore_timeout;
    //Queue empty and timeout then stop
    if ($cycleBefore_timeout == 0){
      echo 'Idle timeout reached' . PHP_EOL;
      \Drupal::state()->delete('strawberryfield_mainLoop_keepalive');
      \Drupal::state()->delete('strawberryfield_mainLoop_pid');
      $loop->stop();
    }
  }
  else { //Queue not empty

    //reset idle timeout
    $cycleBefore_timeout = $idleCycle_timeout;

    //process item
    //item status = initialized(0)/running(1)/allDone(2)/allDone with errors(3)

    //Pull running item status
    $item_state_data_ser = \Drupal::state()->get('strawberryfield_runningItem');

    if (is_null($item_state_data_ser)) { //no running item and queue not empty

      //claim item from queue
      $item = $queue->claimItem();
      $item_id = $item->item_id;
      $element = unserialize($item->data);
      echo 'Start to process element ID:' . $item_id . PHP_EOL;
      echo '... node ID: ' . $element[0] . PHP_EOL;

      //initialize item (status = 0) and child
      $item_state_data = [
        'item' => $item,
        'itemStatus' => 0,
      ];
      \Drupal::state()->set('strawberryfield_runningItem', serialize($item_state_data));
      echo 'Item ' . $item_id . ' set status ' . $item_state_data['itemStatus'] . PHP_EOL;

      //extract childs to process from item
      //
      //child_id includes item_id
      //child_ref contains all child_id
      //
      //ToDO: add something about type of process to run ???!!!

      //TEST. build child_data and child_ref
      $child_number = 3;
      for ($x = 1; $x <= $child_number; $x++) {
        $child_id = $item_id . "_Child_" . $x;
        $child_ref[] = $child_id;
      }
      //TEST

      if ($child_number > 0) { //Item has child to process

        //Push ref on state
        \Drupal::state()->set('strawberryfield_child_ref', serialize($child_ref));

        //§Push child on init queue
        foreach ($child_ref as $child_id) {
          $childQueue_init->createItem($child_id);
        }
      }
      else { //No child to process

        //set item status allDone (2)
        $item_state_data = [
          'item' => $item,
          'itemStatus' => 2,
        ];
        \Drupal::state()->set('strawberryfield_runningItem', serialize($item_state_data));
        echo 'Item ' . $item_id . ' set status ' . $item_state_data['itemStatus'] . PHP_EOL;
      }
    }
    else { //Item is running and queue not empty

      //Read running item status
      $item_state_data = unserialize($item_state_data_ser);
      $item = $item_state_data['item'];
      $itemId = $item->item_id;
      $itemStatus = $item_state_data['itemStatus'];
      echo 'Item ' . $itemId . ' status ' . $itemStatus . PHP_EOL;

      //initialized(0),running(1),allDone(2),allDone with errors(3)
      switch ($itemStatus) {
        case 0:
          //initialized, move to running
          //Set item status running(1)
          $item_state_data['itemStatus'] = 1;
          \Drupal::state()->set('strawberryfield_runningItem', serialize($item_state_data));
          break;
        case 1:
          //running (ready or already processing child)
          //child status = 0:to process 1:processing 2:OK processed 3:error processing

          //check child status then ...
          $total_child_status = array();
          $total_child_status[0] = $childQueue_init->numberOfItems();
          $total_child_status[1] = $childQueue_started->numberOfItems();
          $total_child_status[2] = $childQueue_done->numberOfItems();
          $total_child_status[3] = $childQueue_error->numberOfItems();
          $total_child = $total_child_status[0] + $total_child_status[1];
          $total_child_running = $total_child_status[1] - $total_child_status[2] - $total_child_status[3];

  //TEST
          print_r($total_child_status);
  //TEST

          if ($total_child_status[2] == $total_child) {
            //... child allDONE OK

            //Set item status allDone(2) without errors
            $item_state_data['itemStatus'] = 2;
            \Drupal::state()->set('strawberryfield_runningItem', serialize($item_state_data));
            echo 'Item ' . $itemId . ' set status ' . $item_state_data['itemStatus'] . PHP_EOL;
          }
          elseif (($total_child_status[2] + $total_child_status[3]) == $total_child) {
            //... child allDONE with errors

            //Set item status allDone(3) with errors
            $item_state_data['itemStatus'] = 3;
            \Drupal::state()->set('strawberryfield_runningItem', serialize($item_state_data));
            echo 'Item ' . $itemId . ' set status ' . $item_state_data['itemStatus'] . PHP_EOL;
          }
          elseif ($total_child_running >= $max_childProcess) {
            //... child running = max

            //ToDO: check running process alive
            //Wait next cycle
          }
          elseif ($total_child_status[0] > 0) {
            //... some child to process

            //§ claim first child to process
            $child_queue_item = $childQueue_init->claimItem();
            $child_id = $child_queue_item->data;
            //start process $child_id
            echo '***** start process: ' . $child_id . PHP_EOL;

            $drush_path = "/var/www/archipelago/vendor/drush/drush/";
            $childProcess_path = "/var/www/archipelago/web/modules/contrib/strawberry_runners/src/Scripts";
            //added child_id as variable to child process call
            $cmd = 'exec ' . $drush_path . 'drush scr --script-path=' . $childProcess_path . ' childTestProcess -- ' . $child_id;

            $process = new Process($cmd, null, null, null);
            $process->start($loop);

            //§ remove from init queue and push on started queue
            $childQueue_init->deleteItem($child_queue_item);
            $childQueue_started->createItem($child_id . '|' . $process->getPid());

            $process->stdout->on('data', function ($chunk) use ($child_id){
              //code to read chunck from child process output
            });

            $process->on('exit', function ($code, $term) use ($child_id, $childQueue_done, $childQueue_error){
              //copy to queue done or error
              //ToDO: more deep check
              if ($code == 0) {
                $childQueue_done->createItem($child_id);
              }
              else {
                $childQueue_error->createItem($child_id);
              }

              echo '*****exit with code: ' . $code . ' with signal: ' . $term . ' process: ' . $child_id . PHP_EOL;
            });
            //ToDO: do we have to add process timeout???
              //$loop->addTimer(5, function () use ($process) {
              // Running with exec we don't have to close pipes before terminate
              //    foreach ($process->pipes as $pipe) {
              //        $pipe->close();
              //    }
              //$process->terminate();
              //});
          }
          break;
        case 2:
          //allDone without errors

          //remove item from queue
          $queue->deleteItem($item);

          //delete runningItem and child ref
          \Drupal::state()->delete('strawberryfield_runningItem');
          \Drupal::state()->delete('strawberryfield_child_ref');

          //§delete queues
          $childQueue_init->deleteQueue();
          $childQueue_started->deleteQueue();
          $childQueue_done->deleteQueue();
          $childQueue_error->deleteQueue();

          break;
        case 3:
          //allDone with errors
          //
          //ToDO!!
          //

          //remove item from queue
          $queue->deleteItem($item);

          //remove runningItem and child ref
          \Drupal::state()->delete('strawberryfield_runningItem');
          \Drupal::state()->delete('strawberryfield_child_ref');

          //§delete queues
          $childQueue_init->deleteQueue();
          $childQueue_started->deleteQueue();
          $childQueue_done->deleteQueue();
          $childQueue_error->deleteQueue();

          break;
      }
    }
  }
});

//Force mainLoop stop after 360s for test purpose
$loop->addTimer(360, function () use ($loop) {
  echo 'before stop' . PHP_EOL;
  \Drupal::state()->delete('strawberryfield_mainLoop_keepalive');
  \Drupal::state()->delete('strawberryfield_mainLoop_pid');
  $loop->stop();
});
//

$loop->run();

?>
