<?php
namespace SupAgent\Task;

use Phalcon\Cli\Task;
use SupAgent\Model\Server;
use SupAgent\Supervisor\StatusCode;
use SupAgent\Supervisor\Supervisor;
use SupAgent\Exception\Exception;
use Zend\XmlRpc\Client\Exception\FaultException;

class TaskBase extends Task
{
    protected function removeCron(Supervisor &$supervisor, $program)
    {
        return $this->removeConfig($supervisor, Server::CONF_CRON, $program);
    }

    protected function removeCommand(Supervisor &$supervisor, $program)
    {
        return $this->removeConfig($supervisor, Server::CONF_COMMAND, $program);
    }

    protected function removeConfig(Supervisor &$supervisor, $conf_path, $program)
    {
        $content = '';
        if (file_exists($conf_path))
        {
            $content = trim(file_get_contents($conf_path));
            if ($content === false)
            {
                throw new Exception("无法读取文件");
            }
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

                if (file_put_contents($conf_path, $ini) === false)
                {
                    throw new Exception("配置写入失败");
                }
            }
        }

        try
        {
            $supervisor->reloadConfig();
        }
        catch (FaultException $e)
        {
            if ($e->getCode() != StatusCode::SHUTDOWN_STATE)
            {
                throw $e;
            }
        }

        try
        {
            $supervisor->stopProcessGroup($program);
        }
        catch (FaultException $e)
        {
            if ($e->getCode() != StatusCode::BAD_NAME &&
                $e->getCode() != StatusCode::NOT_RUNNING &&
                $e->getCode() != StatusCode::SHUTDOWN_STATE
            )
            {
                throw $e;
            }
        }

        try
        {
            $supervisor->removeProcessGroup($program);
        }
        catch (FaultException $e)
        {
            if ($e->getCode() == StatusCode::BAD_NAME ||
                $e->getCode() == StatusCode::SHUTDOWN_STATE
            )
            {
                return true;
            }
            elseif ($e->getCode() == StatusCode::STILL_RUNNING)
            {
                return false;
            }

            throw $e;
        }

        return true;
    }

    protected function addCron(Supervisor &$supervisor, $program, $ini)
    {
        return $this->addConfig($supervisor, Server::CONF_CRON, $program, $ini);
    }

    public function addConfig(Supervisor &$supervisor, $conf_path, $program, $ini)
    {
        $content = '';
        if (file_exists($conf_path))
        {
            $content = trim(file_get_contents($conf_path));
            if ($content === false)
            {
                throw new Exception("无法读取文件");
            }
        }

        if (!empty($content))
        {
            $ini = $content  . PHP_EOL . $ini . PHP_EOL;
        }

        if (file_put_contents($conf_path, $ini) === false)
        {
            throw new Exception("无法写入配置");
        }

        $supervisor->reloadConfig();
        $supervisor->addProcessGroup($program);
        $supervisor->startProcessGroup($program);

        return true;
    }
}