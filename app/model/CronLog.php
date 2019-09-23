<?php
namespace SupAgent\Model;

use Phalcon\Mvc\Model;

/**
 * Class CronLog
 *
 * @method Server getServer()
 */
class CronLog extends Model
{
    public $id;
    public $cron_id;
    public $server_id;
    public $program;
    public $command;
    public $start_time;
    public $end_time;
    public $log;
    public $status;
    public $update_time;
    public $create_time;

    const STATUS_INI = 0; // 初始化状态
    const STATUS_STARTED = 1; // 已启动
    const STATUS_FINISHED = 2; // 已正常完成
    const STATUS_FAILED = -1; // 没有正常退出
    const STATUS_UNKNOWN = -2; // 无法确定进程的执行状态
    const STATUS_STOPPED = -3; // 被中断

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

    public function getProcessName()
    {
        return $this->program . ':' . $this->program . '_0';
    }

    public function getLogFile()
    {
        return PATH_SUPERVISOR_LOG . "/{$this->program}.log";
    }
}