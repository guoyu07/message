<?php

return [
    'static_host'       => getenv('STATIC_HOST'),
    'api_host'          => getenv('API_HOST'),
    'ws_host'           => getenv('WS_HOST'), //动态获取，变更为，非配置
    'comet_rpc_token'   => getenv('COMET_RPC_TOKEN'),
];
