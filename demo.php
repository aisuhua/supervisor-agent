<?php
// 处理信号量
$signals = [
    SIGTERM => 'SIGTERM',
    SIGHUP  => 'SIGHUP',
    SIGINT  => 'SIGINT',
    SIGQUIT => 'SIGQUIT',
];

$sig_handler = function ($signo) use ($signals) {
    echo isset($signals[$signo]) ? $signals[$signo] : $signo,

    exit();
};

pcntl_signal(SIGTERM, $sig_handler); // kill
pcntl_signal(SIGHUP, $sig_handler); // kill -s HUP or kill -1
pcntl_signal(SIGINT, $sig_handler); // Ctrl-C
pcntl_signal(SIGQUIT, $sig_handler); // kill -3

$i = 0;
while (true)
{
    echo ++$i, PHP_EOL;
    sleep(1);
    pcntl_signal_dispatch();
}