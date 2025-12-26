<?php 
namespace App\Controllers\v1;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\IncomingRequest;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class OrderController extends BaseController
{
    use ResponseTrait;

    public function create()
    {
        /** @var IncomingRequest $request */
        $request = $this->request;

        // 1. 接收請求資料
        $data = $request->getJSON(true) ?? [];
        $traceId = $request->getHeaderLine('X-Correlation-ID') ?: uniqid('txn_', true);

        $routingKey = env('REQUEST_ROUTING_KEY', 'order.create');

        // 2. 定義事件格式 (CloudEvents 規範)
        $eventPayload = json_encode([
            "specversion" => "1.0",
            "type"        => "com.anser.order.create",
            "route"       => $routingKey,
            "source"      => "/gateway/order",
            "id"          => $traceId,
            "time"        => date(DATE_RFC3339),
            "data"        => $data,
        ]);

        try {
            // 3. 連接 RabbitMQ
            $connection = new AMQPStreamConnection(
                env('RABBITMQ_HOST', 'anser_rabbitmq'),
                (int) env('RABBITMQ_PORT', 5672),
                env('RABBITMQ_USER', 'guest'),
                env('RABBITMQ_PASS', 'guest')
            );
            $channel = $connection->channel();

            // 4. 宣告 Exchange / Queue 並綁定 Routing Key
            $exchangeName = env('REQUEST_EXCHANGE', 'events');
            $queueName    = env('REQUEST_QUEUE', 'request_queue');

            $channel->exchange_declare($exchangeName, 'direct', false, true, false);
            $channel->queue_declare($queueName, false, true, false, false);
            $channel->queue_bind($queueName, $exchangeName, $routingKey);

            // 5. 發送訊息
            $msg = new AMQPMessage($eventPayload, ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
            $channel->basic_publish($msg, $exchangeName, $routingKey);

            $channel->close();
            $connection->close();

            // 6. 回傳「非同步」成功回應 (202 Accepted)
            return $this->respond([
                "status" => "Accepted",
                "message" => "Order request queued for processing.",
                "trace_id" => $traceId
            ], 202);

        } catch (\Exception $e) {
            log_message('error', '[RabbitMQ Error] ' . $e->getMessage());
            return $this->failServerError('Queue Service Unavailable');
        }
    }
}
