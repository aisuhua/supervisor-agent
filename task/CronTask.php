<?php
namespace SupAgent\Task;

use Phalcon\Cli\Task;
use SupAgent\Model\Cron;
use SupAgent\Model\CronLog;
use Cron\CronExpression;
use SupAgent\Model\Server;
use SupAgent\Supervisor\Supervisor;


class CronTask extends Task
{
    protected $cron_conf = PATH_SUPERVISOR_CONFIG . '/cron.conf';

    public function checkPerMinuteAction($params)
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

        while (true)
        {
            if (((int) date('s')) != 0)
            {
                sleep(1);
                continue;
            }

            $crones = Cron::find([
                'server_id = :server_id: AND status = :status:',
                'bind' => [
                    'server_id' => $server_id,
                    'status' => Cron::STATUS_ACTIVE
                ]
            ]);
            if (empty($crones))
            {
                continue;
            }

            $start_time = time();
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

                // 锁定配置
                $fp = fopen($this->cron_conf, "r+");
                if (!flock($fp, LOCK_EX))
                {
                    print_cli("无法获得锁");
                    continue;
                }

                // 读取配置
                if ($file_size = filesize($this->cron_conf))
                {
                    $content = fread($fp,  $file_size);
                    if ($content === false)
                    {
                        print_cli("配置读取失败");
                        continue;
                    }
                }

                // 写入配置
                $ini = $this->makeIni($program, $cron);
                if (!empty($content))
                {
                    $ini = trim($content) . PHP_EOL . $ini;
                }

                ftruncate($fp, 0);
                rewind($fp);
                $bytes = fwrite($fp, $ini);
                if ($bytes === false)
                {
                    print_cli("配置写入失败");
                    continue;
                }
                fflush($fp);

                $supervisor->reloadConfig();
                $supervisor->addProcessGroup($program);
                $supervisor->startProcessGroup($program);

                // 解锁
                flock($fp, LOCK_UN);
                fclose($fp);

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
        }
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