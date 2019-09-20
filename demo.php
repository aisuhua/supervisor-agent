<?php
/**
 * @var Phalcon\Di $di
 */
require 'init.php';

$connection = $di->get('db');
$sql = 'SELECT * FROM server';

// Send a SQL statement to the database system
$result = $connection->query($sql);

while ($server = $result->fetch()) {
    echo $server['ip'], PHP_EOL;
}