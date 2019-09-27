<?php
namespace SupAgent\Model;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Uniqueness;
use Phalcon\Validation\Validator\PresenceOf;

/**
 * Class Command
 */
class Command extends ProcessAbstract
{
    public $id;
    public $server_id;
    public $program;
    public $user;
    public $command;
    public $status;
    public $start_time;
    public $end_time;
    public $update_time;
    public $create_time;

    const PROGRAM_PREFIX = '_supervisor_command_';
    const LOG_SIZE = 100;

    public function initialize()
    {
        $this->belongsTo('server_id', Server::class, 'id', [
            'alias' => 'Server',
            'reusable' => false
        ]);
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
        return PATH_SUPERVISOR_CONF . '/command.conf';
    }

    public static function isCommand($program)
    {
        return strpos($program, self::PROGRAM_PREFIX) === 0;
    }

    public static function truncate()
    {
        $commands = Command::find([
            "status IN ({status:array})",
            'bind' => [
                'status' => [
                    self::STATUS_FINISHED,
                    self::STATUS_STOPPED,
                    self::STATUS_UNKNOWN,
                    self::STATUS_FAILED
                ]
            ],
            'order' => 'id desc',
            'offset' => self::LOG_SIZE,
            'limit' => 10000
        ]);

        if ($commands->count())
        {
            /** @var Command $command */
            foreach ($commands as $command)
            {
                @unlink($command->getLogFile());
                $command->delete();
            }
        }
    }
}