<?php

namespace App\Controllers\v1;

use App\Controllers\BaseController; // 基於 Anser-Gateway 的 BaseController
use PhpAmqpLib\Message\AMQPMessage;
use Config\RabbitMQ; // 假設你有一個設定檔

class OrderController extends BaseController
{
    public function create()
    {
        // 1. 驗證請求 (Validate)
        $data = $this->request->getJSON(true);
        if (empty($data['user_id']) || empty($data['amount'])) {
            return $this->fail('Invalid data', 400);
        }

        // 2. 封裝 Payload (Event Envelope 結構)
        $requestId = bin2hex(random_bytes(16));
        $jobPayload = json_encode([
            'type'          => 'CreateOrderSaga',
            'requestId'     => $requestId,
            'occurredAt'    => date('c'),
            'payload'       => $data,
            'meta'          => [
                'client_ip' => $this->request->getIPAddress(),
                'user_agent'=> $this->request->getUserAgent()->getAgentString()
            ]
        ]);

        // 3. 推送到 Request Queue (Producer)
        // 注意：這裡應該使用依賴注入獲取 RabbitMQ 連線
        $connection = service('RabbitMQ'); 
        $channel = $connection->channel();
        $channel->queue_declare('anser_request_queue', false, true, false, false);

        $msg = new AMQPMessage($jobPayload, ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
        $channel->basic_publish($msg, '', 'anser_request_queue');

        // 4. 快速回應 202 Accepted
        return $this->respond([
            'status' => 202,
            'message' => 'Request accepted, processing in background.',
            'request_id' => $requestId
        ], 202);
    }
}