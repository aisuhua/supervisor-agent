<?php
/**
 * @var Phalcon\Di $di
 */

use Phalcon\Mvc\Micro;
use SupAgent\Model\CronLog;
use SupAgent\Model\Process;
use SupAgent\Model\Server;
use SupAgent\Model\Command;
use SupAgent\Lock\Command as CommandLock;

require __DIR__ . '/../init.php';

$app = new Micro($di);

// 前置检查
$app->before(function () use ($app) {
    $route = $app->router->getMatchedRoute();
    if ($route->getName() == 'state')
    {
        return true;
    }

    $auth = $app->request->get('auth');
    $time = $app->request->get('time');
    $uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

    if (empty($auth) ||
        empty($time) ||
        time() - $time > $GLOBALS['api']['expired'] ||
        md5($_SERVER['HTTP_HOST'] . $uri . $time . $GLOBALS['api']['key']) != $auth)
    {
        if ($route->getName() == 'tail-cron-log')
        {
            exit();
        }

        $result['state'] = 0;
        $result['message'] = "签名错误";
        $app->response->setJsonContent($result)->send();
        $app->stop();

        return false;
    }

    return true;
});

// 查看服务状态
$app->get('/state', function() use ($app) {
    $result['state'] = 1;
    $result['message'] = "RUNNING";
    return $app->response->setJsonContent($result);
})->setName('state');

// 重新加载进程配置
$app->get('/process/reload/{server_id:[0-9]+}', function ($server_id) use ($app) {
    $result = [];

    $server = Server::findFirst($server_id);
    if (!$server)
    {
        $result['state'] = 0;
        $result['message'] = "该服务器不存在";
        return $app->response->setJsonContent($result);
    }

    $processes = Process::find([
        'server_id = :server_id:',
        'bind' => [
            'server_id' => $server_id
        ],
        'order' => 'program asc, id asc'
    ]);

    $ini_arr = [];
    foreach ($processes as $process)
    {
        /** @var Process $process */
        $ini_arr[] = $process->getIni();
    }

    $ini = implode(PHP_EOL, $ini_arr) . PHP_EOL;
    if (file_put_contents(Server::CONF_PROCESS, $ini) === false)
    {
        $result['state'] = 0;
        $result['message'] = "配置更新失败";
        return $app->response->setJsonContent($result);
    }

    $result['state'] = 1;
    $result['message'] = "配置更新成功";
    return $app->response->setJsonContent($result);
});

// 更新命令配置
$app->get('/command/reload/{server_id:[0-9]+}/{id:[0-9]+}', function($server_id, $id) use ($app) {
    $server = Server::findFirst($server_id);
    if (!$server)
    {
        $result['state'] = 0;
        $result['message'] = "该服务器不存在";
        return $app->response->setJsonContent($result);
    }

    $cronLock = new CommandLock();
    $cronLock->lock();

    $content = '';
    if (file_exists(Server::CONF_COMMAND))
    {
        if (($content = file_get_contents(Server::CONF_COMMAND)) == false)
        {
            $result['state'] = 0;
            $result['message'] = "无法读取配置";
            return $app->response->setJsonContent($result);
        }
    }

    /** @var Command $command */
    $command = Command::findFirst($id);
    if (!$command)
    {
        $result['state'] = 0;
        $result['message'] = "该命令不存在";
        return $app->response->setJsonContent($result);
    }

    $ini = $command->getIni();
    if ($content)
    {
        $ini = trim($content)  . PHP_EOL . $ini . PHP_EOL;
    }

    if (file_put_contents(Server::CONF_COMMAND, $ini) === false)
    {
        $result['state'] = 0;
        $result['message'] = "配置更新失败";
        return $app->response->setJsonContent($result);
    }

    $result['state'] = 1;
    $result['message'] = "配置更新成功";
    return $app->response->setJsonContent($result);
});

// 读取定时任务或命令的日志
$app->get('/cron-log/tail/{id:[0-9]+}/{file_size:[0-9]+}', function ($id, $file_size) use ($app) {
    /** @var CronLog $cronLog */
    $cronLog = CronLog::findFirst($id);
    if (!$cronLog)
    {
        exit();
    }

    $log_file = $cronLog->getLogFile();
    if (!file_exists($log_file))
    {
        exit();
    }

    if ($file_size == 0)
    {
        $file_size = filesize($log_file);
    }

    if ($file_size == 0)
    {
        exit();
    }

    $fp = fopen($log_file, 'r');
    echo frread($fp, $file_size);
    exit();
})->setName('tail-cron-log');

$app->handle();