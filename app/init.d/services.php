<?php
/**
 * @var Phalcon\Di $di
 */

use Phalcon\Db\Adapter\Pdo\Mysql;
use SupAgent\Supervisor\Supervisor;
use Phalcon\Cache\Backend\File as BackFile;
use Phalcon\Cache\Frontend\Data as FrontData;

$di->setShared('db', function () {
    return new Mysql([
        'host' => $GLOBALS['db']['host'],
        'username' => $GLOBALS['db']['username'],
        'password' => $GLOBALS['db']['password'],
        'dbname' => $GLOBALS['db']['dbname'],
        'charset' => $GLOBALS['db']['charset'],
    ]);
});

$di->setShared('dataCache', function () {
    $frontCache = new FrontData([
        'lifetime' => $GLOBALS['cache']['lifetime']
    ]);

    return new BackFile($frontCache, [
        'cacheDir' => PATH_CACHE . '/data/',
    ]);
});

$di->set('supervisor', function ($name, $ip, $port, $username = null, $password = null) {
    return new Supervisor($name, $ip, $username, $password, $port);
});

