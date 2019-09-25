<?php
namespace SupAgent\Model;

use Phalcon\Mvc\Model;
use Phalcon\Di;
use SupAgent\Supervisor\Supervisor;

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

    private $supervisor = null;

    const CONF_CRON = PATH_SUPERVISOR_CONFIG . '/cron.conf';
    const CONF_COMMAND = PATH_SUPERVISOR_CONFIG . '/command.conf';
    const CONF_PROCESS = PATH_SUPERVISOR_CONFIG . '/process.conf';

    /**
     * @param bool $reusable
     *
     * @return Supervisor
     */
    public function getSupervisor($reusable = true)
    {
        if ($reusable && $this->supervisor)
        {
            return $this->supervisor;
        }

        $this->supervisor = Di::getDefault()->get('supervisor', [
            $this->id, $this->ip, $this->port, $this->username, $this->password
        ]);

        return $this->supervisor;
    }
}