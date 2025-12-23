<?php

namespace App\Orchestrators;

use SDPMlab\Anser\Orchestration\Orchestrator;
// 引入你專案現有的 RabbitMQ 連線類別
use SDPMlab\AnserEDA\MessageQueue\RabbitMQConnection; 
use PhpAmqpLib\Message\AMQPMessage;

class CreateOrderOrchestrator extends Orchestrator
{
    /** @var array<string,mixed> */
    protected array $payload = [];

    protected ?string $requestId = null;
    private ?RabbitMQConnection $mqConnection = null;

    /**
     * Anser 的 build() 是 final，實際的編排邏輯改寫 definition()。
     *
     * @param array<string,mixed> $data
     * @param string|null $requestId
     */
    protected function definition(array $data = [], ?string $requestId = null): void
    {
        // 1. 保存傳入的資料
        $this->payload = $data;
        $this->requestId = $requestId;

        // 2. 定義 Saga 流程 (Event-Driven)
        // ---------------------------------------------------------------------
        
        // [Step 1] 發送 "訂單建立請求"
        $this->setStep()
            ->do(function () {
                // 注意：這裡直接使用字串 'OrderCreateRequestedEvent' (短名稱)
                // 不要用 ::class，否則會帶有 Namespace，導致 RabbitMQ 路由失敗
                $this->publishToMQ('OrderCreateRequestedEvent', $this->payload);
            })
            ->compensate(function () {
                // 失敗補償：發送 "回滾訂單"
                $this->publishToMQ('RollbackOrderEvent', [
                    'order_id' => $this->payload['order_id'] ?? null,
                    'reason'   => 'Saga compensation triggered'
                ]);
            });

        // [Step 2] 發送 "庫存扣除" (模擬)
        $this->setStep()
            ->do(function () {
                $this->publishToMQ('InventoryDeductedEvent', $this->payload);
            })
            ->compensate(function () {
                $this->publishToMQ('RollbackInventoryEvent', $this->payload);
            });

        // [Step 3] 發送 "付款處理" (模擬)
        $this->setStep()
            ->do(function () {
                $this->publishToMQ('PaymentProcessedEvent', $this->payload);
            });

        // [Step 4] 流程結束，發送 "訂單建立成功"
        $this->setStep()
            ->do(function () {
                $this->publishToMQ('OrderCreatedEvent', [
                    'order_id'   => $this->payload['order_id'] ?? 'unknown',
                    'status'     => 'success',
                    'request_id' => $this->requestId,
                    'timestamp'  => time()
                ]);
            });
    }

    /**
     * 自訂成功回傳格式
     *
     * @return array<string,mixed>
     */
    protected function defineResult()
    {
        return [
            'success'   => $this->isSuccess(),
            'requestId' => $this->requestId,
            'data'      => $this->payload,
        ];
    }

    /**
     * 私有輔助方法：封裝 RabbitMQ 發送邏輯
     * 這樣就不用額外建立 Service 檔案，直接利用專案現有資源
     */
    private function publishToMQ(string $routingKey, array $messageData)
    {
        try {
            // 1. 取得 RabbitMQ 連線（避免重複建立連線）
            if ($this->mqConnection === null) {
                $host = getenv('RABBITMQ_HOST') ?: 'anser_rabbitmq';
                $port = (int)(getenv('RABBITMQ_PORT') ?: 5672);
                $user = getenv('RABBITMQ_USER') ?: 'guest';
                $pass = getenv('RABBITMQ_PASS') ?: 'guest';
                $this->mqConnection = new RabbitMQConnection($host, $port, $user, $pass);
            }
            $channel = $this->mqConnection->getChannel();

            // 2. 準備訊息 (JSON 格式，持久化)
            $msgBody = json_encode($messageData);
            $msg = new AMQPMessage($msgBody, [
                'delivery_mode' => 2, // 2 = Persistent (持久化訊息)
                'content_type'  => 'application/json'
            ]);

            // 3. 發送到 'events' Exchange
            // 這裡的 $routingKey 就是上面傳入的短名稱 (例如 'OrderCreatedEvent')
            $channel->basic_publish($msg, 'events', $routingKey);

            // (選用) 在 Worker 終端印出 Log，方便你除錯看到進度
            echo "   [->] Published Event: {$routingKey}\n";

        } catch (\Throwable $e) {
            // 錯誤處理：避免單一事件發送失敗導致整個 Worker 崩潰
            echo "   [!!!] MQ Publish Error: " . $e->getMessage() . "\n";
        }
    }
}
