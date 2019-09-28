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

    // 验证摘要 auth 是否有效
    if (empty($auth) ||
        empty($time) ||
        time() - $time > $GLOBALS['api']['expired'] ||
        md5($_SERVER['HTTP_HOST'] . $uri . $time . $GLOBALS['api']['key']) != $auth)
    {
        if ($route->getName() == 'tail-cron-log' || $route->getName() == 'tail-command-log')
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
$app->post('/process/reload/{server_id:[0-9]+}', function ($server_id) use ($app) {
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

    $ini = '';
    foreach ($processes as $process)
    {
        /** @var Process $process */
        $ini .= $process->getIni() . PHP_EOL;
    }

    if (file_put_contents(Process::getPathConf(), $ini) === false)
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
$app->post('/command/add/{server_id:[0-9]+}/{id:[0-9]+}', function($server_id, $id) use ($app) {
    $server = Server::findFirst($server_id);
    if (!$server)
    {
        $result['state'] = 0;
        $result['message'] = "该服务器不存在";
        return $app->response->setJsonContent($result);
    }

    $commandLock = new CommandLock();
    $commandLock->lock();

    /** @var Command $command */
    $command = Command::findFirst($id);
    if (!$command)
    {
        $commandLock->unlock();
        $result['state'] = 0;
        $result['message'] = "该命令不存在";
        return $app->response->setJsonContent($result);
    }

    $ini = $command->getIni() . PHP_EOL;
    $content = '';

    if (file_exists(Command::getPathConf()))
    {
        if (($content = file_get_contents(Command::getPathConf())) === false)
        {
            $commandLock->unlock();
            $result['state'] = 0;
            $result['message'] = "无法读取配置";
            return $app->response->setJsonContent($result);
        }
    }

    if (!empty($content))
    {
        $ini = trim($content)  . PHP_EOL . trim($ini) . PHP_EOL;
    }

    if (file_put_contents(Command::getPathConf(), $ini) === false)
    {
        $commandLock->unlock();
        $result['state'] = 0;
        $result['message'] = "配置更新失败";
        return $app->response->setJsonContent($result);
    }

    $commandLock->unlock();
    $result['state'] = 1;
    $result['message'] = "配置更新成功";
    return $app->response->setJsonContent($result);
});

// 读取定时任务日志
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

    echoLog($log_file, $file_size);
    exit();
})->setName('tail-cron-log');

// 读取命令执行日志
$app->get('/command-log/tail/{id:[0-9]+}/{file_size:[0-9]+}', function ($id, $file_size) use ($app) {
    /** @var Command $command */
    $command = Command::findFirst($id);
    if (!$command)
    {
        exit();
    }

    $log_file = $command->getLogFile();
    if (!file_exists($log_file))
    {
        exit();
    }

    echoLog($log_file, $file_size);
    exit();
})->setName('tail-command-log');

$app->handle();