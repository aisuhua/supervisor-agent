<?php
namespace SupAgent\Task;

use Phalcon\Cli\Task;
use SupAgent\Model\Cron;
use SupAgent\Model\CronLog;
use Cron\CronExpression;
use SupAgent\Model\Server;
use SupAgent\Supervisor\Supervisor;
use React\EventLoop\Factory as EventLoop;

class CronTask extends Task
{
    protected $cron_conf = PATH_SUPERVISOR . '/conf.d/cron.conf';

    public function checkPerMinuteAction($params)
    {
        if (empty($params[0]))
        {
            print_cli("缺少 server_id 参数");
            exit(1);
        }

        $server_id = (int) $params[0];

        // 检查配置文件是否存在
        if (!is_file($this->cron_conf))
        {
            if (!touch($this->cron_conf))
            {
                print_cli("{$this->cron_conf} 创建失败");
                exit(1);
            }
        }
        else
        {
            if (!is_writable($this->cron_conf))
            {
                print_cli("{$this->cron_conf} 无法写入");
                exit(1);
            }
        }

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

                $now = new \DateTime();
                $current_datetime = $now->format('YmdHi');
                $current_time = (new \DateTime($current_datetime))->format('U');

                /** @var Cron $cron */
                foreach ($crones as $cron)
                {
                    // 判断定时任务是否应该启动
                    $cronExpression = CronExpression::factory($cron->time);
                    if (!$cronExpression->isDue($now))
                    {
                        continue;
                    }

                    $program = $cron->getProgram($current_datetime);
                    print_cli("{$program} is starting");

                    $ini = $this->makeIni($program, $cron);
                    $this->addCron($supervisor, $program, $ini);

                    // 添加执行日志
                    $cronLog = new CronLog();
                    $cronLog->cron_id = $cron->id;
                    $cronLog->server_id = $cron->server_id;
                    $cronLog->program = $program;
                    $cronLog->command = $cron->command;
                    $cronLog->start_time = time();
                    $cronLog->status = CronLog::STATUS_STARTED;
                    $cronLog->create();

                    // 更新上次执行时间
                    $cron->last_time = $current_time;
                    $cron->save();

                    print_cli("{$program} has started");
                }
            });
        });

        $loop->run();
    }

    private function addCron(Supervisor $supervisor, $program, $ini)
    {
        // 锁定配置
        $fp = fopen($this->cron_conf, "a+");
        if ($fp === false)
        {
            print_cli('无法打开配置文件');
            return false;
        }

        if (!flock($fp, LOCK_EX))
        {
            print_cli("无法获得锁");
            return false;
        }

        // 写入配置
        $bytes = fwrite($fp, $ini);
        if ($bytes === false)
        {
            print_cli("配置写入失败");
            return false;
        }
        fflush($fp);

        // 重载配置
        $supervisor->reloadConfig();
        $supervisor->addProcessGroup($program);
        $supervisor->startProcessGroup($program);

        // 解锁
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private function makeIni($program, Cron &$cron)
    {
        $ini = '';
        $ini .= "[program:{$program}]" . PHP_EOL;
        $ini .= "command={$cron->command}" . PHP_EOL;
        $ini .= "numprocs=1" . PHP_EOL;
        $ini .= "numprocs_start=0" . PHP_EOL;
        $ini .= "process_name=%(program_name)s_%(process_num)s" . PHP_EOL;
        $ini .= "user={$cron->user}" . PHP_EOL;
        $ini .= "directory=%(here)s" . PHP_EOL;
        $ini .= "startsecs=0" . PHP_EOL;
        $ini .= "autostart=false" . PHP_EOL;
        $ini .= "startretries=0" . PHP_EOL;
        $ini .= "autorestart=false" . PHP_EOL;
        $ini .= "redirect_stderr=true" . PHP_EOL;
        $ini .= "stdout_logfile=AUTO" . PHP_EOL;
        $ini .= "stdout_logfile_backups=0" . PHP_EOL;
        $ini .= "stdout_logfile_maxbytes=50MB" . PHP_EOL;

        return $ini;
    }
}