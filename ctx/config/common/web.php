<?php

/**
 * 应用配置
 * -- environment (运行环境):
 *  - development(开发模式)
 *  - testing(单测模式暂时不考虑)
 *  - production(生产环境)
 *  - maintenance(维护模式)
 *
 * -- tfVersion (框架版本)
 * -- timezone (时区)
 *
 * - xhprof_dir util包路径
 *
 * @var array
 */
return [
    'debug'         => getenv('WEB_DEBUG'),
    'tfVersion'     => 'TFramework/2.0',
    'timezone'      => 'PRC',
    'session_name'  => 'IM_SS_ID',
];
