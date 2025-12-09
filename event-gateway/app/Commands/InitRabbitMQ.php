<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Wire\AMQPTable;

class InitRabbitMQ extends BaseCommand
{
    /**
     * The Command's Group
     *
     * @var string
     */
    protected $group = 'RabbitMQ';

    /**
     * The Command's Name
     *
     * @var string
     */
    protected $name = 'rabbitmq:init';

    /**
     * The Command's Description
     *
     * @var string
     */
    protected $description = 'Initialize RabbitMQ Exchanges, Queues, and Bindings for Event-Gateway.';

    public function run(array $params)
    {
        CLI::write("🚀 Starting RabbitMQ Initialization...", 'yellow');

        $host = getenv('RABBITMQ_HOST') ?: 'anser_rabbitmq';
        $port = getenv('RABBITMQ_PORT') ?: 5672;
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $pass = getenv('RABBITMQ_PASS') ?: 'guest';

        try {
            $connection = new AMQPStreamConnection($host, $port, $user, $pass);
            $channel = $connection->channel();

            // ==========================================
            // 1. 定義常數名稱 (與架構圖對齊)
            // ==========================================
            $exchangeName = 'anser_event_bus';      // 事件總線 (Topic)
            $dlxExchange  = 'anser_dlx';            // 死信交換機
            $dlqName      = 'anser_dead_letter_queue';
            
            // 佇列清單
            $queues = [
                'request' => 'anser_request_queue',       // Gateway 入口緩衝
                'order'   => 'service_order_queue',       // Order Service
                'payment' => 'service_payment_queue',     // Payment Service
                'reply'   => 'anser_saga_reply_queue',    // Saga Reply
            ];

            // ==========================================
            // 2. 建立 Dead Letter Exchange & Queue (死信機制)
            // ==========================================
            CLI::write("   [DLQ] Setting up Dead Letter architecture...", 'cyan');
            
            // 宣告死信交換機 (Fanout 模式，無差別接收所有失敗訊息)
            $channel->exchange_declare($dlxExchange, 'fanout', false, true, false);
            
            // 宣告死信佇列
            $channel->queue_declare($dlqName, false, true, false, false);
            
            // 綁定死信佇列
            $channel->queue_bind($dlqName, $dlxExchange);


            // ==========================================
            // 3. 建立主要 Event Bus
            // ==========================================
            CLI::write("   [Bus] Declaring Main Exchange: {$exchangeName}", 'cyan');
            $channel->exchange_declare($exchangeName, 'topic', false, true, false);


            // ==========================================
            // 4. 建立並綁定各個工作佇列
            // ==========================================
            
            // 設定一般佇列的參數 (發生錯誤或被拒絕時，轉送到 DLX)
            $queueArgs = new AMQPTable([
                'x-dead-letter-exchange' => $dlxExchange,
                // 'x-message-ttl' => 60000 // 可選：訊息存活時間
            ]);

            foreach ($queues as $role => $queueName) {
                CLI::write("   [Queue] Declaring queue: {$queueName}", 'light_gray');
                
                // 宣告持久化佇列 (Durable = true)
                $channel->queue_declare($queueName, false, true, false, false, false, $queueArgs);

                // 根據角色設定 Routing Key 綁定
                switch ($role) {
                    case 'request':
                        // Gateway 收到 HTTP 請求後，直接送到這裡
                        // 這裡可以不綁定 Exchange，直接用 Default Exchange 推送，但綁定比較靈活
                        $channel->queue_bind($queueName, $exchangeName, 'request.new');
                        break;
                    
                    case 'order':
                        // Order Service 監聽與訂單相關的命令
                        $channel->queue_bind($queueName, $exchangeName, 'command.order.#');
                        $channel->queue_bind($queueName, $exchangeName, 'event.order.#');
                        break;

                    case 'payment':
                        // Payment Service 監聽與付款相關的命令
                        $channel->queue_bind($queueName, $exchangeName, 'command.payment.#');
                        break;

                    case 'reply':
                        // Saga 監聽所有服務的回覆 (Reply)
                        // 通常是 event.*.success 或 event.*.failure
                        $channel->queue_bind($queueName, $exchangeName, 'reply.#');
                        break;
                }
            }

            $channel->close();
            $connection->close();

            CLI::write("✅ RabbitMQ Initialization Completed Successfully!", 'green');

        } catch (\Throwable $e) {
            CLI::error("❌ Initialization Failed: " . $e->getMessage());
            // 不要在這裡 exit，讓 CLI 可以顯示錯誤堆疊
        }
    }
}