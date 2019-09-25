<?php
use SupAgent\Model\Cron;
use SupAgent\Model\CronLog;

require 'init.php';

$content = file_get_contents(\SupAgent\Model\Server::CONF_CRON);
$parsed = parse_ini_string($content, true, INI_SCANNER_RAW);
if ($parsed === false)
{
    throw new Exception("无法解析配置");
}

var_dump($parsed);