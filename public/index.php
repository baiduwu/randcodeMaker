<?php
/**
 * Created by PhpStorm.
 * User: wuzhiqiang
 * Date: 2016/7/27
 * Time: 20:08
 */
use Illuminate\Database\Capsule\Manager as Capsule;

require '../vendor/autoload.php';

$capsule = new Capsule();
$capsule->addConnection(require '../config/database.php');
$capsule->bootEloquent();

$data = Capsule::table('users')->where(array('mall_id' => '51f9d7f731d6559b7d00014d'))->get();
var_dump($data);
echo 'hello world';