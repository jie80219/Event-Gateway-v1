<?php
require_once __DIR__ . '/../../init.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use SDPMlab\AnserEDA\EventBus;
use SDPMlab\AnserEDA\HandlerScanner;
use SDPMlab\AnserEDA\MessageQueue\MessageBus;
use SDPMlab\AnserEDA\EventStore\EventStoreDB;

function listEventClasses(string $eventDir): array
{
    if (!is_dir($eventDir)) {
        return [];
    }

    $files = glob($eventDir . '/*.php') ?: [];
    $classes = [];
    foreach ($files as $file) {
        $class = 'App\\Events\\' . basename($file, '.php');
        if (class_exists($class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

function classShortName(string $class): string
{
    $pos = strrpos($class, '\\');
    return $pos === false ? $class : substr($class, $pos + 1);
}

function buildEventInstance(string $eventClass, array $payload): ?object
{
    if (!class_exists($eventClass)) {
        return null;
    }

    if ($eventClass === \App\Events\OrderCreateRequestedEvent::class) {
        return new $eventClass($payload);
    }

    $reflection = new \ReflectionClass($eventClass);
    $constructor = $reflection->getConstructor();
    if ($constructor === null) {
        return $reflection->newInstance();
    }

    $args = [];
    foreach ($constructor->getParameters() as $param) {
        $name = $param->getName();
        if (array_key_exists($name, $payload)) {
            $args[] = $payload[$name];
        } elseif ($param->isDefaultValueAvailable()) {
            $args[] = $param->getDefaultValue();
        } else {
            $args[] = null;
        }
    }

    return $reflection->newInstanceArgs($args);
}

$rabbitHost = getenv('RABBITMQ_HOST') ?: 'localhost';
$rabbitPort = (int) (getenv('RABBITMQ_PORT') ?: 5672);
$rabbitUser = getenv('RABBITMQ_USER') ?: 'guest';
$rabbitPass = getenv('RABBITMQ_PASS') ?: 'guest';

$eventStoreHost = getenv('EVENTSTORE_HOST') ?: 'localhost';
$eventStorePort = (int) (getenv('EVENTSTORE_HTTP_PORT') ?: 2113);
$eventStoreUser = getenv('EVENTSTORE_USER') ?: 'admin';
$eventStorePass = getenv('EVENTSTORE_PASS') ?: 'changeit';

try {
    $connection = new AMQPStreamConnection($rabbitHost, $rabbitPort, $rabbitUser, $rabbitPass);
    $channel = $connection->channel();

    $exchangeName = 'events';
    $channel->exchange_declare($exchangeName, 'direct', false, true, false);

    $messageBus = new MessageBus($channel);
    $eventStoreDB = new EventStoreDB($eventStoreHost, $eventStorePort, $eventStoreUser, $eventStorePass);
    $eventBus = new EventBus($messageBus, $eventStoreDB);

    $handlerScanner = new HandlerScanner();
    $handlerScanner->scanAndRegisterHandlers('App\\Sagas', $eventBus);

    $eventDir = realpath(__DIR__ . '/../Events');
    $eventClasses = listEventClasses($eventDir ?: '');

    $callback = function ($msg) use ($eventBus) {
        $payload = json_decode($msg->body, true);
        if (!is_array($payload)) {
            echo " [!] Invalid message payload. Dropping.\n";
            $msg->ack();
            return;
        }

        $eventType = $payload['type'] ?? null;
        $eventData = $payload['data'] ?? null;

        if (!$eventType || !is_array($eventData)) {
            echo " [!] Missing event type or data. Dropping.\n";
            $msg->ack();
            return;
        }

        try {
            $event = buildEventInstance($eventType, $eventData);
            if ($event === null) {
                echo " [!] Unknown event class: {$eventType}. Dropping.\n";
                $msg->ack();
                return;
            }

            $eventBus->dispatch($event);
            echo " [v] Event handled: {$eventType}\n";
            $msg->ack();
        } catch (\Throwable $e) {
            echo " [!] Handler error: " . $e->getMessage() . "\n";
            sleep(1);
            $msg->nack(true);
        }
    };

    if (!$eventClasses) {
        echo " [!] No event classes found to bind.\n";
    }

    $channel->basic_qos(null, 1, null);
    foreach ($eventClasses as $eventClass) {
        $queueName = classShortName($eventClass);
        $channel->queue_declare($queueName, false, true, false, false);
        $channel->queue_bind($queueName, $exchangeName, $queueName);
        $channel->basic_consume($queueName, '', false, false, false, false, $callback);
    }

    echo " [*] Event Consumer started. Waiting for events...\n";
    while ($channel->is_consuming()) {
        $channel->wait();
    }

    $channel->close();
    $connection->close();
} catch (\Throwable $e) {
    echo "Critical Error during startup: " . $e->getMessage() . "\n";
}