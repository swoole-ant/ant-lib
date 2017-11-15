<?php

namespace socket\Handler;

use ZPHP\Manager\Task;
use ZPHP\Protocol\Response;
use ZPHP\Protocol\Request;
use ZPHP\Core\Route as ZRoute;
use ZPHP\Core\Config as ZConfig;
use common;
use sdk\MonitorClient as MClient;
use ZPHP\Socket\Adapter\Swoole;
use ZPHP\ZPHP;
use ZPHP\Conn\Factory as ZConn;
use ZPHP\Cache\Factory as ZCache;

class Proxy
{
    /**
     * @param $serv
     * @return string
     * @desc 返回全局的唯一的请求id
     */
    private static function getRequestId($serv)
    {
        return sha1(uniqid($serv->worker_pid . '_', true));
    }


    /**
     * @param $serv \swoole_server 对像
     * @param $fd //文件描述符
     * @param $from_id //来自哪个reactor线程, 此参数基本用不上
     * @param $data //接收到的tcp数据
     * @return bool
     * @throws \Exception
     * @desc 收到tcp数据的业务处理
     */
    public static function onReceive(\swoole_server $serv, $fd, $from_id, $data)
    {
        $startTime = microtime(true);
        common\Log::info([$data, substr($data, 4), $fd], 'proxy_tcp');
        $realData = substr($data, 4);
        if ('ant-ping' === $realData) {  //ping包，强制硬编码，不允许自定义
            return $serv->send($fd, pack('N', 8) . 'ant-pong');  //回pong包
        }
        if ('ant-reload' == $realData) { //重启包
            common\Log::info([], 'reload');
            $serv->send($fd, pack('N', 2) . 'ok');  //回ok
            return $serv->reload();
        }
        Request::addParams('_recv', 1);
        Request::parse($realData);
        if (Request::checkRequestTimeOut()) {
            //该请求已超时
            common\Log::info([Request::getParams()], 'request_timeout');
            return false;
        }
        $params = Request::getParams();
        $params['_fd'] = $fd;
        Request::setParams($params);

        if (!empty($params['_task'])) {
            //task任务, 回复task的任务id
            $taskId = self::getRequestId($serv);
            $params['taskId'] = $taskId;
            $params['requestId'] = Request::getRequestId();
            $serv->task($params);
            $result = Response::display([
                'code' => 0,
                'msg' => '',
                'data' => [
                    'taskId' => $taskId
                ]
            ]);
            $serv->send($fd, pack('N', strlen($result)) . $result);
        } else {
            if (empty($params['_recv'])) {
                //不用等处理结果，立即回复一个空包，表示数据已收到
                $result = Response::display([
                    'code' => 0,
                    'msg' => '',
                    'data' => null
                ]);
                $serv->send($fd, pack('N', strlen($result)) . $result);
            }

            $result = ZRoute::route();
            common\Log::info([$data, $fd, Request::getCtrl(), Request::getMethod(), $result], 'proxy_tcp');
            if (!empty($params['_recv'])) {
                //发送处理结果
                $serv->send($fd, pack('N', strlen($result)) . $result);
            }
        }
        $executeTime = Response::getResponseTime() - $startTime;  //获取程序执行时间
        common\Log::info(['tcp', Request::getCtrl() . DS . Request::getMethod(), $executeTime], 'monitor');
        MClient::serviceDot(Request::getCtrl() . DS . Request::getMethod(), $executeTime);
    }


    /**
     * @param $request \swoole_http_request
     * @param $response \swoole_http_response
     * @throws \Exception
     * @desc http请求回调
     */
    public static function onRequest($request, $response)
    {
        $startTime = microtime(true);
        if ($request->server['path_info'] == '/ant-ping') {
            common\Log::info([$request], 'http_ping');
            $response->end('ant-pong');
            return;
        }
        if ($request->server['path_info'] == '/favicon.ico') {
            common\Log::info([$request], 'favicon');
            $response->end();
            return;
        }

        common\Log::info([$request], 'proxy_http');
        $param = [];
        $_GET = $_POST = $_REQUEST = $_COOKIE = $_FILES = null;
        if (!empty($request->get)) {
            $_GET = $request->get;
            $param = $request->get;
        }
        if (!empty($request->post)) {
            $_POST = $request->post;
            $param += $request->post;
        }

        if (!empty($request->cookie)) {
            $_COOKIE = $request->cookie;
        }

        if (!empty($request->files)) {
            $_FILES = $request->files;
        }

        foreach ($request->header as $key => $val) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $val;
        }

        foreach ($request->server as $key => $val) {
            $_SERVER[strtoupper($key)] = $val;
        }

        $_REQUEST = $param;

        Request::addParams('_recv', 1);
        Request::parse($param);
        $params = Request::getParams();
        if (!isset($params['_recv'])) {
            $params['_recv'] = 1;
        }
        $params['_fd'] = $request->fd;
        Request::setParams($params);

        if (Request::checkRequestTimeOut()) {
            //该请求已超时
            common\Log::info([Request::getParams()], 'request_timeout');
            $response->status('499');
            $response->end();
            return;
        }

        if (!empty($params['_task'])) {
            //task任务, 回复task的任务id
            $serv = Request::getSocket();
            $taskId = self::getRequestId($serv);
            $params['taskId'] = $taskId;
            $params['requestId'] = Request::getRequestId();
            $serv->task($params);
            $result = Response::display([
                'code' => 0,
                'msg' => '',
                'data' => [
                    'taskId' => $taskId
                ]
            ]);
            $response->end($result);
        } else {
            if (empty($params['_recv'])) {
                //不用等处理结果，立即回复一个空包，表示数据已收到
                $result = Response::display([
                    'code' => 0,
                    'msg' => '',
                    'data' => null
                ]);
                $response->end($result);
                ZRoute::route();
            } else {
                $result = ZRoute::route();
                if (is_null($result)) {
                    $response->end('');
                } else {
                    $response->end($result);
                }
            }
        }

        $executeTime = microtime(true) - $startTime;  //获取程序执行时间
        common\Log::info(['http', $result, Request::getCtrl() . DS . Request::getMethod(), $executeTime], 'monitor');
        MClient::serviceDot(Request::getCtrl() . DS . Request::getMethod(), $executeTime);
    }


    /**
     * @param \swoole_websocket_server $server
     * @param \swoole_http_request $request
     * @throws \Exception
     * @desc websocket握手成功后的回调
     */
    public static function onOpen(\swoole_websocket_server $server, \swoole_http_request $request)
    {

        common\Log::info([$request], 'ws_open');
        $callback = ZConfig::getField('socket', 'on_open_callback');
        if (!$callback || !is_array($callback)) {
            return;
        }
        $param = [];
        $_GET = $_POST = $_REQUEST = $_COOKIE = $_FILES = null;
        if (!empty($request->get)) {
            $_GET = $request->get;
            $param = $request->get;
        }
        if (!empty($request->post)) {
            $_POST = $request->post;
            $param += $request->post;
        }

        if (!empty($request->cookie)) {
            $_COOKIE = $request->cookie;
        }

        if (!empty($request->files)) {
            $_FILES = $request->files;
        }

        foreach ($request->header as $key => $val) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $val;
        }

        $param['_fd'] = $request->fd;
        $_REQUEST = $param;
        Request::setRequest($request);
        Request::addHeaders($request->header, true);
        Request::init($callback[0], $callback[1], $param);
        ZRoute::route();
        Request::setRequest(null);
    }

    /**
     * @param \swoole_websocket_server $serv
     * @param \swoole_websocket_frame $frame
     * @return bool
     * @throws \Exception
     * @desc 收到一个websocket数据包回调
     */
    public static function onMessage(\swoole_websocket_server $serv, \swoole_websocket_frame $frame)
    {
        $startTime = microtime(true);
        common\Log::info([$frame->data, $frame->fd], 'proxy_ws');
        $fd = $frame->fd;
        $data = $frame->data;
        Request::addParams('_recv', 1);
        Request::parse($frame->data);
        if (Request::checkRequestTimeOut()) {
            //该请求已超时
            common\Log::info([Request::getParams()], 'request_timeout');
            return false;
        }
        $params = Request::getParams();
        $params['_fd'] = $frame->fd;
        Request::setParams($params);
        if (!empty($params['_task'])) {
            //task任务, 回复task的任务id
            $taskId = self::getRequestId($serv);
            $params['taskId'] = $taskId;
            $params['requestId'] = Request::getRequestId();
            $serv->task($params);
            $result = Response::display([
                'code' => 0,
                'msg' => '',
                'data' => [
                    'taskId' => $taskId
                ]
            ]);
            $serv->push($fd, $result);
        } else {
            if (empty($params['_recv'])) {
                //不用等处理结果，立即回复一个空包，表示数据已收到
                $result = Response::display([
                    'code' => 0,
                    'msg' => '',
                    'data' => null
                ]);
                $serv->push($fd, $result);
            }

            $result = ZRoute::route();
            common\Log::info([$data, $fd, Request::getCtrl(), Request::getMethod(), $result], 'proxy_tcp');
            if (!empty($params['_recv'])) {
                //发送处理结果
                $serv->push($fd, $result);
            }
        }
        $executeTime = Response::getResponseTime() - $startTime;  //获取程序执行时间
        common\Log::info(['ws', Request::getCtrl() . DS . Request::getMethod(), $executeTime], 'monitor');
        MClient::serviceDot(Request::getCtrl() . DS . Request::getMethod(), $executeTime);
    }

    /**
     * @param $serv
     * @param $taskId
     * @param $fromId
     * @param $data
     * @return mixed
     * @throws \Exception
     * @desc task任务，适合处理一些耗时的业务
     */
    public static function onTask($serv, $taskId, $fromId, $data)
    {
        $ret = Task::check($data);
        if ($ret) { //task特殊处理逻辑
            return Task::handle($ret);
        }
        $startTime = microtime(true);
        Request::setRequestId($data['requestId']);
        Request::parse($data);
        $result = ZRoute::route();
        if (!empty($data['_recv'])) { //发送回执
            if (!empty($data['udp'])) { //udp请求
                $serv->sendto($data['clientInfo']['address'], $data['clientInfo']['port'], $result);
            } else {
                $serv->send($data['_fd'], pack('N', strlen($result)) . $result);
            }
        }
        $executeTime = microtime(true) - $startTime;
        common\Log::info(['task', $taskId, $fromId, Request::getCtrl() . DS . Request::getMethod(), $executeTime], 'monitor');
        MClient::taskDot(Request::getCtrl() . DS . Request::getMethod(), $executeTime);
    }

    /**
     * @param $serv
     * @param $taskId
     * @param $data
     * @desc task处理完成之后，数据回调
     */
    public static function onFinish($serv, $taskId, $data)
    {
    }


    /**
     * @param \swoole_server $serv
     * @param $data
     * @param $clientInfo
     * @throws \Exception
     * @desc 收到udp数据的处理
     */
    public static function onPacket(\swoole_server $serv, $data, $clientInfo)
    {
        $startTime = microtime(true);
        common\Log::info([$data, $clientInfo], 'proxy_udp');
        if ('ant-ping' == $data) {
            $serv->sendto($clientInfo['address'], $clientInfo['port'], 'ant-pong');
            return;
        }

        if ('ant-reload' == $data) {
            common\Log::info([], 'reload');
            $serv->reload();
            return;
        }
        $params = Request::parse($data);
        $params['_fd'] = $clientInfo;
        Request::setFd($clientInfo);
        if (!empty($params['_task'])) {
            //task任务, 回复task的任务id
            $params['udp'] = 1;
            $params['clientInfo'] = $clientInfo;
            $taskId = self::getRequestId($serv);
            $params['taskId'] = $taskId;
            $params['requestId'] = Request::getRequestId();
            $serv->task($params);
            if (!empty($params['_recv'])) {
                $result = Response::display([
                    'code' => 0,
                    'msg' => '',
                    'data' => ['taskId' => $taskId]
                ]);
                $serv->sendto($clientInfo['address'], $clientInfo['port'], $result);
            }
        } else {
            $result = ZRoute::route();
            if (!empty($params['_recv']) && $result) {
                $serv->sendto($clientInfo['address'], $clientInfo['port'], $result);
            }
            common\Log::info([$data, $clientInfo, $result], 'proxy_tcp');
        }

        $executeTime = microtime(true) - $startTime;  //获取程序执行时间
        common\Log::info(['udp', $executeTime], 'monitor');
        MClient::serviceDot(Request::getCtrl() . DS . Request::getMethod(), $executeTime);
    }


    /**
     * @param $serv \swoole_server
     * @param $workerId
     * @throws \Exception
     * @desc worker/task进程启动后回调，可用于一些初始化业务和操作
     */
    public static function onWorkerStart($serv, $workerId)
    {
        \register_shutdown_function(function () use ($serv) {
            $params = Request::getParams();
            Request::setViewMode(ZConfig::getField('project', 'view_mode', 'Json'));
            common\Log::info([$params], 'shutdown');
            $result = \call_user_func(ZConfig::getField('project', 'fatal_handler', 'ZPHP\ZPHP::fatalHandler'));
            if (!empty($params['_recv'])) { //发送回执
                common\Log::info([$params, $result], 'shutdown');
                if (Request::isHttp()) {
                    Response::getResponse()->end($result);
                } else {
                    $serverType = ZConfig::get('socket', 'server_type');
                    switch ($serverType) {
                        case Swoole::TYPE_WEBSOCKET:
                        case Swoole::TYPE_WEBSOCKETS:
                            $serv->push(Request::getFd(), $result);
                            break;
                        case Swoole::TYPE_UDP:
                            $clientInfo = Request::getFd();
                            if (!empty($clientInfo['address'])) {
                                $serv->sendto($clientInfo['address'], $clientInfo['port'], $result);
                            }
                            break;
                        default:
                            $serv->send(Request::getFd(), pack('N', strlen($result)) . $result);
                    }
                }
                //@TODO 异常上报
            }
        });
        common\Log::info([$workerId], 'info');
        $timer = ZConfig::get('timer', []);
        if (!empty($timer) && 0 === intval($workerId)) {
            common\Log::info(['timer', $workerId], 'info');
            foreach ($timer as $index => $item) {
                if (!empty($item['ms']) &&
                    !empty($item['callback']) &&
                    \is_callable($item['callback'])
                ) {
                    common\Log::info([$item, $workerId], 'info');
                    \swoole_timer_tick($item['ms'], $item['callback'], isset($item['params']) ? $item['params'] : null);
                }
            }
        }

        $reloadPath = ZConfig::getField('project', 'reload_path', []);
        $reloadPath += [
            ZPHP::getConfigPath() . DS . '..' . DS . 'public'
        ];
        if (is_array($reloadPath)) {
            foreach ($reloadPath as $path) {
                ZConfig::mergePath($path);
            }
        }
        $workNum = ZConfig::getField('socket', 'worker_num');
        if ($workerId == ($workNum - 1)) {
            ZCache::getInstance('Task')->load();
            ZConn::getInstance('Task')->load();
        }
    }


    /**
     * @param $serv
     * @param $workerId
     * @param $workerPid
     * @param $exitCode
     * @throws \Exception
     * @desc  工作进程异常之后的处理
     */
    public static function onWorkerError($serv, $workerId, $workerPid, $exitCode)
    {
        $workNum = ZConfig::getField('socket', 'worker_num');
        if ($workerId == ($workNum - 1)) {
            ZCache::getInstance('Task')->flush();
            ZConn::getInstance('Task')->flush();
        }
    }


    /**
     * @param $serv \swoole_server
     * @param $fd
     * @param $from_id
     * @throws \Exception
     * @desc 建立连接回调
     */
    public static function onConnect($serv, $fd, $from_id)
    {
        common\Log::info([$fd], 'on_connect');
        $callback = ZConfig::getField('socket', 'on_connect_callback');
        if (!$callback || !is_array($callback)) {
            return;
        }

        Request::init($callback[0], $callback[1], [
            '_fd' => $fd
        ]);
        ZRoute::route();
    }

    /**
     * @param $serv
     * @param $fd
     * @param $from_id
     * @throws \Exception
     * @desc 连接关闭回调
     */
    public static function onClose($serv, $fd, $from_id)
    {
        common\Log::info([$fd], 'on_close');
        $callback = ZConfig::getField('socket', 'on_close_callback');
        if (!$callback || !is_array($callback)) {
            return;
        }
        Request::init($callback[0], $callback[1], [
            '_fd' => $fd
        ]);
        ZRoute::route();
    }

    /**
     * @param $serv
     * @param $workerId
     * @throws \Exception
     * @desc worker进程退出时回调
     */
    public static function onWorkerStop($serv, $workerId)
    {
        $workNum = ZConfig::getField('socket', 'worker_num');
        if ($workerId == ($workNum - 1)) {
            ZCache::getInstance('Task')->flush();
            ZConn::getInstance('Task')->flush();
        }
    }
}
