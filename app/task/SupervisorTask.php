<?php
namespace SupAgent\Task;

use Mtdowling\Supervisor\EventListener;
use Mtdowling\Supervisor\EventNotification;
use SupAgent\Model\Command;
use SupAgent\Model\Cron;
use SupAgent\Supervisor\Supervisor;
use SupAgent\Model\CronLog;
use Zend\XmlRpc\Client\Exception\FaultException;
use SupAgent\Lock\Cron as CronLock;
use SupAgent\Lock\Command as CommandLock;
use SupAgent\Model\ProcessAbstract;

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

            if (CronLog::isCron($eventData['groupname']))
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

                $success = $this->handleProcess($cronLog, $eventData, $listener);
                $cronLog->truncate();
                $cronLock->unlock();

                return $success;
            }
            elseif (Command::isCommand($eventData['groupname']))
            {
                $commandLock = new CommandLock();
                $commandLock->lock();

                /** @var Command $command */
                $command = Command::findFirst([
                    'program = :program:',
                    'bind' => [
                        'program' => $eventData['groupname']
                    ]
                ]);

                if (!$command)
                {
                    $listener->log("该命令执行记录不存在");
                    $commandLock->unlock();

                    return true;
                }

                $success = $this->handleProcess($command, $eventData, $listener);
                $commandLock->unlock();

                return $success;
            }

            return true;
        });
    }

    protected function handleProcess(ProcessAbstract $process, $eventData, EventListener $listener)
    {
        $server = $process->getServer();
        if (!$server)
        {
            $listener->log("服务器不存在");
            return true;
        }

        $supervisor = $server->getSupervisor();

        try
        {
            $process_info = $supervisor->getProcessInfo($process->getProcessName());
        }
        catch (FaultException $e) {}

        $status = self::getStatusByEvent($eventData);

        // 开始事件
        if ($status == ProcessAbstract::STATUS_STARTED)
        {
            $process->status = ProcessAbstract::STATUS_STARTED;
            $process->start_time = empty($process_info['start']) ? time() : $process_info['start'];
            $process->save();
            return true;
        }

        // 结束事件
        $process->status = $status;
        $process->end_time = empty($process_info['stop']) ? time() : $process_info['stop'];

        // 如果有则以最后的时间为准
        if (!empty($process_info['start']))
        {
            $process->start_time = $process_info['start'];
        }

        // 删除进程配置
        $supervisor->removeProcess($process->getPathConf(), $process->program);

        // 保存进程最终状态
        return $process->save();
    }

    protected static function getStatusByEvent($eventData)
    {
        if ($eventData['eventname'] == 'PROCESS_STATE_STARTING')
        {
            return ProcessAbstract::STATUS_STARTED;
        }

        if ($eventData['eventname'] == 'PROCESS_STATE_EXITED')
        {
            if ($eventData['expected'] == 1)
            {
                return ProcessAbstract::STATUS_FINISHED;
            }

            return ProcessAbstract::STATUS_FAILED;
        }

        if ($eventData['eventname'] == 'PROCESS_STATE_STOPPED')
        {
            return ProcessAbstract::STATUS_STOPPED;
        }

        if ($eventData['eventname'] == 'PROCESS_STATE_UNKNOWN')
        {
            return ProcessAbstract::STATUS_UNKNOWN;
        }

        return ProcessAbstract::STATUS_FAILED;
    }
}