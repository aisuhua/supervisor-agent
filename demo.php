<?php
include 'init.php';

if (!\SupAgent\Model\Cron::isCron('_supervisor_event_listener'))
{
    // 只处理定时任务事件
    echo 'true';
    exit;
}

echo 'false';

