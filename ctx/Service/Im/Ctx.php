<?php

namespace Ctx\Service\Im;

use App\Helpers\Log;
use Ctx\Basic\Ctx as BasicCtx;
use Ctx\Service\Im\Child\JsonRPC;
use Tree6bee\Support\Helpers\Arr;

/**
 * 模块接口声明文件
 * 备注：文件命名跟模块中的其他类不同，因为要防止模块声明类只能被实例化一次
 * 也就是只能用ctx->模块 来实例化，不能用loadC来实例化更多
 */
class Ctx extends BasicCtx
{
    /**
     * @var JsonRPC
     */
    private $rpcClient;

    /**
     * @var \Predis\Client
     */
    private $redis;

    private $redisKey = 'im:';

    public function init()
    {
        $this->rpcClient = $this->loadC('JsonRPC');
        $this->redis = $this->ctx->Ctx->loadRedis();
    }

    /*---其它---*/
    /**
     * @deprecated 调试代码
     */
    // protected $rpc = array(
    //     'host'      => 'http://ctx.sh7ne.dev/public/rpc.php',
    //     'method'    => array(
    //         'debug',
    //     ),
    // );

    /**
     * rpc 测试代码
     */
    public function getStaticHost()
    {
        return $this->getItem('static_host');
    }

    public function getApiHost()
    {
        return $this->getItem('api_host');
    }

    //TODO 按照一定策略获取机器，如同一个群组的在一个机器或则cpu空闲的机器
    //todo 增加逻辑同一个账号同一个平台类型不能多次登录
    public function getConnectInfo($uid)
    {
        $cometAdrr = $this->getItem('ws_host'); //todo 需要修改 集群中动态获取
        $connId = uniqid(); //todo 需要修改 需要生成唯一的 id
        $token = md5($uid); //todo 需要修改

        $clientInfo = json_encode([
            'uid'       => $uid,
        ]);
        $t = $this->getTokenInfo($connId, $token, $clientInfo);

        $this->redis->set(
            $this->getAuthKey($connId),
            $t,
            'Ex',
            60 //60s过期
        );

        return sprintf('%s/ws?c=%s&t=%s&i=%s', $cometAdrr, $connId, $token, rawurlencode($clientInfo));
    }

    private function getAuthKey($connId)
    {
        return $this->redisKey . 'auth:' . $connId;
    }

    private function getTokenInfo($connId, $token, $clientInfo)
    {
        return json_encode([
            'conn_id'   => $connId,
            'token'     => $token,
            'info'      => $clientInfo, //client 其他可携带信息 string 类型 clientInfo
        ]);
    }

    //校验 uid 和 token 和 conn_id 当前comet机器的addr 都需要传递，防止伪造或则连接非指定的机器
    //todo 同一个账号不能多次登录
    public function _checkToken($connId, $token, $clientInfo, $rpcAddr)
    {
        $redisKey = $this->getAuthKey($connId);
        $t = $this->redis->get($redisKey);

        if ($t == $this->getTokenInfo($connId, $token, $clientInfo)) {
            $this->redis->del($redisKey);
            return true;
        }

        throw new \Exception('校验失败');
    }

    //todo 后期hash过大可以按照id hash 拆分
    //映射 机器 上存在的 连接
    private function getComet2ConnKey($rpcAddr)
    {
        return $this->redisKey. 'comet:map:' . $rpcAddr;
    }

    //在线用户
    private function getOnlineKey()
    {
        return $this->redisKey. 'online:map';
    }

    private function parseClientInfo($clientInfo)
    {
        try {
            $clientInfo = $this->json_decode($clientInfo);
            return $clientInfo;
        } catch (\Exception $e) {
            throw new \Exception('解析clientInfo出错 >> ' . $e->getMessage());
        }
    }

    /**
     * client 上线 回调
     *
     * 在线状态上报处理
     */
    public function _online($connId, $clientInfo, $rpcAddr)
    {
        $uid = Arr::get($this->parseClientInfo($clientInfo), 'uid');
        $onlineKey = $this->getOnlineKey();

        //todo 加锁 防止并发的时候出错
        //添加用户 连接 到 所在的 机器
        $userConnInfo = $this->redis->hget($onlineKey, $uid);
        if (! empty($userConnInfo)) { //已经存在其他连接
            try {
                $userConnInfo = $this->json_decode($userConnInfo);
                $userConnInfo[$connId] = $rpcAddr;
                $this->redis->hset($onlineKey, $uid, json_encode($userConnInfo));
            } catch (\Exception $e) {
                $this->redis->hset($onlineKey, $uid, json_encode([
                    $connId => $rpcAddr,
                ]));
            }
        } else {
            $this->redis->hset($onlineKey, $uid, json_encode([
                $connId => $rpcAddr,
            ]));
        }

        //映射 机器 上存在的 连接
        $this->redis->hset($this->getComet2ConnKey($rpcAddr), $connId, $uid);

        //todo 这里是测试代码：固定加入群组，实际情况是群组功能单独的api
        $this->joinGroup(1, $uid);

        return true;
    }

    /**
     * client 离线 回调
     *
     * 离线状态上报处理
     */
    public function _offline($connId, $clientInfo, $rpcAddr)
    {
        $uid = Arr::get($this->parseClientInfo($clientInfo), 'uid');
        $onlineKey = $this->getOnlineKey();

        //todo 加锁 防止并发的时候出错
        //添加用户 连接 从 所在的 机器
        $userConnInfo = $this->redis->hget($onlineKey, $uid);
        if (! empty($userConnInfo)) { //已经存在其他连接
            try {
                $userConnInfo = $this->json_decode($userConnInfo);
                unset($userConnInfo[$connId]);
                if (! empty($userConnInfo)) {
                    $this->redis->hset($onlineKey, $uid, json_encode($userConnInfo));
                } else {
                    $this->redis->hdel($onlineKey, [$uid]);

                    //todo 这里是测试代码：固定加入群组，实际情况是群组功能单独的api
                    $this->leaveGroup(1, $uid);

                    //todo 无用代码，这里是因为没有账号系统，实际开发中不需要，因为在线状态已经能通过 getOnlineKey 获取到
                    $this->redis->hdel($this->getOnlineNicknameKey(), [$uid]);
                }
            } catch (\Exception $e) {
                $this->redis->hdel($onlineKey, [$uid]);
            }
        }

        //映射 机器 上存在的 连接
        $this->redis->hdel($this->getComet2ConnKey($rpcAddr), [$connId]);

        return true;
    }

    /**
     * comet server 上线 回调
     *
     * TODO 增加到可用 comet server 列表中
     */
    public function _addCometServer($node, $revision)
    {
//        throw new \Exception($node . " add : " . $revision);
        return true;
    }

    /**
     * comet server 下线 回调
     *
     * TODO 从路由中移除机器对应的所有的 id
     */
    public function _removeCometServer($node, $revision)
    {
//        throw new \Exception($node . " remove : " . $revision);
        return true;
    }

    //在线用户
    private function geGroupKey($group)
    {
        return $this->redisKey. 'group:map:' . $group;
    }

    //加入讨论组
    private function joinGroup($group, $uid)
    {
        $redisKey = $this->geGroupKey($group);
        $this->redis->hset($redisKey, $uid, 1);
    }

    //离开讨论组
    private function leaveGroup($group, $uid)
    {
        $redisKey = $this->geGroupKey($group);
        $this->redis->hdel($redisKey, [$uid]);
    }

    //获取讨论组成员
    //todo 这里是测试代码：固定加入群组，实际情况是群组功能单独的api
    private function getGroupUsers($group)
    {
        $redisKey = $this->geGroupKey($group);
        return array_keys($this->redis->hgetall($redisKey));
    }

    /**
     * @param $uidArr
     * @return array 如:
     *      array(
     *          'uid'   => json_encode(array(
     *              'conn_id_1' => comet1,
     *          ))
     *      )
     */
    private function getUsersConnections($uidArr)
    {
        if (empty($uidArr)) {
            return [];
        }

        $onlineKey = $this->getOnlineKey();
        $connections = $this->redis->hmget($onlineKey, ...$uidArr);

        return array_combine($uidArr, $connections);
    }

    private function getUsersConnectionsGroupByCometAddr(array $uidArr)
    {
        $ret = $this->getUsersConnections($uidArr);

        $connections = [];
        foreach ($ret as $uid => $row) {
            if (empty($row)) { //uid 不存在连接
                //todo 后续增加更多处理
                continue;
            }

            foreach ($this->json_decode($row) as $connId => $addr) {
                $connections[$addr][] = $connId;
            }
        }

        return $connections;
    }

    //消息类型
    const MESSAGE_TYPE_PERSON = 'person';
    const MESSAGE_TYPE_GROUP = 'group';

    const MESSAGE_CONTENT_TYPE_TEXT = 'text';
    const MESSAGE_CONTENT_TYPE_ONLINE = 'online';

    public function sendToUser($from, $to, $msg)
    {
        //私聊需要双写
        $connections = $this->getUsersConnectionsGroupByCometAddr([$from, $to]);

        foreach ($connections as $addr => $connIds) {
            list($host, $port) = explode(':', $addr);

            $msgBody = json_encode([
                'from'          => $from,
                'to'            => $to,
                'type'          => self::MESSAGE_TYPE_PERSON,
                'contentType'   => self::MESSAGE_CONTENT_TYPE_TEXT,
                'content'       => $msg,
            ]);
            //todo 判断发送结果
            $ret = $this->rpcClient->SendToConnections($host, $port, $this->getItem('comet_rpc_token'), $connIds, $msgBody);
            Log::error(var_export($ret, true));
        }

        return true;
    }

    public function sendToGroup($from, $to, $msg)
    {
        $uids = $this->getGroupUsers($to);
        $connections = $this->getUsersConnectionsGroupByCometAddr((array) $uids);

        foreach ($connections as $addr => $connIds) {
            list($host, $port) = explode(':', $addr);

            $msgBody = json_encode([
                'from'          => $from,
                'to'            => $to,
                'type'          => self::MESSAGE_TYPE_GROUP,
                'contentType'   => self::MESSAGE_CONTENT_TYPE_TEXT,
                'content'       => $msg,
            ]);
            //todo 判断发送结果
            $this->rpcClient->SendToConnections($host, $port, $this->getItem('comet_rpc_token'), $connIds, $msgBody);
        }

        return true;
    }

    private function json_decode($string)
    {
        $data = json_decode($string, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            throw new \Exception(sprintf('json 数据解析错误，string: %s, error: %s'), $string, json_last_error_msg());
        }

        return $data;
    }

    private function getOnlineNicknameKey()
    {
        return $this->redisKey . 'nickname:map';
    }

    //todo 无用代码，这里是因为没有账号系统，实际开发中上线通知下发应该是在online回调服务端的时候
    public function pushOnline($uid, $name)
    {
        $this->redis->hset($this->getOnlineNicknameKey(), $uid, $name);
    }

    public function getGroupOnlineUsers($group)
    {
        $uids = $this->getGroupUsers($group);

        $nicknames = $this->redis->hgetall($this->getOnlineNicknameKey());

        $ret = [];
        foreach ($uids as $uid) {
            $ret[$uid] = $nicknames[$uid];
        }

        return $ret;
    }
}
