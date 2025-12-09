<?php

namespace Anser\ApiGateway\Worker;

use Anser\ApiGateway\Saga\OrderSaga;
use Anser\Shared\DTO\EventEnvelope;
use PhpAmqpLib\Message\AMQPMessage;

class RequestConsumer
{
    public function __construct(
        private OrderSaga $orderSaga
    ) {}

    public function process(AMQPMessage $msg)
    {
        $body = json_decode($msg->body, true);
        $correlationId = $msg->get('correlation_id');

        // Restore Context
        if ($body['action'] === 'create_order') {
            // Start Saga
            try {
                $this->orderSaga->start($body['data'], $correlationId);
                // Ack message
                $msg->ack();
            } catch (\Throwable $e) {
                // Handle retry or Dead Letter Queue
                $msg->nack();
            }
        }
    }
}