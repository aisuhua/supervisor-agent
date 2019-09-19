<?php
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

});

/**
 * 重新生成命令配置
 * 1. 读取命令信息，将命令进程拼接成 ini，写入 command.conf 文件
 * 2. 调用 Supervisor reloadConfig 方法，更新进程状态
 * 3. 返回 added/changed/removed
 */
$app->get('/command/reload/{server_id}', function ($server_id) {

});

/**
 * 处理 event listener 回调的事件
 * 1. 根据进程信息判断属于定时任务或者命令
 * 2. 根据进程当前状态执行更新操作，包括从配置文件删除进程所对应的配置项
 * 3.
 */


$app->handle();