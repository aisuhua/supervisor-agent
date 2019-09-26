<?php
namespace SupAgent\Model;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Uniqueness;
use Phalcon\Validation\Validator\PresenceOf;

class Process extends Model
{
    public $id;
    public $server_id;
    public $program;
    public $command;
    public $process_name;
    public $numprocs;
    public $numprocs_start;
    public $user;
    public $directory;
    public $autostart;
    public $startretries;
    public $autorestart;
    public $redirect_stderr;
    public $stdout_logfile;
    public $stdout_logfile_backups;
    public $stdout_logfile_maxbytes;
    public $update_time;
    public $create_time;

    public function getIni()
    {
        $ini = '';
        $ini .= "[program:{$this->program}]" . PHP_EOL;
        $ini .= "command={$this->command}" . PHP_EOL;
        $ini .= "numprocs={$this->numprocs}" . PHP_EOL;
        $ini .= "numprocs_start={$this->numprocs_start}" . PHP_EOL;
        $ini .= "process_name={$this->process_name}" . PHP_EOL;
        $ini .= "user={$this->user}" . PHP_EOL;
        $ini .= "directory={$this->directory}" . PHP_EOL;
        $ini .= "autostart={$this->autostart}" . PHP_EOL;
        $ini .= "startretries={$this->startretries}" . PHP_EOL;
        $ini .= "autorestart={$this->autorestart}" . PHP_EOL;
        $ini .= "redirect_stderr={$this->redirect_stderr}" . PHP_EOL;
        $ini .= "stdout_logfile={$this->stdout_logfile}" . PHP_EOL;
        $ini .= "stdout_logfile_backups={$this->stdout_logfile_backups}" . PHP_EOL;
        $ini .= "stdout_logfile_maxbytes={$this->stdout_logfile_maxbytes}";

        return $ini;
    }
}