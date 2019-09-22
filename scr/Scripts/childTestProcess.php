<?php
use Drupal\Core\State\State;

$child['uuid'] = $extra[0];
$child['pid'] = getmypid();

//to return maimLoop current pid
echo serialize($child);

sleep(3);
?>
