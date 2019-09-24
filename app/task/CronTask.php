<?php
namespace SupAgent\Task;

use SupAgent\Model\Cron;
use SupAgent\Model\CronLog;
use Cron\CronExpression;
use SupAgent\Model\Server;
use SupAgent\Supervisor\StatusCode;
use SupAgent\Supervisor\Supervisor;
use React\EventLoop\Factory as EventLoop;
use SupAgent\Exception\Exception;
use Zend\XmlRpc\Client\Exception\FaultException;

class CronTask extends TaskBase
{
    public function startAction($params)
    {
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

                /** @var \SupAgent\Model\Server $server */
                $server = Server::findFirst($server_id);
                if (!$server)
                {
                    throw new Exception('该服务器不存在');
                }

                $supervisor = new Supervisor(
                    $server->id,
                    $server->ip,
                    $server->username,
                    $server->password,
                    $server->port
                );

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

                    $program = $cron->getProgram($now->format('YmdHi'));
                    print_cli("{$program} is starting");

                    // 添加执行日志
                    $cronLog = new CronLog();
                    $cronLog->cron_id = $cron->id;
                    $cronLog->server_id = $cron->server_id;
                    $cronLog->program = $program;
                    $cronLog->command = $cron->command;
                    $cronLog->status = CronLog::STATUS_INI;
                    $cronLog->create();

                    $this->addCron($supervisor, $program, $cron);

                    // 更新上次执行时间
                    $cron->last_time = $now->format('U');
                    $cron->save();

                    print_cli("{$program} has started");
                }
            });
        });

        $loop->run();
    }

    // 做一些清理工作
    protected function clearAction($server_id)
    {
        /** @var \SupAgent\Model\Server $server */
        $server = Server::findFirst($server_id);
        if (!$server)
        {
            throw new Exception('该服务器不存在');
        }

        $supervisor = new Supervisor(
            $server->id,
            $server->ip,
            $server->username,
            $server->password,
            $server->port
        );

        // 修复进程状态
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

                // 进程退出 10 秒后仍未更新状态则认为需处理
                if (time() - $process_info['stop'] < 10)
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
                    if ($process_info['start'] == 0 || $cronLog->status == CronLog::STATUS_INI)
                    {
                        $cronLog->status = CronLog::STATUS_UNKNOWN;
                    }
                    else
                    {
                        $cronLog->status = CronLog::STATUS_STOPPED;
                    }

                    $cronLog->end_time = $process_info['stop'];
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

                if ($this->removeCron($supervisor, $cronLog))
                {
                    $cronLog->save();
                    $cronLog->truncate();
                }
            }
            catch (FaultException $e)
            {

            }
        }
    }
}