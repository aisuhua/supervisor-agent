<?php
namespace SupAgent\Model;

use Phalcon\Mvc\Model;

class Server extends Model
{
    public $id;
    public $server_group_id;
    public $ip;
    public $port;
    public $username;
    public $password;
    public $sync_conf_port;
    public $process_conf;
    public $cron_conf;
    public $command_conf;
    public $sort;
    public $create_time;
    public $update_time;

    const CONF_CRON = PATH_SUPERVISOR_CONFIG . '/cron.conf';
    const CONF_COMMAND = PATH_SUPERVISOR_CONFIG . '/command.conf';
    const CONF_PROCESS = PATH_SUPERVISOR_CONFIG . '/process.conf';

    public function getSupervisor()
    {

    }
}