<?php

namespace Thruway;

use function GuzzleHttp\Psr7\parse_query;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\LoopInterface;
use React\Http\Response;
use Thruway\Event\ConnectionCloseEvent;
use Thruway\Event\ConnectionOpenEvent;
use Thruway\Event\RouterStartEvent;
use Thruway\Event\RouterStopEvent;
use Thruway\Exception\DeserializationException;
use Thruway\Logging\Logger;
use Thruway\Message\AbortMessage;
use Thruway\Message\HelloMessage;
use Thruway\Message\Message;
use Thruway\Peer\Router;
use Thruway\Peer\RouterInterface;
use Thruway\Serializer\JsonSerializer;
use Thruway\Transport\MiddlewareTransport;
use Thruway\Transport\RouterTransportProviderInterface;
use Voryx\WebSocketMiddleware\WebSocketConnection;
use Voryx\WebSocketMiddleware\WebSocketMiddleware;

final class Middleware implements RouterTransportProviderInterface
{
    /** @var Router */
    private $router;

    /** @var WebSocketMiddleware */
    private $webSocketMiddleware;

    /** @var LoopInterface */
    private $loop;

    /** @var bool */
    private $trusted = false;

    /** @var Session[] */
    private $sessions = [];

    /** @var bool */
    private $started = false;

    public function __construct(array $paths = [], LoopInterface $loop, Router $router = null)
    {
        $this->loop = $loop;

        if ($router === null) {
            $this->router = new Router($loop);
        }

        $serializer = new JsonSerializer();

        $this->webSocketMiddleware = new WebSocketMiddleware($paths, function (WebSocketConnection $conn, ServerRequestInterface $request, Response $response) use ($serializer) {
            if (!$this->started) {
                $conn->send(new AbortMessage((object)[], 'thruway.not.started'));
                $conn->close();
                return;
            }

            $transport = new MiddlewareTransport(
                function (Message $message) use ($conn, $serializer) {
                    $conn->send($serializer->serialize($message));
                },
                function () use ($conn) { // connection close
                    $conn->close();
                },
                function () use ($conn, $request) {
                    return [
                        "type"              => "http-middleware",
                        "transport-address" => $request->getServerParams()['REMOTE_ADDR'],
                        "headers"           => $request->getHeaders(),
                        "url"               => $request->getUri()->getPath(),
                        "query_params"      => parse_query($request->getUri()->getQuery()),
                        "cookies"           => $request->getHeader("Cookie")
                    ];
                }
            );

            $session = $this->router->createNewSession($transport);
            $this->sessions[$session->getSessionId()] = $session;

            $conn->on('message', function (\Ratchet\RFC6455\Messaging\Message $message) use ($session, $serializer) {
                $msg = $message->getPayload();
                Logger::debug($this, "onMessage: ({$msg})");

                try {
                    $msg = $serializer->deserialize($msg);

                    if ($msg instanceof HelloMessage) {
                        $details = $msg->getDetails();

                        $details->transport = (object) $session->getTransport()->getTransportDetails();

                        $msg->setDetails($details);
                    }

                    $session->dispatchMessage($msg);
                } catch (DeserializationException $e) {
                    Logger::alert($this, "Deserialization exception occurred.");
                } catch (\Thruway\Serializer\DeserializationException $e) {
                    Logger::alert($this, "Deserialization exception occurred.");
                } catch (\Exception $e) {
                    Logger::alert($this, "Exception occurred during onMessage: ".$e->getMessage());
                }
            });

            $conn->on('error', function () use ($session) {
                unset($this->sessions[$session->getSessionId()]);
                $this->router->getEventDispatcher()->dispatch('connection_close', new ConnectionCloseEvent($session));
            });

            $conn->on('close', function () use ($session) {
                unset($this->sessions[$session->getSessionId()]);
                $this->router->getEventDispatcher()->dispatch('connection_close', new ConnectionCloseEvent($session));
            });

            $this->router->getEventDispatcher()->dispatch("connection_open", new ConnectionOpenEvent($session));
        }, ['wamp.2.json']);

        $this->router->addTransportProvider($this);

        if ($router === null) {
            $this->router->start(false);
        }
    }

    public function __invoke(ServerRequestInterface $request, callable $next)
    {
        return $this->webSocketMiddleware->__invoke($request, $next);
    }

    // ----------- TransportProviderInterface

    public function handleRouterStart(RouterStartEvent $event) {
        $this->started = true;
    }

    public function handleRouterStop(RouterStopEvent $event) {
        $this->started = false;
        foreach ($this->sessions as $session) {
            $session->shutdown();
        }

        $this->sessions = [];
    }

    public static function getSubscribedEvents()
    {
        return [
            "router.start" => ["handleRouterStart", 10],
            "router.stop"  => ["handleRouterStop", 10]
        ];
    }

    public function initModule(RouterInterface $router, LoopInterface $loop)
    {
        if ($this->router !== $router) {
            throw new \Exception('initModule: router is not the same router as in the constructor.');
        }

        if ($this->loop !== $loop) {
            throw new \Exception('initModule: loop is not the same loop as in the constructor.');
        }

        $this->started = true;
    }

    public function getLoop()
    {
        return $this->loop;
    }

    public function setTrusted($trusted)
    {
        $this->trusted = $trusted;
    }
}
