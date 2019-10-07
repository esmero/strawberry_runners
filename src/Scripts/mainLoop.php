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
if (\Drupal::queue('strawberryfields_child_output')->numberOfItems > 0) {\Drupal::queue('strawberryfields_child_output')->deleteQueue;}
$childQueue_init = \Drupal::queue('strawberryfields_child_init');
$childQueue_started = \Drupal::queue('strawberryfields_child_started');
$childQueue_done = \Drupal::queue('strawberryfields_child_done');
$childQueue_error = \Drupal::queue('strawberryfields_child_error');
$childQueue_output = \Drupal::queue('strawberryfields_child_output');

//Timer to check queue
$loop->addPeriodicTimer($queuecheckPeriod, function () use ($loop, &$cycleBefore_timeout, $queue, $idleCycle_timeout, $max_childProcess, $childQueue_init, $childQueue_started, $childQueue_done, $childQueue_error, $childQueue_output) {
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
      $node_id = $element[0];
      $jsondata = $element[1];
      $flavour_toProcess = $element[2];

      echo 'Start to process element ID:' . $item_id . ' node ID: ' . $node_id . ' Flavour: ' . $flavour_toProcess . PHP_EOL;

      //initialize item (status = 0)
      $item_state_data = [
        'item' => $item,
        'itemStatus' => 0,
      ];
      \Drupal::state()->set('strawberryfield_runningItem', serialize($item_state_data));
      echo 'Item ' . $item_id . ' set status ' . $item_state_data['itemStatus'] . PHP_EOL;

      //extract childs to process from SBF-JSON
      //
      //child_id = node_id, type and status
      //child_ref contains all child_id to process

      //$child_ref = listFlavoursToProcess($node_id, $jsondata);
      $child_ref = listChildsToProcess($node_id, $jsondata, $flavour_toProcess);
      $child_number = count($child_ref);

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
      $element = unserialize($item->data);
      $node_id = $element[0];
      $jsondata = $element[1];
      $flavour_toProcess = $element[2];
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

            $child_flavour_status = explode('|', $child_id)[4];

            if ($child_flavour_status == 3) {
              //flavour to remove

              //§ remove from init queue and push on started and done queues
              $childQueue_init->deleteItem($child_queue_item);
              $childQueue_started->createItem($child_id . '|0');
              $childQueue_done->createItem($child_id);
              $childQueue_output = \Drupal::queue('strawberryfields_child_output');

              unset($output);
              $output[0] = $child_id;
              $output[1] = 0;
              $output[2] = '';
              $childQueue_output->createItem(serialize($output));
            }
            else {
              $drush_path = "/var/www/archipelago/vendor/drush/drush/";
              $childProcess_path = "/var/www/archipelago/web/modules/contrib/strawberry_runners/src/Scripts";
              $childProcess_script = 'strawberryfield_flavour_' . explode('|', $child_id)[3];
              //added child_id as variable to child process call
              $cmd = 'exec ' . $drush_path . 'drush scr --script-path=' . $childProcess_path . ' ' . $childProcess_script . ' -- "' . $child_id . '"';

              $process = new Process($cmd, null, null, null);
              $process->start($loop);

              //§ remove from init queue and push on started queue
              $childQueue_init->deleteItem($child_queue_item);
              $childQueue_started->createItem($child_id . '|' . $process->getPid());

              $process->stdout->on('data', function ($chunk) use ($child_id){
                //code to read chunck from child process output
                echo 'Chunk ' . $child_id . ': ' . $chunk . PHP_EOL;
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

                echo '*****exit with code: ' . $code . ' process: ' . $child_id . PHP_EOL;
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
          }
          break;
        case 2:
          //allDone without errors

          //manage results from child output queue
          //
          //ToDO!!
          //

          //TEST
          $child_output_item_number = $childQueue_output->numberOfItems();
          while ($child_output_item_number > 0) {
            $child_output_item = $childQueue_output->claimItem();
            $child_output_data = unserialize($child_output_item->data);
            $childQueue_output->deleteItem($child_output_item);
            $child_output_item_number = $childQueue_output->numberOfItems();

            $flavour_type = explode('|', $child_output_data[0])[3];
            $flavour_status = explode('|', $child_output_data[0])[4];
            $flavour_node_id = explode('|', $child_output_data[0])[0];
            $flavour_mainContainer_key = explode('|', $child_output_data[0])[1];
            $flavour_subContainer_key = explode('|', $child_output_data[0])[2];

            $new_jsondata_array = $jsondata;

            if ($flavour_status == 3) {
              $flavour_json_array = array();
            }
            else {
              $flavour_json_array = json_decode($child_output_data[2], TRUE);
            }
            echo 'OLD JSON *************' . PHP_EOL;
            $jsondata_flavour_key = 'flv:' . $flavour_type;
            print_r($jsondata[$flavour_mainContainer_key][$flavour_subContainer_key][$jsondata_flavour_key]);
            echo 'OLD JSON ^^^^^^^^^^^^^' . PHP_EOL;

            echo 'NEW JSON *************' . PHP_EOL;
            $new_jsondata_array[$flavour_mainContainer_key][$flavour_subContainer_key][$jsondata_flavour_key]['status'] = 0;
            $new_jsondata_array[$flavour_mainContainer_key][$flavour_subContainer_key][$jsondata_flavour_key]['data'] = $flavour_json_array;
            print_r($new_jsondata_array[$flavour_mainContainer_key][$flavour_subContainer_key][$jsondata_flavour_key]);
            echo 'NEW JSON ^^^^^^^^^^^^^' . PHP_EOL;


            $new_jsondata = json_encode($new_jsondata_array);

            $ado = \Drupal::entityTypeManager()->getStorage('node')->load($flavour_node_id);
            $ado->set('field_descriptive_metadata', $new_jsondata);
            $ado->save();

            $jsondata = $new_jsondata_array;

          }
          //TEST


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
          $childQueue_output->deleteQueue();

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
          $childQueue_output->deleteQueue();

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

/**
 * List childs to process from SBF-JSON
 *
 * (0) ready, runner executed and STB-JSON updated
 * (1) new, runner to execute
 * (2) update, runner to execute as STB-JSON was updated
 * (3) remove, runner must remove entry related to this flavour
 *
 *
 *  Expected something like this:
 *
 *     "as:image": {
 *        "urn:uuid:35915592-83c8-40b6-b097-29c51c134cc7": {
 *            "url": "private:\/\/53d\/image-giovane-uomo-del-ballerino-che-indossa-un-salto-russo-piega-del-costume-28730977_3.jpg",
 *            "name": "giovane-uomo-del-ballerino-che-indossa-un-salto-russo-piega-del-costume-28730977_3.jpg",
 *            "tags": [],
 *            "type": "Image",
 *            "dr:fid": 62,
 *            "dr:for": "images",
 *            "dr:uuid": "35915592-83c8-40b6-b097-29c51c134cc7",
 *            "flv:exif": {
 *               "status": 1
 *            },
 */
 function listChildsToProcess($node_id, $jsondata, $flavour_toProcess) {
   //First level keys where to search for flavours
   //Flavours will be in 3rd level
   $flavour_mainContainer_keys = array("as:image");
   $flavour_key = 'flv:' . $flavour_toProcess;

   $child_index = 0;
   $child_ref = array();
   foreach ($flavour_mainContainer_keys as $mainContainer_key) {
     if (isset($jsondata[$mainContainer_key])) {
       foreach ($jsondata[$mainContainer_key] as $subContainer_key => $subContainer) {
         if (array_key_exists($flavour_key, $subContainer)) {
           $flavour_status = $subContainer[$flavour_key]['status'];
           if ($flavour_status > 0) {
             $child_ref[$child_index] = "{$node_id}|{$mainContainer_key}|{$subContainer_key}|{$flavour_toProcess}|{$flavour_status}";
             $child_index++;
           }
         }
       }
     }
   }
   return $child_ref;
}

/**
 * List flavours to process from SBF-JSON
 *
 * (0) ready, runner executed and STB-JSON updated
 * (1) new, runner to execute
 * (2) update, runner to execute as STB-JSON was updated
 * (3) remove, runner must remove entry related to this flavour
 *
 *
 *  Expected something like this:
 *
 *     "as:image": {
 *        "urn:uuid:35915592-83c8-40b6-b097-29c51c134cc7": {
 *            "url": "private:\/\/53d\/image-giovane-uomo-del-ballerino-che-indossa-un-salto-russo-piega-del-costume-28730977_3.jpg",
 *            "name": "giovane-uomo-del-ballerino-che-indossa-un-salto-russo-piega-del-costume-28730977_3.jpg",
 *            "tags": [],
 *            "type": "Image",
 *            "dr:fid": 62,
 *            "dr:for": "images",
 *            "dr:uuid": "35915592-83c8-40b6-b097-29c51c134cc7",
 *            "flv:exif": {
 *               "status": 1
 *            },
 */
 function listFlavoursToProcess($node_id, $jsondata) {
   //First level keys where to search for flavours
   //Flavours will be in 3rd level
   $flavour_mainContainer_keys = array("as:image");
   $flavour_installed = array("exif", "hocr");

   $child_index = 0;
   $child_ref = array();
   foreach ($flavour_mainContainer_keys as $mainContainer_key) {
     if (isset($jsondata[$mainContainer_key])) {
       foreach ($jsondata[$mainContainer_key] as $subContainer_key => $subContainer) {
         foreach ($flavour_installed as $flavour){
           $flavour_key = 'flv:' . $flavour;
           if (array_key_exists($flavour_key, $subContainer)) {
             $flavour_status = $subContainer[$flavour_key]['status'];
             if ($flavour_status > 0) {
               $child_ref[$child_index] = "{$node_id}|{$mainContainer_key}|{$subContainer_key}|{$flavour}|{$flavour_status}";
               $child_index++;
             }
           }
         }
       }
     }
   }
   return $child_ref;
}

?>
