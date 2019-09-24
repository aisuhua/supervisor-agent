<?php
namespace SupAgent\Task;

use Mtdowling\Supervisor\EventListener;
use Mtdowling\Supervisor\EventNotification;
use SupAgent\Model\Cron;
use SupAgent\Supervisor\Supervisor;
use SupAgent\Model\CronLog;
use Zend\XmlRpc\Client\Exception\FaultException;
use SupAgent\Lock\Cron as CronLock;

class SupervisorTask extends TaskBase
{
    public function eventAction()
    {
        $listener = new EventListener();

        $listener->listen(function(EventListener $listener, EventNotification $event) {
            // 占用内存是否超过限制
            // 是否有文件发生修改

            $listener->log($event->getEventName());
            $listener->log($event->getServer());
            $listener->log($event->getPool());
            $listener->log(var_export($event->getData(), true));

            $eventData = $event->getData();
            if (!Cron::isCron($eventData['groupname']))
            {
                // 只处理定时任务事件
                return true;
            }

            if (Cron::isCron($eventData['groupname']))
            {
                $cronLock = new CronLock();

                /** @var CronLog $cronLog */
                $cronLog = CronLog::findFirst([
                    'program = :program:',
                    'bind' => [
                        'program' => $eventData['groupname']
                    ]
                ]);

                if (!$cronLog)
                {
                    $listener->log("定时任务不存在");
                    $cronLock->unlock();

                    return true;
                }

                /** @var \SupAgent\Model\Server $server */
                $server = $cronLog->getServer();
                if (!$server)
                {
                    $listener->log("服务器不存在");
                    $cronLock->unlock();

                    return true;
                }

                $supervisor = new Supervisor(
                    $server->id,
                    $server->ip,
                    $server->username,
                    $server->password,
                    $server->port
                );

                try
                {
                    $process_info = $supervisor->getProcessInfo($cronLog->getProcessName());
                }
                catch (FaultException $e) {}

                // 开始事件
                if ($eventData['eventname'] == 'PROCESS_STATE_STARTING')
                {
                    $cronLog->status = CronLog::STATUS_STARTED;
                    $cronLog->start_time = empty($process_info['start']) ? time() : $process_info['start'];
                    $cronLog->save();
                    $cronLock->unlock();

                    return true;
                }

                // 结束事件
                $cronLog->status = self::getStatusByEvent($eventData);
                $cronLog->end_time = empty($process_info['stop']) ? time() : $process_info['stop'];

                // 如果有则以最后的时间为准
                if (!empty($process_info['start']))
                {
                    $cronLog->start_time = $process_info['start'];
                }

                // $cronLog->log = (string) @file_get_contents($cronLog->getLogFile());

                // 删除进程配置
                if ($this->removeCron($supervisor, $cronLog->program))
                {
                    $cronLog->save();
                    $cronLog->truncate();
                }

                $cronLock->unlock();

                return true;
            }
        });
    }

    private static function getStatusByEvent($eventData)
    {
        if ($eventData['eventname'] == 'PROCESS_STATE_EXITED')
        {
            if ($eventData['expected'] == 1)
            {
                return CronLog::STATUS_FINISHED;
            }

            return CronLog::STATUS_FAILED;
        }

        if ($eventData['eventname'] == 'PROCESS_STATE_STOPPED')
        {
            return CronLog::STATUS_STOPPED;
        }

        if ($eventData['eventname'] == 'PROCESS_STATE_UNKNOWN')
        {
            return CronLog::STATUS_UNKNOWN;
        }

        return CronLog::STATUS_FAILED;
    }
}