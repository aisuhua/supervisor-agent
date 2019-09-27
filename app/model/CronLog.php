<?php
namespace SupAgent\Model;

use Phalcon\Mvc\Model;

/**
 * Class CronLog
 */
class CronLog extends ProcessAbstract
{
    public $id;
    public $cron_id;
    public $server_id;
    public $program;
    public $command;
    public $start_time;
    public $end_time;
    public $status;
    public $update_time;
    public $create_time;

    const PROGRAM_PREFIX = '_supervisor_cron_';

    public function initialize()
    {
        $this->belongsTo('server_id', Server::class, 'id', [
            'alias' => 'server',
            'reusable' => false
        ]);
    }

    public function beforeCreate()
    {
        $this->create_time = time();
    }

    public function beforeSave()
    {
        $this->update_time = time();
    }

    public function getServer()
    {
        return $this->getRelated('server');
    }

    public function getProgram()
    {
        return self::PROGRAM_PREFIX . $this->id;
    }

    public function getLogFile()
    {
        return PATH_SUPERVISOR_LOG_CRON . "/{$this->program}.log";
    }

    public static function getPathConf()
    {
        return PATH_SUPERVISOR_CONF . '/cron.conf';
    }

    public function truncate()
    {
        $cronLogs= self::find([
            "cron_id = :cron_id: AND status IN ({status:array})",
            'bind' => [
                'cron_id' => $this->cron_id,
                'status' => [
                    self::STATUS_FINISHED,
                    self::STATUS_STOPPED,
                    self::STATUS_UNKNOWN,
                    self::STATUS_FAILED
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

    public static function isCron($program)
    {
        return strpos($program, self::PROGRAM_PREFIX) === 0;
    }
}