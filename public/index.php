<?php
use Phalcon\Mvc\Application;

require __DIR__ . '/../init.php';

$application = new Application($di);

echo $application->handle()->getContent();