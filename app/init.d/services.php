<?php
/**
 * @var Phalcon\Di $di
 */

use Phalcon\Db\Adapter\Pdo\Mysql;
use SupAgent\Supervisor\Supervisor;
use Phalcon\Mvc\Model\Metadata\Memory as MemoryMetaData;
use Phalcon\Mvc\Model\MetaData\Files as FileMetaData;

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

$di->set('supervisor', function ($name, $ip, $port, $username = null, $password = null)
{
    return new Supervisor($name, $ip, $username, $password, $port);
});

