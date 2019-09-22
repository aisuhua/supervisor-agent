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

class SupervisorTask extends Task
{
    protected $cron_conf = PATH_SUPERVISOR . '/conf.d/cron.conf';
    protected $command_conf = PATH_SUPERVISOR . '/conf.d/command.conf';

    public function handleEventAction()
    {
        $listener = new EventListener();

        // 处理信号量
        $signals = [
            SIGTERM => 'SIGTERM',
            SIGHUP  => 'SIGHUP',
            SIGINT  => 'SIGINT',
            SIGQUIT => 'SIGQUIT',
        ];

        $sig_handler = function ($signo) use ($signals, $listener) {
            $listener->log($signo);

            exit();
        };

        pcntl_signal(SIGTERM, $sig_handler); // kill
        pcntl_signal(SIGHUP, $sig_handler); // kill -s HUP or kill -1
        pcntl_signal(SIGINT, $sig_handler); // Ctrl-C
        pcntl_signal(SIGQUIT, $sig_handler); // kill -3

        $listener->listen(function(EventListener $listener, EventNotification $event) {
            // 处理信号量
            // 占用内存是否超过限制
            // 是否有文件发生修改

            $data = $event->getData();
            if (strpos($data['groupname'], '_supervisor_cron_') !== 0 &&
                strpos($data['groupname'], '_supervisor_command_') !== 0
            )
            {
                return true;
            }

            $listener->log($event->getEventName());
            $listener->log($event->getServer());
            $listener->log($event->getPool());
            $listener->log(var_export($event->getData(), true));

            $process_name = $data['groupname'] . ':' . $data['processname'];

            if (strpos($data['groupname'], '_supervisor_cron_') === 0)
            {
                /** @var CronLog $cronLog */
                $cronLog = CronLog::findFirst([
                    'program = :program:',
                    'bind' => [
                        'program' => $data['groupname']
                    ]
                ]);

                if (!$cronLog)
                {
                    $listener->log("定时任务不存在");
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

                try
                {
                    $process_info = $supervisor->getProcessInfo($process_name);
                }
                catch (FaultException $e)
                {
                    if ($e->getCode() == StatusCode::BAD_NAME)
                    {
                        $listener->log("进程不存在");
                        $cronLog->status = CronLog::STATUS_UNKNOWN;
                        $cronLog->save();

                        return true;
                    }

                    throw $e;
                }

                if ($process_info['statename'] == 'EXITED')
                {
                    if ($process_info['exitstatus'] == 0)
                    {
                        // 正常退出
                        $cronLog->status = CronLog::STATUS_FINISHED;
                    }
                    else
                    {
                        // 异常退出则标志为失败
                        $cronLog->status = CronLog::STATUS_FAILED;
                    }
                }
                elseif ($process_info['statename'] == 'STOPPED')
                {
                    // 被中断执行
                    $cronLog->status = CronLog::STATUS_STOPPED;
                }
                else
                {
                    // 异常退出
                    $cronLog->status = CronLog::STATUS_FAILED;
                }

                // 进程退出时间
                $cronLog->end_time = $process_info['stop'];
                // 进程日志
                $cronLog->log = $supervisor->tailProcessStdoutLog($process_name, 0, 8 * 1024 * 1024)[0];

                // 删除进程配置
                $fp = fopen($this->cron_conf, "r+");
                if ($fp === false)
                {
                    $listener->log('无法打开配置文件');
                }

                if (!flock($fp, LOCK_EX))
                {
                    $listener->log("无法获得锁");
                    return false;
                }

                $content = '';
                if ($file_size = filesize($this->cron_conf))
                {
                    $content = fread($fp,  $file_size);
                    if ($content === false)
                    {
                        $listener->log("配置读取失败");
                        return false;
                    }
                }

                if (!empty($content))
                {
                    $parsed = parse_ini_string($content, true, INI_SCANNER_RAW);
                    if ($parsed === false)
                    {
                        $listener->log("配置解析出错");
                        return false;
                    }

                    $key = "program:{$data['groupname']}";
                    if (isset($parsed[$key]))
                    {
                        unset($parsed[$key]);
                    }

                    $ini = build_ini_string($parsed);

                    ftruncate($fp, 0);
                    rewind($fp);
                    $bytes = fwrite($fp, $ini);
                    if ($bytes === false)
                    {
                        $listener->log("配置写入失败");
                        return false;
                    }
                    fflush($fp);
                }

                $supervisor->reloadConfig();

                try
                {
                    $supervisor->removeProcessGroup($data['groupname']);
                }
                catch (FaultException $e)
                {
                    if ($e->getCode() == StatusCode::BAD_NAME) {}
                    elseif($e->getCode() == StatusCode::STILL_RUNNING)
                    {
                        $listener->log("进程仍在运行");
                        return true;
                    }

                    throw $e;
                }

                flock($fp, LOCK_UN);
                fclose($fp);

                if (!unlink($process_info['stdout_logfile']))
                {
                    if (is_file($process_info['stdout_logfile']))
                    {
                        $listener->log("无法删除日志文件");
                        return false;
                    }
                }

                $cronLog->save();
            }

            return true;
        });
    }

    public function clearAction($params)
    {
        if (empty($params[0]))
        {
            print_cli("缺少 server_id 参数");
            exit(1);
        }

        $server_id = (int) $params[0];

        /** @var \SupAgent\Model\Server $server */
        $server = Server::findFirst($server_id);
        if (!$server)
        {
            print_cli("该服务器不存在");
            exit(1);
        }

        $supervisor = new Supervisor(
            $server->id,
            $server->ip,
            $server->username,
            $server->password,
            $server->port
        );

        // 修复因重启 Supervisor
        $processes = $supervisor->getAllProcessInfo();
        foreach ($processes as $process)
        {
            if (Cron::isCron($process['group']))
            {
                // 重启后的进程
                if ($process['statename'] == 'STOPPED' && $process['start'] == 0)
                {
                    $program_info = explode('_', $process['group']);
                    $id = $program_info[3];

                    /** @var CronLog $cronLog */
                    $cronLog = CronLog::findFirst($id);
                    if ($cronLog && $cronLog->status == CronLog::STATUS_STARTED)
                    {
                        $cronLog->status = CronLog::STATUS_UNKNOWN;
                        $cronLog->end_time = time();
                        $cronLog->save();
                    }

                    // 删除配置

                    // 删除进程

                }
            }
        }
    }

    private function removeCron(Supervisor $supervisor, $program)
    {
        $fp = fopen($this->cron_conf, "r+");
        if ($fp === false)
        {
            throw new Exception("无法打开配置文件：{$this->cron_conf}");
        }

        if (!flock($fp, LOCK_EX))
        {
            throw new Exception("无法获得锁：{$this->cron_conf}");
        }

        $content = '';
        if ($file_size = filesize($this->cron_conf))
        {
            $content = fread($fp, $file_size);
            $parsed = parse_ini_string($content, true, INI_SCANNER_RAW);
            if ($parsed === false)
            {
                throw new Exception("配置解析出错：{$content}");
            }

            $key = "program:{$program}";
            if (isset($parsed[$key]))
            {
                unset($parsed[$key]);
                $ini = build_ini_string($parsed);

                ftruncate($fp, 0);
                rewind($fp);
                $bytes = fwrite($fp, $ini);

                if ($bytes === false)
                {
                    throw new Exception("配置写入失败：{$ini}");
                }

                $supervisor->reloadConfig();
                $supervisor->removeProcessGroup($program);

                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
        else
        {

        }
    }
}