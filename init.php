<?php
use Phalcon\Loader;
use Phalcon\Di\FactoryDefault;
use Phalcon\Di\FactoryDefault\Cli as CliDI;

error_reporting(-1);

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 是否开启 debug 模式
define('DEBUG_MODE', true);

// 定义项目常量
define('PATH_ROOT', __DIR__);
define('PATH_INIT', PATH_ROOT . '/init');
define('PATH_CONFIG', PATH_ROOT . '/config');
define('PATH_CONFIG_COMMON', PATH_CONFIG . '/common');
define('PATH_CACHE', PATH_ROOT . '/cache');
define('PATH_LOG', PATH_ROOT . '/log');
define('PATH_LIBRARY', PATH_ROOT . '/library');

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

// 加载公共配置
require PATH_CONFIG_COMMON . '/inc_language.php';

// 加载环境配置
require PATH_CONFIG_IDC . '/inc_database.php';

// 加载库文件
require PATH_LIBRARY . '/lib_func.php';

// 注册默认自动加载的目录
$loader = new Loader();
$loader->registerNamespaces([
    'SupAgent\Model' => PATH_ROOT . '/model/',
    'SupAgent\Library' => PATH_ROOT . '/library/',
]);
$loader->register();

// 根据当前运行模式实例化对应的 DI 容器
if (PHP_SAPI == 'cli')
{
    $di = new CliDI();
}
else
{
    $di = new FactoryDefault();
}

// 注册公共服务
require PATH_INIT . '/services.php';