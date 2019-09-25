<?php
use SupAgent\Model\Cron;
use SupAgent\Model\CronLog;

require 'init.php';

$fp = fopen('init.php', 'r');
fseek($fp, -1024 * 1024, SEEK_END);
$data = fread($fp, 1024 * 1024);

var_dump($data);