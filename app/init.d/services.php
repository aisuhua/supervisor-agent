<?php
/**
 * @var Phalcon\Di $di
 */
use Phalcon\Db\Adapter\Pdo\Mysql;

$di->set('db', function () {
    return new Mysql([
        'host' => $GLOBALS['db']['host'],
        'username' => $GLOBALS['db']['username'],
        'password' => $GLOBALS['db']['password'],
        'dbname' => $GLOBALS['db']['dbname'],
        'charset' => $GLOBALS['db']['charset'],
    ]);
});