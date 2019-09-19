<?php
namespace SupAgent\Task;

use Phalcon\Cli\Task;

class CronTask extends Task
{
   public function checkPerMinuteAction()
   {
        echo 'suhua';
   }
}