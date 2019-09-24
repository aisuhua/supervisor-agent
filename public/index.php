<?php
/**
 * @var Phalcon\Di $di
 */
use Phalcon\Mvc\Micro;

require __DIR__ . '/../init.php';

$app = new Micro($di);

/**
 * 重新生成进程配置
 * 1. 读取进程信息，将进程配置拼接成 ini，写入 process.conf 文件
 * 2. 调动 Supervisor reloadConfig 方法，更新进程状态
 * 3. 返回 added/changed/removed
 */
$app->get('/process/reload/{server_id}', function ($server_id) {
    echo $server_id, PHP_EOL;
});

/**
 * 重新生成命令配置
 * 1. 读取命令信息，将命令进程拼接成 ini，写入 command.conf 文件
 * 2. 调用 Supervisor reloadConfig 方法，更新进程状态
 * 3. 返回 added/changed/removed
 */
$app->get('/command/reload/{server_id}', function($server_id) {

});

/**
 * 读取定时任务或命令日志
 */
$app->get('/log/tail/{length}', function($length) {

});

$app->handle();