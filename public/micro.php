<?php
use Phalcon\Mvc\Micro;

require __DIR__ . '/../init.php';

$app = new Micro($di);

$app->get(
    '/orders/display/{name}',
    function ($name) {
        $serverGroups = ServerGroup::find();

        var_dump($serverGroups->toArray());
    }
);

$app->handle();