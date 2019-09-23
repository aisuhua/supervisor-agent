<?php
namespace SupAgent\Model;

use Phalcon\Mvc\Model;

/**
 * Class Cron
 *
 * @method Server getServer()
 */
class Cron extends Model
{
    public $id;
    public $server_id;
    public $user;
    public $command;
    public $time;
    public $description;
    public $status;
    public $last_time;
    public $prev_time;
    public $update_time;
    public $create_time;

    const STATUS_ACTIVE = 1;
    const STATE_INACTIVE = -1;
    const PROGRAM_PREFIX = '_supervisor_cron_';

    public function initialize()
    {
        $this->keepSnapshots(true);

        $this->belongsTo('server_id', Server::class, 'id', [
            'alias' => 'server',
            'reusable' => false
        ]);
    }

    public function getProgram()
    {
        return self::PROGRAM_PREFIX . $this->id . '_' . date('YmdHi');
    }

    public static function isCron($program)
    {
        return strpos($program, self::PROGRAM_PREFIX) === 0;
    }

    public function getIni()
    {
        $program = $this->getProgram();

        $ini = '';
        $ini .= "[program:{$program}]" . PHP_EOL;
        $ini .= "command={$this->command}" . PHP_EOL;
        $ini .= "numprocs=1" . PHP_EOL;
        $ini .= "numprocs_start=0" . PHP_EOL;
        $ini .= "process_name=%(program_name)s_%(process_num)s" . PHP_EOL;
        $ini .= "user={$this->user}" . PHP_EOL;
        $ini .= "directory=%(here)s" . PHP_EOL;
        $ini .= "startsecs=0" . PHP_EOL;
        $ini .= "autostart=false" . PHP_EOL;
        $ini .= "startretries=0" . PHP_EOL;
        $ini .= "autorestart=false" . PHP_EOL;
        $ini .= "redirect_stderr=true" . PHP_EOL;
        $ini .= "stdout_logfile=" . PATH_SUPERVISOR_LOG . "/{$program}.log" . PHP_EOL;
        $ini .= "stdout_logfile_backups=0" . PHP_EOL;
        $ini .= "stdout_logfile_maxbytes=8MB" . PHP_EOL;

        return $ini;
    }



}