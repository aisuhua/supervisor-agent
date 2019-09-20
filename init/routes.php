<?php
/**
 * @var Phalcon\Di $di
 */

$router = $di->get('router');

$router->add('/home', [
    'namespace'  => 'SupAgent\Controller',
    'controller' => 'index',
    'action' => 'index'
]);

$router->handle();