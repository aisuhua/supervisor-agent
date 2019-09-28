<?php
namespace SupAgent\Supervisor;

use Supervisor\Supervisor as SupervisorBase;
use SupAgent\Exception\Exception;
use Zend\XmlRpc\Client\Exception\FaultException;

class Supervisor extends SupervisorBase
{
    public function getAllProcessInfo()
    {
        return $this->rpcClient->call('supervisor.getAllProcessInfo');
    }

    public function stopProcess($name, $wait = true)
    {
        return $this->rpcClient->call('supervisor.stopProcess', array($name, $wait));
    }

    public function startProcess($name, $wait = true)
    {
        return $this->rpcClient->call('supervisor.startProcess', array($name, $wait));
    }

    public function getProcessInfo($name)
    {
        return $this->rpcClient->call('supervisor.getProcessInfo', array($name));
    }

    public function tailProcessStdoutLog($name, $offset, $length)
    {
        return $this->rpcClient->call('supervisor.tailProcessStdoutLog', array($name, $offset, $length));
    }

    public function readProcessStdoutLog($name, $offset, $length)
    {
        return $this->rpcClient->call('supervisor.readProcessStdoutLog', array($name, $offset, $length));
    }

    public function clearProcessLogs($name)
    {
        return $this->rpcClient->call('supervisor.clearProcessLogs', array($name));
    }

    public function shutdown()
    {
        return $this->rpcClient->call('supervisor.shutdown');
    }

    public function restart()
    {
        return $this->rpcClient->call('supervisor.restart');
    }

    public function reloadConfig()
    {
        return $this->rpcClient->call('supervisor.reloadConfig');
    }

    public function addProcessGroup($name)
    {
        return $this->rpcClient->call('supervisor.addProcessGroup', array($name));
    }

    public function removeProcessGroup($name)
    {
        return $this->rpcClient->call('supervisor.removeProcessGroup', array($name));
    }

    public function addProcess($conf_path, $program, $ini)
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

        $this->reloadConfig();
        $this->addProcessGroup($program);
        $this->startProcessGroup($program);

        return true;
    }

    public function removeProcess($conf_path, $program)
    {
        $content = '';
        if (file_exists($conf_path))
        {
            if (($content = file_get_contents($conf_path)) === false)
            {
                throw new Exception("无法读取文件");
            }
            $content = trim($content);
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
            $this->reloadConfig();
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
            $this->stopProcessGroup($program);
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
            $this->removeProcessGroup($program);
        }
        catch (FaultException $e)
        {
            if ($e->getCode() != StatusCode::BAD_NAME &&
                $e->getCode() != StatusCode::SHUTDOWN_STATE &&
                $e->getCode() != StatusCode::STILL_RUNNING
            )
            {
                throw $e;
            }
        }

        return true;
    }

}