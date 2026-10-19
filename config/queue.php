<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue API supports an assortment of back-ends via a single
    | API, giving you convenient access to each back-end using the same
    | syntax for every one. Here you may define a default connection.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'sync'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection information for each server that
    | is used by your application. A default configuration has been added
    | for each back-end shipped with Laravel. You are free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => 'localhost',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => env('REDIS_QUEUE', 'default'),
            // 必须大于 worker 的 --timeout（docker-compose 里是 180）。
            // 否则任务还在执行，redis 已把同一任务重新投递给另一个 worker，
            // 造成同一条消息被重复发送（本项目历史上出现过）。
            // 取 timeout 的 2 倍，留出网络/DB 抖动余量。
            'retry_after' => 360,

            // 阻塞取任务：worker 没任务时 blpop 住 :notify，有任务立刻被唤醒。
            //
            // ⚠️ 留 null 的话 RedisQueue 走非阻塞 eval：pop 不到任务就返回 null，
            // Worker::runNextJob() 接着 sleep(--sleep)，docker-compose 里是 3 秒。
            // 机器人消息是低频的，worker 几乎总在睡 —— 每条消息凭空白等 0~3s（均值 1.5s），
            // 表现为「webhook 秒回 200，但群里好几秒才看到回复」。
            // pushRaw 会同时 rpush 到 <queue>:notify，所以这里一设就能被立刻唤醒。
            //
            // 取值要小于 retry_after、大于单任务耗时；0 = 一直阻塞（这里给 5 保守些）。
            'block_for' => (int) env('REDIS_QUEUE_BLOCK_FOR', 5),
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control which database and table are used to store the jobs that
    | have failed. You may change them to any database / table you wish.
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],

];
