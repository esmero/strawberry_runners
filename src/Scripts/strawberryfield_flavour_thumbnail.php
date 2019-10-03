<?php
use Drupal\Core\State\State;

//child_id = node_id, type and status
$child_id = $extra[0];

//Pull running item status (node_id and SBF-JSON)
$item_state_data = unserialize(\Drupal::state()->get('strawberryfield_runningItem'));
$element = unserialize($item_state_data['item']->data);
$node_id = $element[0];
$jsondata = $element[1];




//output on queue child output
$childQueue_output = \Drupal::queue('strawberryfields_child_output');

$output[0] = $child_id;
$output[1] = 'Test Thumbnail';
$childQueue_output->createItem(serialize($output));

sleep(3);
?>
