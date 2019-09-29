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
    const LOG_SIZE = 5;

    public function initialize()
    {
        $this->keepSnapshots(true);

        $this->belongsTo('server_id', Server::class, 'id', [
            'alias' => 'server',
            'reusable' => false
        ]);

        $this->hasMany('id', CronLog::class, 'cron_id', [
            'alias' => 'cronLog',
            'reusable' => true
        ]);
    }

    public function truncate()
    {
        $cronLogs= $this->getRelated('cronLog', [
            "status IN ({status:array})",
            'bind' => [
                'status' => [
                    ProcessAbstract::STATUS_FINISHED,
                    ProcessAbstract::STATUS_STOPPED,
                    ProcessAbstract::STATUS_UNKNOWN,
                    ProcessAbstract::STATUS_FAILED
                ]
            ],
            'order' => 'id desc',
            'offset' => Cron::LOG_SIZE,
            'limit' => 10000
        ]);

        if ($cronLogs->count())
        {
            /** @var CronLog $cronLog */
            foreach ($cronLogs as $cronLog)
            {
                @unlink($cronLog->getLogFile());
                $cronLog->delete();
            }
        }
    }
}