<?php
namespace SupAgent\Task;

use Phalcon\Cli\Task;
use SupAgent\Supervisor\EventListener;
use Mtdowling\Supervisor\EventNotification;

class SupervisorTask extends Task
{
    /**
     * 处理进程状态的变化
     */
    public function handleProcessStateEventAction()
    {
        $listener = new EventListener();

        $listener->listen(function(EventListener $listener, EventNotification $event) {
            // 占用内存是否超过限制
            // 是否有文件发生修改

            $listener->log($event->getEventName());
            $listener->log($event->getServer());
            $listener->log($event->getPool());
            $listener->log(var_export($event->getData(), true));

            


            return true;
        });
    }
}