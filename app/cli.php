<?php
/**
 * @var Phalcon\Di $di
 */
use Phalcon\Cli\Console;
use Phalcon\Cli\Dispatcher;

require __DIR__ . '/../init.php';

// 注冊自动加载目录
$loader->registerNamespaces([
    'SupAgent\Task' => PATH_APP . '/task/',

], true);
$loader->register();

// 设置默认命名空间
$di->set('dispatcher', function () {
    $dispatcher = new Dispatcher();
    $dispatcher->setDefaultNamespace('SupAgent\Task');
    return $dispatcher;
});

$console = new Console($di);

$arguments = [];
foreach ($argv as $k => $arg)
{
    if ($k === 1)
    {
        $arguments['task'] = $arg;
    }
    elseif ($k === 2)
    {
        $arguments['action'] = $arg;
    }
    elseif ($k >= 3)
    {
        $arguments['params'][] = $arg;
    }
}

$console->handle($arguments);
