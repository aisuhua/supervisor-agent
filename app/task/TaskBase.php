<?php
namespace SupAgent\Task;

use Phalcon\Cli\Task;
use SupAgent\Model\Cron;
use SupAgent\Model\CronLog;
use SupAgent\Model\Server;
use SupAgent\Supervisor\StatusCode;
use SupAgent\Supervisor\Supervisor;
use SupAgent\Lock\Cron as CronLock;
use SupAgent\Exception\Exception;
use Zend\XmlRpc\Client\Exception\FaultException;

class TaskBase extends Task
{
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

    protected function removeConfig(Supervisor $supervisor, $program, $log_file)
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

    protected function addCron(Supervisor &$supervisor, Cron &$cron)
    {
        // 锁定配置
        $cronLock = new CronLock();
        if (!$cronLock->lock())
        {
            throw new Exception("无法获得锁");
        }

        // 写入配置
        if (file_put_contents(Server::CONF_CRON, $cron->getIni(), FILE_APPEND) === false)
        {
            throw new Exception("无法写入配置");
        }

        // 重载配置
        $supervisor->reloadConfig();
        $supervisor->addProcessGroup($cron->getProgram());
        $supervisor->startProcessGroup($cron->getProgram());

        // 解锁
        if(!$cronLock->unlock())
        {
            throw new Exception("解锁失败");
        }
    }
}