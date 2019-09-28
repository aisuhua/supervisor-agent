<?php
namespace SupAgent\Model;

use Phalcon\Mvc\Model;

abstract class ProcessAbstract extends Model
{
    const STATUS_INI = 0; // 初始化状态
    const STATUS_STARTED = 1; // 已启动
    const STATUS_FINISHED = 2; // 已正常完成
    const STATUS_FAILED = -1; // 没有正常退出
    const STATUS_UNKNOWN = -2; // 无法确定进程的执行状态
    const STATUS_STOPPED = -3; // 被中断

    public $program;
    public $command;
    public $user;
    public $status;
    public $start_time;
    public $end_time;

    public static function getPathConf() {}

    abstract public function getProgram();
    abstract public function getLogFile();
    /** @return Server */
    abstract public function getServer();
    abstract public function truncate();

    public function getProcessName()
    {
        return $this->program . ':' . $this->program . '_0';
    }

    public function getIni()
    {
        $ini = '';
        $ini .= "[program:{$this->getProgram()}]" . PHP_EOL;
        $ini .= "command={$this->command}" . PHP_EOL;
        $ini .= "process_name=%(program_name)s_%(process_num)s" . PHP_EOL;
        $ini .= "numprocs=1" . PHP_EOL;
        $ini .= "numprocs_start=0" . PHP_EOL;
        $ini .= "user={$this->user}" . PHP_EOL;
        $ini .= "directory=%(here)s" . PHP_EOL;
        $ini .= "startsecs=0" . PHP_EOL;
        $ini .= "autostart=false" . PHP_EOL;
        $ini .= "startretries=0" . PHP_EOL;
        $ini .= "autorestart=false" . PHP_EOL;
        $ini .= "redirect_stderr=true" . PHP_EOL;
        $ini .= "stdout_logfile=" . $this->getLogFile() . PHP_EOL;
        $ini .= "stdout_logfile_backups=0" . PHP_EOL;
        $ini .= "stdout_logfile_maxbytes=50MB";

        return $ini;
    }

    public function hasFinished()
    {
        if (in_array($this->status, [
            self::STATUS_FAILED,
            self::STATUS_UNKNOWN,
            self::STATUS_STOPPED,
            self::STATUS_FINISHED
        ]))
        {
            return true;
        }

        return false;
    }

    public static function getStateByProcessInfo(&$process_info)
    {
        if (in_array($process_info['statename'], ['STARTING', 'RUNNING', 'STOPPING']))
        {
            return self::STATUS_STARTED;
        }
        elseif ($process_info['statename'] == 'EXITED')
        {
            return $process_info['exitstatus'] == 0 ?
                self::STATUS_FINISHED :
                self::STATUS_FAILED;
        }
        elseif ($process_info['statename'] == 'STOPPED')
        {
            return self::STATUS_STOPPED;
        }
        elseif ($process_info['statename'] == 'UNKNOWN')
        {
            return self::STATUS_UNKNOWN;
        }
        elseif ($process_info['statename'] == 'FATAL')
        {
            return self::STATUS_FAILED;
        }
        else
        {
            return self::STATUS_UNKNOWN;
        }
    }

    public static function getStateByEventData($eventData)
    {
        if ($eventData['eventname'] == 'PROCESS_STATE_STARTING')
        {
            return self::STATUS_STARTED;
        }

        if ($eventData['eventname'] == 'PROCESS_STATE_EXITED')
        {
            if ($eventData['expected'] == 1)
            {
                return self::STATUS_FINISHED;
            }

            return self::STATUS_FAILED;
        }

        if ($eventData['eventname'] == 'PROCESS_STATE_STOPPED')
        {
            return self::STATUS_STOPPED;
        }

        if ($eventData['eventname'] == 'PROCESS_STATE_UNKNOWN')
        {
            return self::STATUS_UNKNOWN;
        }

        return ProcessAbstract::STATUS_FAILED;
    }
}