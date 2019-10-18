<?php
namespace SupAgent\Task;

use Phalcon\Cli\Task;
use SupAgent\Library\Version;

class TaskBase extends Task
{
    const MAX_MEM_SIZE = 52428800;
    const MAX_RUN_TIME = 3600;

    protected function checkBeforeNext(Version &$version, $start_time)
    {
        if ($version->hasChanged())
        {
            print_err('version has changed, exit automatically');
            exit();
        }

        if (($mem = memory_get_usage(true)) > self::MAX_MEM_SIZE)
        {
            $mem = size_format($mem);
            print_err("memory usage out of {$mem}, exit automatically");
            exit();
        }

        if (($cost_time = time() - $start_time) > self::MAX_RUN_TIME)
        {
            print_err("process has been running for {$cost_time}s, exit automatically");
            exit();
        }
    }
}