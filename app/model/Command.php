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
    const LOG_SIZE = 50;

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
        return PATH_SUPERVISOR_LOG_COMMAND . "/{$this->program}.log";
    }

    public static function getPathConf()
    {
        return PATH_SUPERVISOR_CONF . '/command.conf';
    }

    public static function isCommand($program)
    {
        return strpos($program, self::PROGRAM_PREFIX) === 0;
    }

    public function truncate()
    {
        $commands = self::find([
            "server_id = :server_id: AND status IN ({status:array})",
            'bind' => [
                'server_id' => $this->server_id,
                'status' => [
                    self::STATUS_FINISHED,
                    self::STATUS_STOPPED,
                    self::STATUS_UNKNOWN,
                    self::STATUS_FAILED
                ]
            ],
            'order' => 'id desc',
            'offset' => self::LOG_SIZE - 1,
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

    public static function parseProgram($program)
    {
        if (preg_match('/(' . self::PROGRAM_PREFIX . ')(\d+)/', $program, $matches))
        {
            return [
                'prefix' => $matches[1],
                'id' => $matches[2]
            ];
        }

        return false;
    }
}