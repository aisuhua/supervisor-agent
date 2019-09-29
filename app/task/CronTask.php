<?php
namespace SupAgent\Task;

use Phalcon\Cli\Task;
use SupAgent\Model\Command;
use SupAgent\Model\Cron;
use SupAgent\Model\CronLog;
use Cron\CronExpression;
use SupAgent\Model\ProcessAbstract;
use SupAgent\Model\Server;
use SupAgent\Supervisor\StatusCode;
use React\EventLoop\Factory as EventLoop;
use SupAgent\Exception\Exception;
use SupAgent\Supervisor\Supervisor;
use Zend\XmlRpc\Client\Exception\FaultException;
use SupAgent\Lock\Cron as CronLock;
use SupAgent\Lock\Command as CommandLock;

class CronTask extends Task
{
    public function startAction($params)
    {
        echo print_cli("running....");

        if (empty($params[0]))
        {
            throw new Exception('缺少 server_id 参数');
        }

        $server_id = (int) $params[0];

        // 执行一些清理工作
        $this->clearAction($server_id);

        // 每分钟启动一次
        $loop = EventLoop::create();
        $loop->addPeriodicTimer(60, function () use ($loop, $server_id) {
            $loop->addTimer(60 - time() % 60, function() use ($server_id) {
                /** @var \SupAgent\Model\Server $server */
                $server = Server::findFirst($server_id);
                if (!$server)
                {
                    throw new Exception('该服务器不存在');
                }

                $supervisor = $server->getSupervisor();

                // 定时任务列表
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
                    $cronLog->user = $cron->user;
                    $cronLog->status = CronLog::STATUS_INI;
                    $cronLog->create();

                    $cronLog->refresh();
                    $program = $cronLog->getProgram();
                    $cronLog->program = $program;
                    $cronLog->save();

                    $supervisor->addProcess(CronLog::getPathConf(), $program, $cronLog->getIni());

                    // 更新上次执行时间
                    $cron->last_time = $now->format('U');
                    $cron->save();

                    print_cli("{$program} started");
                }

                $cronLock->unlock();

                echo size_format(memory_get_usage(true)), PHP_EOL;
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
        $commandLock = new CommandLock();
        $commandLock->lock();

        /** @var \SupAgent\Model\Server $server */
        $server = Server::findFirst($server_id);
        if (!$server)
        {
            throw new Exception('该服务器不存在');
        }

        $this->fixState($server);
        $this->clearProcess($server);
        $this->clearConfig($server, CronLog::getPathConf());
        $this->clearConfig($server, Command::getPathConf());
        $this->clearCronLogFiles($server);

        $cronLock->unlock();
        $commandLock->unlock();
    }

    /**
     * 清理不一致的定时任务日志
     *
     * @param Server $server
     */
    protected function fixState(Server &$server)
    {
        $supervisor = $server->getSupervisor();

        // 定时任务
        $cronLogs = CronLog::find([
            'server_id = :server_id: AND status IN({status:array})',
            'bind' => [
                'server_id' => $server->id,
                'status' => [
                    CronLog::STATUS_INI,
                    CronLog::STATUS_STARTED
                ]
            ]
        ]);

        /** @var CronLog $cronLog */
        foreach ($cronLogs as $cronLog)
        {
            self::fixProcessState($supervisor, $cronLog);
        }

        // 执行命令
        $commands = Command::find([
            'server_id = :server_id: AND status IN({status:array})',
            'bind' => [
                'server_id' => $server->id,
                'status' => [
                    CronLog::STATUS_INI,
                    CronLog::STATUS_STARTED
                ]
            ]
        ]);

        /** @var Command $command */
        foreach ($commands as $command)
        {
            self::fixProcessState($supervisor, $command);
        }
    }

    private function fixProcessState(Supervisor &$supervisor, ProcessAbstract &$process)
    {
        try
        {
            // 有可能不存在
            $process_info = $supervisor->getProcessInfo($process->getProcessName());

            $status = ProcessAbstract::getStateByProcessInfo($process_info);
            if ($status == ProcessAbstract::STATUS_STARTED)
            {
                return true;
            }

            // 停止后还没有超过5秒则跳过
            if ($process_info['stop'] > 0 &&  time() - $process_info['stop'] < 5)
            {
                return true;
            }

            $process->status = $status;
            $process->end_time = $process_info['stop'] > 0 ? $process_info['stop'] : time();

            // 以最后状态时间为准
            if ($process_info['start'] > 0)
            {
                $process->start_time = $process_info['start'];
            }
        }
        catch (FaultException $e)
        {
            if ($e->getCode() != StatusCode::BAD_NAME)
            {
                throw $e;
            }

            // 进程不存在
            $process->status = ProcessAbstract::STATUS_UNKNOWN;
            $process->end_time = time();
        }

        /** @var ProcessAbstract $class */
        $class = get_class($process);
        $supervisor->removeProcess($class::getPathConf(), $process->program);

        $process->save();

        print_cli("{$process->program} record fixed");
    }

    /**
     * 清理僵尸进程
     *
     * @param Server $server
     */
    protected function clearProcess(Server &$server)
    {
        $supervisor = $server->getSupervisor();

        // 修复进程状态
        $processes = $supervisor->getAllProcessInfo();

        foreach ($processes as $process)
        {
            $status = ProcessAbstract::getStateByProcessInfo($process);
            if ($status == ProcessAbstract::STATUS_STARTED)
            {
                continue;
            }

            // 停止后还没有超过5秒则跳过
            if ($process['stop'] > 0 &&  time() - $process['stop'] < 5)
            {
                continue;
            }

            // 删除没有任何对应记录或已完成的进程
            if (CronLog::isCron($process['group']))
            {
                $program_info = CronLog::parseProgram($process['group']);
                /** @var CronLog $cronLog */
                $cronLog = CronLog::findFirst($program_info['id']);

                if (!$cronLog || $cronLog->hasFinished())
                {
                    $supervisor->removeProcess(CronLog::getPathConf(), $process['group']);
                    @unlink($process['stdout_logfile']);
                }

                print_cli("{$process['group']} process removed");
            }
            elseif (Command::isCommand($process['group']))
            {
                $program_info = Command::parseProgram($process['group']);
                /** @var Command $command */
                $command = Command::findFirst($program_info['id']);

                if (!$command || $command->hasFinished())
                {
                    $supervisor->removeProcess(Command::getPathConf(), $process['group']);
                    @unlink($process['stdout_logfile']);
                }

                print_cli("{$process['group']} process removed");
            }
        }
    }

    protected function clearConfig(Server &$server, $conf_path)
    {
        $supervisor = $server->getSupervisor();

        // 检查配置是否有多余的项
        if (!is_file($conf_path))
        {
            return true;
        }

        if (($content = file_get_contents($conf_path)) === false)
        {
            throw new Exception("无法读取文件");
        }
        $content = trim($content);

        $parsed = parse_ini_string($content, true, INI_SCANNER_RAW);
        if ($parsed === false)
        {
            throw new Exception("无法解析配置");
        }

        $origin = $parsed;
        foreach ($parsed as $key => $value)
        {
            $program = explode(':', $key)[1];

            if (CronLog::isCron($program))
            {
                $program_info = CronLog::parseProgram($program);
                $cronLog = CronLog::findFirst($program_info['id']);

                if (!$cronLog)
                {
                    if ($supervisor->removeProcess($conf_path, $program))
                    {
                        unset($parsed[$key]);
                        print_cli("{$program} removed from {$conf_path}");
                    }
                }
            }
            elseif (Command::isCommand($program))
            {
                $program_info = Command::parseProgram($program);
                $command = Command::findFirst($program_info['id']);

                if (!$command)
                {
                    if ($supervisor->removeProcess($conf_path, $program))
                    {
                        unset($parsed[$key]);
                        print_cli("{$program} removed from {$conf_path}");
                    }
                }
            }
        }

        if (count($origin) != $parsed)
        {
            $ini = build_ini_string($parsed);
            if (file_put_contents($conf_path, $ini) === false)
            {
                throw new Exception("配置写入失败");
            }
        }

        return true;
    }

    /**
     * 清理定时任务日志
     *
     * @param Server $server
     */
    protected function clearCronLogFiles(Server &$server)
    {
        // 删除过多的记录
        $crones = Cron::find([
            'server_id = :server_id:',
            'bind' => [
                'server_id' => $server->id
            ]
        ]);

        $cron_ids = [];
        /** @var Cron $cron */
        foreach ($crones as $cron)
        {
            $cron->truncate();
            $cron_ids[] = $cron->id;
        }

        // 删除无效的日志文件
        $cron_files = [];
        $delete_files = [];

        $files = scandir(PATH_SUPERVISOR_LOG_CRON, 1);
        foreach ($files as $file)
        {
            if (CronLog::isCron($file))
            {
                $program_info = CronLog::parseProgram(trim($file, '.log'));
                $cron_id = $program_info['id'];
                $cron_files[$cron_id][] = $file;
            }
        }

        if (!empty($cron_files))
        {
            $file_cron_ids = array_keys($cron_files);
            $delete_ids = array_diff($file_cron_ids, array_column($crones->toArray(), 'id'));
            if (!empty($delete_ids))
            {
                foreach ($delete_ids as $delete_id)
                {
                    $delete_files = $cron_files[$delete_id];
                }
            }
        }

        if (!empty($delete_files))
        {
            foreach ($delete_files as $delete_file)
            {
                $file_path = PATH_SUPERVISOR_LOG_CRON . '/' . $delete_file;
                if (@unlink($file_path))
                {
                    print_cli("{$file_path} deleted");
                }
            }
        }
    }
}