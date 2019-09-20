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

    public function initialize()
    {
        $this->keepSnapshots(true);

        $this->belongsTo('server_id', 'Server', 'id', [
            'alias' => 'server',
            'reusable' => false
        ]);
    }

    /**
     * 产生定时任务对应的程序名称
     *
     * @param $datetime
     * @return string
     */
    public function getProgram($datetime)
    {
        return 'sys_cron_' . $this->id . '_' . $datetime;
    }
}