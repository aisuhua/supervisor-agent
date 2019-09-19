<?php
use Phalcon\Di\FactoryDefault\Cli as CliDI;
use Phalcon\Cli\Console as ConsoleApp;

require __DIR__ . '/init.php';

// 设置默认命名空间
$di->get('dispatcher')->setDefaultNamespace('SupAgent\Task');
$di->get('dispatcher')->setNamespaceName ('SupAgent\Task');

$console = new ConsoleApp();
$console->setDI($di);

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

try
{
    $console->handle($arguments);
}
catch (\Phalcon\Exception $e)
{
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
catch (\Throwable $throwable)
{
    fwrite(STDERR, $throwable->getMessage() . PHP_EOL);
    exit(1);
}
catch (\Exception $exception)
{
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
