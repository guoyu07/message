<?php
// $redis->connect($ip, $port, $timeout);
return array(
    'default'  => array(    //默认redis
        'host'      => getenv('REDIS_HOST'),
        'port'      => getenv('REDIS_PORT'),
        'timeout'   => 3,
    ),
    'test'  => array(
        'host'      => '127.0.0.1',
        'port'      => 6379,
        'timeout'   => 3,
    ),
);
