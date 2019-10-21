<?php
namespace SupAgent\Task;

use Phalcon\Cli\Task;
use Mtdowling\Supervisor\EventListener;
use Mtdowling\Supervisor\EventNotification;
use SupAgent\Model\Command;
use SupAgent\Model\CronLog;
use Zend\XmlRpc\Client\Exception\FaultException;
use SupAgent\Lock\Cron as CronLock;
use SupAgent\Lock\Command as CommandLock;
use SupAgent\Model\ProcessAbstract;
use SupAgent\Library\Version;

class SupervisorTask extends TaskBase
{
    public function eventAction()
    {
        $version = new Version();
        $start_time = time();
        $listener = new EventListener();

        $listener->listen(function(EventListener $listener, EventNotification $event) use (&$version, $start_time) {

            $this->checkBeforeNext($version, $start_time);

            $listener->log($event->getEventName());
            $listener->log($event->getServer());
            $listener->log($event->getPool());
            $listener->log(var_export($event->getData(), true));

            $eventData = $event->getData();

            if (CronLog::isCron($eventData['groupname']))
            {
                $cronLock = new CronLock();

                $program_info = CronLog::parseProgram($eventData['groupname']);
                /** @var CronLog $cronLog */
                $cronLog = CronLog::findFirst($program_info['id']);

                if (!$cronLog)
                {
                    $listener->log("定时任务不存在");
                    $cronLock->unlock();

                    return true;
                }

                $success = $this->handleProcess($cronLog, $eventData, $listener);
                $cronLock->unlock();

                return $success;
            }
            elseif (Command::isCommand($eventData['groupname']))
            {
                $commandLock = new CommandLock();
                $commandLock->lock();

                $program_info = Command::parseProgram($eventData['groupname']);
                /** @var Command $command */
                $command = Command::findFirst($program_info['id']);

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

        $status = ProcessAbstract::getStateByEventData($eventData);

        // 开始事件
        if ($status == ProcessAbstract::STATUS_STARTED)
        {
            $process->status = $status;
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
        $supervisor->removeProcess($process::getPathConf(), $process->program);

        // 清理进程日志
        $process->truncate();

        // 保存进程最终状态
        return $process->save();
    }
}