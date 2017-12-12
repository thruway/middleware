<?php
namespace Thruway\Transport;

use Thruway\Message\Message;

final class MiddlewareTransport extends AbstractTransport {
    private $sender;
    private $closer;
    private $getTransDetails;

    public function __construct(callable $sender, callable $closer, callable $getTransportDetails)
    {
        $this->sender          = $sender;
        $this->closer          = $closer;
        $this->getTransDetails = $getTransportDetails;
    }

    public function getTransportDetails()
    {
        return call_user_func($this->getTransDetails);
    }

    public function sendMessage(Message $msg)
    {
        return call_user_func($this->sender, $msg);
    }

    public function close()
    {
        return call_user_func($this->closer);
    }
}
