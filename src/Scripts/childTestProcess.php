<?php
use Drupal\Core\State\State;

$child['uuid'] = $extra[0];
$child['pid'] = getmypid();

//to return maimLoop current pid
//WE DON'T NEED THIS AS WE GET PID FROM PROCESS
//echo serialize($child);

sleep(3);
?>
