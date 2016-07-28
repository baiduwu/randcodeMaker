<?php
/**
 * Created by PhpStorm.
 * User: wuzhiqiang
 * Date: 2016/7/27
 * Time: 20:08
 */
use test\CodeMaker;
//require '../test/CodeMaker.php';
require '../vendor/autoload.php';
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Queue\Capsule\Manager as Queue;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container as Container;
use Illuminate\Redis;



$capsule = new Capsule();
/*$capsule->setEventDispatcher(new Dispatcher(new Container));*/
$capsule->setAsGlobal();
$capsule->addConnection(require '../config/database.php');
$capsule->bootEloquent();

//$queue = new Queue();
//$queue->addConnection(require '../config/queue.php', 'code_maker');
//$queue->setAsGlobal();

$container = new Container();
$server = array(
    'default' => [
        'code_maker' => [
            'host'     => '127.0.0.1',
            'port'     => 6379,
            'database' => 0,
            'parameters'=>[
                'password' =>  ''
            ]
        ]
    ],
);
$container->instance('redis',new Redis\Database($server));

//var_dump($container->getBindings());exit;




//$data = Capsule::table('case')->where(array('mall_id' => '51f9d7f731d6559b7d00014d'))->get();
//var_dump($data);
$servie = new CodeMaker();
//CodeMaker::getInstance()->applyService(102,1,10000,10,2000);
$servie->applyService(102,1,10000,10,2000);
echo 'hello world';