<?php
/**
 * @var Phalcon\Di $di
 */
use React\EventLoop\Factory as EventLoop;

require 'init.php';

$loop = EventLoop::create();

$loop->addSignal(SIGTERM, function (int $signal, $loop) {
    echo $signal . PHP_EOL;
});

$last_time = filemtime('.version');

$runTask = function() use ($last_time, $di) {
    echo date('Y-m-d H:i:s'), PHP_EOL;

    echo 'Done' . PHP_EOL;
    clearstatcache(true, '.version');

    if ($last_time != filemtime('.version'))
    {
        $di['dataCache']->save('next_loop', 1, 5 - time() % 5);
        exit;
    }
};

if ($di['dataCache']->get('next_loop'))
{
    $di['dataCache']->delete('next_loop');
    echo "now: ", date('Y-m-d H:i:s'), PHP_EOL;

    $loop->addTimer(5 - time() % 5, function () use ($loop, $runTask) {
        $runTask();
    });
}

$loop->addPeriodicTimer(5, function () use ($loop, $runTask) {
    $loop->addTimer(5 - time() % 5, $runTask);
});

$loop->run();



