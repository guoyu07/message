<?php

namespace App\Controllers\Im;

use App\Controllers\Controller;
use App\Helpers\Log;

class Index extends Controller
{
    /**
     * 路由中间件
     */
    protected static $middleware = array(
        'index' => array(
            // '\Tree6bee\Cf\Foundation\Http\Middleware\VerifyCsrfToken',
        ),
    );

    public function index()
    {
        $staticHost = $this->ctx->Im->getStaticHost();
        $apiHost = $this->ctx->Im->getApiHost();
        $name = empty($_SESSION['name']) ? "" : $_SESSION['name'];
        $uid = empty($_SESSION['uid']) ? "" : $_SESSION['uid'];

        return $this->render(
            '/Home/index.html',
            compact('staticHost', 'apiHost', 'name', 'uid')
        );
    }

    public function register()
    {
        if (! empty($_SESSION['uid'])) {
            return $this->success(); //已经注册成功过，跳过注册逻辑.
        }
        if (empty($_POST['name'])) {
            throw new \Exception('名字不能为空');
        }

        $name = $_POST['name'];
        if (mb_strlen($name) > 16) {
            throw new \Exception('名字最大为16个字');
        }
        $uid = uniqid();
        $_SESSION['uid'] = $uid;
        $_SESSION['name'] = $name;

        return $this->success();
    }

    public function connectInfo()
    {
        if (empty($_SESSION['uid'])) {
            throw new \Exception("非法的请求");
        }

        return $this->success([
            'wsHost'    => $this->ctx->Im->getConnectInfo($_SESSION['uid']),
        ]);
    }

    //rpc 状态下无session!!!
    public function rpc()
    {
        $agent = 'CtxImRpc 1.0';
        $body = file_get_contents('php://input');

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            throw new \Exception('请求非法' . var_export($body, true));
        }

        if (isset($_SERVER['HTTP_USER_AGENT']) &&
            $_SERVER['HTTP_USER_AGENT'] == $agent &&
            isset($data['class'], $data['method'])
        ) {
            $class = $data['class'];
            $method = $data['method'];
            $args = isset($data['args']) ? $data['args'] : array();

            header('Content-Type: application/json; charset=utf-8');
            $data = call_user_func_array(array($this->ctx->$class, '_' . $method), $args);
            Log::error(sprintf(
                "body:%s, args:%s, data:%s\n",
                $body,
                var_export($args, true),
                var_export($data, true)
            ));

            return $this->success($data);
        } else {
            throw new \Exception("非法的请求");
        }
    }

    //todo 限制发送频率
    //todo 限制消息长度
    public function sendToGroup()
    {
        if (empty($_SESSION['uid'])) {
            throw new \Exception("非法的请求");
        }

        $from = $_SESSION['uid'];
        $msg = htmlspecialchars((string) $_POST['msg']);
        // $msg = $_GET['msg'];
        $to = (string) $_POST['to'];
        $this->ctx->Im->sendToGroup($from, $to, $msg);

        return $this->success();
    }

    //todo 限制发送频率
    //todo 限制消息长度
    public function sendToUser()
    {
        if (empty($_SESSION['uid'])) {
            throw new \Exception("非法的请求");
        }

        $from = $_SESSION['uid'];
        $to = (string) $_POST['to'];
        $msg = htmlspecialchars((string) $_POST['msg']);
        $this->ctx->Im->sendToUser($from, $to, $msg);

        return $this->success();
    }

    //上线处理
    //todo 无用代码，这里是因为没有账号系统，实际开发中上线通知下发应该是在online回调服务端的时候
    public function pushOnline()
    {
        if (empty($_SESSION['uid'])) {
            throw new \Exception("非法的请求");
        }

        $this->ctx->Im->pushOnline($_SESSION['uid'], $_SESSION['name']);

        return $this->success();
    }

    //todo 无用代码，这里是因为没有账号系统，实际开发中上线通知下发应该是在online回调服务端的时候
    public function getSelfUidUrl()
    {
        if (empty($_SESSION['uid'])) {
            throw new \Exception("非法的请求");
        }

        return $this->success($_SESSION['uid']);
    }

    public function getGroupOnlineUsers()
    {
        if (empty($_SESSION['uid'])) {
            throw new \Exception("非法的请求");
        }

        $group = (string) $_GET['group'];
        return $this->success($this->ctx->Im->getGroupOnlineUsers($group));
    }
}
