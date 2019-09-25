<?php
namespace SupAgent\Task;

use SupAgent\Model\Cron;
use SupAgent\Model\CronLog;
use Cron\CronExpression;
use SupAgent\Model\Server;
use SupAgent\Supervisor\StatusCode;
use React\EventLoop\Factory as EventLoop;
use SupAgent\Exception\Exception;
use SupAgent\Supervisor\Supervisor;
use Zend\XmlRpc\Client\Exception\FaultException;
use SupAgent\Lock\Cron as CronLock;

class CronTask extends TaskBase
{
    public function startAction($params)
    {
        echo print_cli("running....");

        if (empty($params[0]))
        {
            throw new Exception('缺少 server_id 参数');
        }

        $server_id = (int) $params[0];

        // 修复进程状态
        $this->clearAction($server_id);

        $loop = EventLoop::create();

        // 每分钟启动一次
        $loop->addPeriodicTimer(60, function () use ($loop, $server_id) {
            $loop->addTimer(60 - time() % 60, function() use ($server_id) {
                $crones = Cron::find([
                    'server_id = :server_id: AND status = :status:',
                    'bind' => [
                        'server_id' => $server_id,
                        'status' => Cron::STATUS_ACTIVE
                    ]
                ]);

                if ($crones->count() == 0)
                {
                    return true;
                }

                $cronLock = new CronLock();
                $cronLock->lock();

                /** @var \SupAgent\Model\Server $server */
                $server = Server::findFirst($server_id);
                if (!$server)
                {
                    throw new Exception('该服务器不存在');
                }

                $supervisor = $server->getSupervisor();

                $now = new \DateTime();

                /** @var Cron $cron */
                foreach ($crones as $cron)
                {
                    // 判断定时任务是否应该启动
                    $cronExpression = CronExpression::factory($cron->time);
                    if (!$cronExpression->isDue($now))
                    {
                        continue;
                    }

                    // 添加执行日志
                    $cronLog = new CronLog();
                    $cronLog->cron_id = $cron->id;
                    $cronLog->server_id = $cron->server_id;
                    $cronLog->command = $cron->command;
                    $cronLog->status = CronLog::STATUS_INI;
                    $cronLog->create();

                    $program = $cron->getProgram($cronLog->id);
                    $cronLog->refresh();
                    $cronLog->program = $program;
                    $cronLog->save();

                    $this->addCron($supervisor, $program, $cron->getIni($program));

                    // 更新上次执行时间
                    $cron->last_time = $now->format('U');
                    $cron->save();

                    print_cli("{$program} started");
                }

                $cronLock->unlock();
            });
        });

        $loop->run();
    }

    /**
     * 做一些清理工作
     *
     * @param $server_id
     * @throws Exception
     */
    protected function clearAction($server_id)
    {
        // 锁定操作
        $cronLock = new CronLock();
        $cronLock->lock();

        /** @var \SupAgent\Model\Server $server */
        $server = Server::findFirst($server_id);
        if (!$server)
        {
            throw new Exception('该服务器不存在');
        }

        $supervisor = $server->getSupervisor();

        $this->clearCronLog($server, $supervisor);
        $this->clearProcess($server, $supervisor);
        $this->clearConfig($server, $supervisor);
        $this->clearLog($server);

        $cronLock->unlock();
    }

    /**
     * 清理不一致的定时任务日志
     *
     * @param Server $server
     * @param Supervisor $supervisor
     */
    protected function clearCronLog(Server &$server, Supervisor &$supervisor)
    {
        $server_id = $server->id;

        // 修复定时任务状态
        $cronLogs = CronLog::find([
            'server_id = :server_id: AND status IN({status:array})',
            'bind' => [
                'server_id' => $server_id,
                'status' => [
                    CronLog::STATUS_INI,
                    CronLog::STATUS_STARTED
                ]
            ]
        ]);

        /** @var CronLog $cronLog */
        foreach ($cronLogs as $cronLog)
        {
            try
            {
                // 有可能不存在
                $process_info = $supervisor->getProcessInfo($cronLog->getProcessName());

                if (in_array($process_info['statename'], ['STARTING', 'RUNNING', 'STOPPING']))
                {
                    continue;
                }

                // 停止后还没有超过10秒则跳过
                if ($process_info['stop'] > 0 &&  time() - $process_info['stop'] < 10)
                {
                    continue;
                }

                // 处理进程已退出的几种情况
                if ($process_info['statename'] == 'EXITED')
                {
                    $cronLog->status = $process_info['exitstatus'] == 0 ?
                        CronLog::STATUS_FINISHED :
                        CronLog::STATUS_FAILED;
                    $cronLog->end_time = $process_info['stop'];
                }
                elseif ($process_info['statename'] == 'STOPPED')
                {
                    $cronLog->status = CronLog::STATUS_STOPPED;
                    $cronLog->end_time = $process_info['stop'] > 0 ? $process_info['stop'] : time();
                }
                elseif ($process_info['statename'] == 'UNKNOWN')
                {
                    $cronLog->status = CronLog::STATUS_UNKNOWN;
                    $cronLog->end_time = $process_info['stop'];
                }
                elseif (in_array($process_info['statename'], ['FATAL']))
                {
                    $cronLog->status = CronLog::STATUS_FAILED;
                    $cronLog->end_time = $process_info['stop'];
                }

                // 以最后状态时间为准
                if ($process_info['start'] > 0)
                {
                    $cronLog->start_time = $process_info['start'];
                }
            }
            catch (FaultException $e)
            {
                if ($e->getCode() != StatusCode::BAD_NAME)
                {
                    throw $e;
                }

                // 进程不存在
                $cronLog->status = CronLog::STATUS_UNKNOWN;
                $cronLog->end_time = time();
            }

            if ($this->removeCron($supervisor, $cronLog->program))
            {
                $cronLog->save();
                $cronLog->truncate();

                print_cli("{$cronLog->program} record fixed");
            }
        }
    }

    /**
     * 清理僵尸进程
     *
     * @param Server $server
     * @param Supervisor $supervisor
     */
    protected function clearProcess(Server &$server, Supervisor &$supervisor)
    {
        $server_id = $server->id;

        // 修复进程状态
        $processes = $supervisor->getAllProcessInfo();

        foreach ($processes as $process)
        {
            if (in_array($process['statename'], ['STARTING', 'RUNNING', 'STOPPING']))
            {
                continue;
            }

            // 停止后还没有超过10秒则跳过
            if ($process['stop'] > 0 &&  time() - $process['stop'] < 10)
            {
                continue;
            }

            if (Cron::isCron($process['group']))
            {
                $cronLog = CronLog::findFirst([
                    'server_id = :server_id: AND program = :program:',
                    'bind' => [
                        'server_id' => $server_id,
                        'program' => $process['group']
                    ]
                ]);

                if (!$cronLog)
                {
                    // 进程找不到对应的记录则删除
                    $this->removeCron($supervisor, $process['group']);
                    @unlink($process['stdout_logfile']);
                }
                else
                {
                    // 如果存在记录则表明该记录状态已经是已完成
                    // 删除该进程
                    $this->removeCron($supervisor, $process['group']);
                }

                print_cli("{$process['group']} process removed");
            }
        }
    }

    /**
     * 清理不一致的配置
     *
     * @param Server $server
     * @param Supervisor $supervisor
     * @throws Exception
     */
    protected function clearConfig(Server &$server, Supervisor &$supervisor)
    {
        $server_id = $server->id;

        // 检查配置是否有多余的项
        $content = trim(file_get_contents(Server::CONF_CRON));
        if ($content === false)
        {
            throw new Exception("无法读取文件");
        }

        $parsed = parse_ini_string($content, true, INI_SCANNER_RAW);
        if ($parsed === false)
        {
            throw new Exception("无法解析配置");
        }

        $origin = $parsed;
        foreach ($parsed as $key => $value)
        {
            $program = explode(':', $key)[1];

            $cronLog = CronLog::findFirst([
                "server_id = :server_id: AND program = :program:",
                'bind' => [
                    'server_id' => $server_id,
                    'program' => $program
                ]
            ]);

            if (!$cronLog)
            {
                // 删除多余的配置项
                if ($this->removeCron($supervisor, $program))
                {
                    unset($parsed[$key]);

                    print_cli("{$program} removed from " . Server::CONF_CRON);
                }
            }
        }

        if (count($origin) != $parsed)
        {
            $ini = build_ini_string($parsed);
            if (file_put_contents(Server::CONF_CRON, $ini) === false)
            {
                throw new Exception("配置写入失败");
            }
        }
    }

    /**
     * 清理不一致的日志文件
     *
     * @param Server $server
     */
    public function clearLog(Server &$server)
    {
        $server_id = $server->id;

        $delete_files = [];
        $cron_files = [];

        $files = scandir(PATH_SUPERVISOR_LOG, 1);
        foreach ($files as $file)
        {
            if (Cron::isCron($file))
            {
                $key = explode('_', $file)[3];
                $cron_files[$key][] = $file;

                if (count($cron_files[$key]) > Cron::LOG_SIZE)
                {
                    $delete_files[] = $file;
                }
            }
        }

        if (!empty($cron_files))
        {
            $cron_ids = array_keys($cron_files);

            $cron = Cron::find([
                'server_id = :server_id: AND id IN({ids:array})',
                'bind' => [
                    'server_id' => $server_id,
                    'ids' => $cron_ids
                ],
                'columns' => 'id'
            ]);

            $delete_ids = array_diff($cron_ids, array_column($cron->toArray(), 'id'));
            if (!empty($delete_ids))
            {
                foreach ($delete_ids as $delete_id)
                {
                    $delete_files = array_merge($delete_files, $cron_files[$delete_id]);
                }
            }
        }

        if (!empty($delete_files))
        {
            foreach ($delete_files as $delete_file)
            {
                $file_path = PATH_SUPERVISOR_LOG . '/' . $delete_file;
                if (@unlink($file_path))
                {
                    print_cli("{$file_path} deleted");
                }
            }
        }
    }
}