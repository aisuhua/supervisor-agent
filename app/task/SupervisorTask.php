<?php
namespace SupAgent\Task;

use Phalcon\Cli\Task;
use Mtdowling\Supervisor\EventListener;
use Mtdowling\Supervisor\EventNotification;
use SupAgent\Model\Cron;
use SupAgent\Model\Server;
use SupAgent\Supervisor\StatusCode;
use SupAgent\Supervisor\Supervisor;
use SupAgent\Model\CronLog;
use Zend\XmlRpc\Client\Exception\FaultException;
use SupAgent\Exception\Exception;
use SupAgent\Lock\Cron as CronLock;

class SupervisorTask extends Task
{
    public function handleEventAction()
    {
        $listener = new EventListener();

        $listener->listen(function(EventListener $listener, EventNotification $event) {
            // 处理信号量
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
                    return true;
                }

                if ($eventData['eventname'] == 'PROCESS_STATE_STARTING')
                {
                    $cronLog->status = CronLog::STATUS_STARTED;
                    $cronLog->save();

                    return true;
                }

                /** @var \SupAgent\Model\Server $server */
                $server = $cronLog->getServer();
                if (!$server)
                {
                    $listener->log("服务器不存在");
                    return true;
                }

                $supervisor = new Supervisor(
                    $server->id,
                    '127.0.0.1',
                    $server->username,
                    $server->password,
                    $server->port
                );

                $cronLog->status = self::getStatusByEvent($eventData);
                $cronLog->end_time = time();
                $cronLog->log = (string) @file_get_contents($cronLog->getLogFile());

                // 删除进程配置
                if ($this->removeCron($supervisor, $cronLog))
                {
                    $cronLog->save();
                }

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

        return CronLog::STATUS_FAILED;
    }

    protected function removeCron(Supervisor $supervisor, CronLog $cronLog)
    {
        $cronLock = new CronLock();
        if (!$cronLock->lock())
        {
            throw new Exception("无法获得锁");
        }

        $this->removeConfig($supervisor, $cronLog->program, $cronLog->getLogFile());

        if(!$cronLock->unlock())
        {
            throw new Exception("解锁失败");
        }

        return true;
    }

    private function removeConfig(Supervisor $supervisor, $program, $log_file)
    {
        $content = file_get_contents(Server::CONF_CRON);
        if ($content === false)
        {
            throw new Exception("无法读取文件");
        }

        if (!empty($content))
        {
            $parsed = parse_ini_string($content, true, INI_SCANNER_RAW);
            if ($parsed === false)
            {
                throw new Exception("无法解析配置");
            }

            $key = "program:{$program}";
            if (isset($parsed[$key]))
            {
                unset($parsed[$key]);
                $ini = build_ini_string($parsed);

                if (file_put_contents(Server::CONF_CRON, $ini) === false)
                {
                    throw new Exception("配置写入失败");
                }
            }
        }

        try
        {
            $supervisor->reloadConfig();
            $supervisor->removeProcessGroup($program);
        }
        catch (FaultException $e)
        {
            if ($e->getCode() == StatusCode::BAD_NAME ||
                $e->getCode() == StatusCode::SHUTDOWN_STATE
            )
            {
                goto end;
            }
            elseif ($e->getCode() == StatusCode::STILL_RUNNING)
            {
                return false;
            }

            throw $e;
        }

        end:
        if (!@unlink($log_file))
        {
            if (is_file($log_file))
            {
                throw new Exception("日志文件删除失败");
            }
        }

        return true;
    }
}