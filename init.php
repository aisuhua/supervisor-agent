<?php
use Phalcon\Loader;
use Phalcon\Di\FactoryDefault;
use Phalcon\Db\Adapter\Pdo\Mysql;
use Phalcon\Mvc\View;
use Phalcon\Mvc\View\Engine\Volt as VoltEngine;
use Phalcon\Mvc\Dispatcher;

error_reporting(-1);

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 是否开启 debug 模式
define('DEBUG_MODE', true);

// 定义项目常量
define('PATH_ROOT', __DIR__);
define('PATH_CONFIG', PATH_ROOT . '/config');
define('PATH_CONFIG_COMMON', PATH_CONFIG . '/common');
define('PATH_CACHE', PATH_ROOT . '/cache');
define('PATH_LOG', PATH_ROOT . '/log');
define('PATH_LIBRARY', PATH_ROOT . '/library');

// 判断当前机房
if (is_file("/www/web/IDC_HN1"))
{
    define('IDC_NAME', 'HN1');
    define('PATH_CONFIG_IDC', PATH_CONFIG . '/hn1');
}
else
{
    define('IDC_NAME', 'RC');
    define('PATH_CONFIG_IDC', PATH_CONFIG . '/rc');
}

// 是否开启 debug
if (DEBUG_MODE)
{
    ini_set('error_log', PATH_LOG . '/php_error.log');
    ini_set('log_errors', '1');
    ini_set('display_errors', 'On');
}
else
{
    ini_set('error_log', PATH_LOG . '/php_error.log');
    ini_set('log_errors', '1');
    ini_set('display_errors', 'Off');
}

// 加载公共配置
require PATH_CONFIG_COMMON . '/inc_language.php';

// 加载环境配置
require PATH_CONFIG_IDC . '/inc_database.php';

// 加载库文件
require PATH_LIBRARY . '/lib_func.php';

// 注册自动加载目录
$loader = new Loader();
$loader->registerNamespaces(
    [
        'SupAgent\Controller' => PATH_ROOT . '/controller/',
        'SupAgent\Model' => PATH_ROOT . '/model/',
        'SupAgent\Library' => PATH_ROOT . '/library/',
    ]
);
$loader->register();

// 注册服务
$di = new FactoryDefault();

$di->set('dispatcher', function () {
    $dispatcher = new Dispatcher();
    $dispatcher->setDefaultNamespace('SupAgent\Controller\\');
    return $dispatcher;
});

$di->set('db', function () {
    return new Mysql([
        'host' => $GLOBALS['db']['host'],
        'username' => $GLOBALS['db']['username'],
        'password' => $GLOBALS['db']['password'],
        'dbname' => $GLOBALS['db']['dbname'],
        'charset' => $GLOBALS['db']['charset'],
    ]);
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