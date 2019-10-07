<?php
use Drupal\Core\State\State;

//child_id = node_id, type and status
$child_id = $extra[0];

//Pull running item status (node_id and SBF-JSON)
$item_state_data = unserialize(\Drupal::state()->get('strawberryfield_runningItem'));
$element = unserialize($item_state_data['item']->data);
$node_id = $element[0];
$jsondata = $element[1];

$flavour_type = explode('|', $child_id)[3];
$flavour_status = explode('|', $child_id)[4];
$flavour_node_id = explode('|', $child_id)[0];
$flavour_mainContainer_key = explode('|', $child_id)[1];
$flavour_subContainer_key = explode('|', $child_id)[2];

$uri = $jsondata[$flavour_mainContainer_key][$flavour_subContainer_key]['url'];

//get image path
$stream = \Drupal::service('stream_wrapper_manager')->getViaUri($uri);
$image_path = $stream->realpath();

//call external command
unset($exif_output);
unset($exif_array_output);
exec('exif -m ' . $image_path, $exif_output, $exif_return);

if ($exif_return == 0) {
  foreach ($exif_output as $line) {
    $line_split = explode("\t", $line);
    $exif_array_output[$line_split[0]] = $line_split[1];
  }
  $exif_json_output = json_encode($exif_array_output);
}
else {
  $exif_json_output = '{"Exif return error":"' . $exif_return . '"}';
}

//output on queue child output
$childQueue_output = \Drupal::queue('strawberryfields_child_output');

$output[0] = $child_id;
$output[1] = $exif_return;
$output[2] = $exif_json_output;
$childQueue_output->createItem(serialize($output));

//set return code 0=OK, other= error
drush_set_context('DRUSH_EXIT_CODE', $exif_return);

?>
