<?php
namespace SupAgent\Task;

use Phalcon\Cli\Task;
use SupAgent\Model\Cron;
use SupAgent\Model\CronLog;
use Cron\CronExpression;
use SupAgent\Model\Server;
use SupAgent\Supervisor\Supervisor;
use React\EventLoop\Factory as EventLoop;
use SupAgent\Lock\Cron as CronLock;
use SupAgent\Exception\Exception;

class CronTask extends Task
{
    public function checkPerMinuteAction($params)
    {
        if (empty($params[0]))
        {
            throw new Exception('缺少 server_id 参数');
        }

        $server_id = (int) $params[0];
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

                    $program = $cron->getProgram();
                    print_cli("{$program} is starting");

                    // 添加执行日志
                    $cronLog = new CronLog();
                    $cronLog->cron_id = $cron->id;
                    $cronLog->server_id = $cron->server_id;
                    $cronLog->program = $program;
                    $cronLog->command = $cron->command;
                    $cronLog->start_time = time();
                    $cronLog->status = CronLog::STATUS_INI;
                    $cronLog->create();

                    $this->addCron($supervisor, $cron);

                    // 更新上次执行时间
                    $cron->last_time = $now->format('U');
                    $cron->save();

                    print_cli("{$program} has started");
                }
            });
        });

        $loop->run();
    }

    private function addCron(Supervisor &$supervisor, Cron &$cron)
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