<?php
/**
 * @var Phalcon\Di $di
 */

use Phalcon\Db\Adapter\Pdo\Mysql;
use SupAgent\Supervisor\Supervisor;
use Phalcon\Mvc\Model\Metadata\Memory as MemoryMetaData;
use Phalcon\Mvc\Model\MetaData\Files as FileMetaData;
use Phalcon\Logger\Formatter\Line as FormatterLine;
use Phalcon\Logger\Adapter\File as FileLogger;
use Phalcon\Logger\Adapter\Stream as StreamLogger;

/**
 * 文件日志服务
 */
$di->setShared('logger', function($filename = null)
{
    $formatter = new FormatterLine(null, 'c');

    $filename = empty($filename) ? 'default.log' : $filename;
    $logger = new FileLogger(PATH_LOG . '/' . $filename);
    $logger->setFormatter($formatter);

    return $logger;
});

/**
 * 标准错误输出日志服务
 */
$di->setShared('streamLogger', function($filename = null)
{
    $formatter = new FormatterLine(null, 'c');
    $logger = new StreamLogger('php://stderr');
    $logger->setFormatter($formatter);

    return $logger;
});

/**
 * 数据库服务
 */
$di->setShared('db', function ()
{
    return new Mysql([
        'host' => $GLOBALS['db']['host'],
        'port' => $GLOBALS['db']['port'],
        'username' => $GLOBALS['db']['username'],
        'password' => $GLOBALS['db']['password'],
        'dbname' => $GLOBALS['db']['dbname'],
        'charset' => $GLOBALS['db']['charset'],
    ]);
});

/**
 * 数据库元数据服务
 */
$di->setShared('modelsMetadata', function ()
{
    if (DEBUG_MODE)
    {
        return new MemoryMetaData();
    }

    return new FileMetaData([
        'metaDataDir' => PATH_CACHE . '/metadata/',
        'lifetime' => 86400
    ]);
});

/**
 * Supervisor 服务
 */
$di->set('supervisor', function ($name, $ip, $port, $username = null, $password = null)
{
    return new Supervisor($name, $ip, $username, $password, $port);
});

