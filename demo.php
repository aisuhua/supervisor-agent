<?php
use SupAgent\Model\Cron;
use SupAgent\Model\CronLog;

require 'init.php';

$cron = Cron::find('server_id = 111');
var_dump($cron->toArray());
