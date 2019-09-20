<?php
/**
 * @var Phalcon\Di $di
 */
use Phalcon\Mvc\Application;
use Phalcon\Mvc\Dispatcher;
use Phalcon\Mvc\View;
use Phalcon\Mvc\View\Engine\Volt as VoltEngine;

require __DIR__ . '/../init.php';
require PATH_INIT . '/routes.php';

// 注冊自动加载目录
$loader->registerNamespaces([
    'SupAgent\Controller' => PATH_ROOT . '/controller/',

], true);
$loader->register();

// 注册服务
$di->set('dispatcher', function () {
    $dispatcher = new Dispatcher();
    $dispatcher->setDefaultNamespace('SupAgent\Controller\\');
    return $dispatcher;
});

$di->setShared('view', function () {
    $view = new View();
    $view->setDI($this);
    $view->setViewsDir(PATH_ROOT . '/view/');

    $view->registerEngines([
        '.volt' => function ($view) {
            $volt = new VoltEngine($view, $this);

            $volt->setOptions([
                'compiledPath' => PATH_CACHE . '/volt/',
                'compileAlways' => DEBUG_MODE ? true : false
            ]);

            return $volt;
        }
    ]);

    return $view;
});

$application = new Application($di);
echo $application->handle()->getContent();


