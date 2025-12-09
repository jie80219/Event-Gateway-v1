<?php
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

$containerBuilder = new ContainerBuilder();

$containerBuilder->addDefinitions([
    // RabbitMQ Connection
    \PhpAmqpLib\Connection\AMQPStreamConnection::class => function (ContainerInterface $c) {
        return new \PhpAmqpLib\Connection\AMQPStreamConnection(
            getenv('RABBITMQ_HOST'), 5672, 'guest', 'guest'
        );
    },

    // Event Store Client
    'EventStoreClient' => function (ContainerInterface $c) {
        // Return initialized EventStore connection
    },

    // Saga Binding
    \Anser\ApiGateway\Saga\OrderSaga::class => \DI\autowire(),
]);

return $containerBuilder->build();