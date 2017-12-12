<?php

use React\EventLoop\Factory;
use React\Http\Server;
use Thruway\ClientSession;
use Thruway\Middleware;
use Thruway\Peer\Client;
use Thruway\Peer\Router;

require __DIR__ . '/../vendor/autoload.php';

$loop = Factory::create();

$router = new Router($loop);

$internalClient = new Client('realm1', $loop);

$internalClient->on('open', function (ClientSession $session) {
    $session->getLoop()->addPeriodicTimer(1, function () use ($session) {
        static $x = 0;
        $session->publish('some.counting.topic', [$x++]);
    });
});

$router->addInternalClient($internalClient);

$router->start(false);

$thruway = new Middleware(['/thruway'], $loop, $router);

$server = new Server($thruway);
$server->listen(new \React\Socket\Server('tcp://127.0.0.1:9001', $loop));

$loop->run();
