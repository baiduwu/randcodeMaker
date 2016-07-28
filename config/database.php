<?php

return [

    'driver'    => 'mysql',

    'host'      => '127.0.0.1',

    'database'  => 'ry_marketing',

    'username'  => 'root',

    'password'  => 'rongyi',

    'charset'   => 'utf8',

    'collation' => 'utf8_general_ci',

    'prefix'    => '',
    'redis' => [
        'code_maker' => [
            'host'     => '127.0.0.1',
            'port'     => 6379,
            'database' => 0,
            'parameters'=>[
            'password' =>  ''
            ]
        ]
    ],

];