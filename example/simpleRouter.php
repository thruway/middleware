<?php

use React\EventLoop\Factory;
use React\Http\Server;
use Thruway\Middleware;

require __DIR__ . '/../vendor/autoload.php';

$loop = Factory::create();

$thruway = new Middleware(['/thruway'], $loop);

$server = new Server($thruway);
$server->listen(new \React\Socket\Server('tcp://127.0.0.1:9001', $loop));

$loop->run();
