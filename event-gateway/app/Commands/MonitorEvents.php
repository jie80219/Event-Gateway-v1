<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class MonitorEvents extends BaseCommand
{
    protected $group       = 'Anser';
    protected $name        = 'anser:monitor';
    protected $description = 'Listen to specific Anser-EDA events defined in InitRabbitMQ.';

    public function run(array $params)
    {
        CLI::write("👀 [Monitor] Connecting to RabbitMQ...", 'yellow');

        $host = getenv('RABBITMQ_HOST') ?: 'anser_rabbitmq';
        $port = getenv('RABBITMQ_PORT') ?: 5672;
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $pass = getenv('RABBITMQ_PASS') ?: 'guest';

        try {
            $connection = new AMQPStreamConnection($host, $port, $user, $pass);
            $channel = $connection->channel();

            // 1. 確保 Exchange 存在 (跟 InitRabbitMQ 保持一致：events / direct)
            $exchangeName = 'events';
            $channel->exchange_declare($exchangeName, 'direct', false, true, false);

            // 2. 建立一個「暫時、獨佔、自動刪除」的 Queue
            // 這樣監聽器關閉後，這個 Queue 就會自動消失，不會占用資源
            list($queue_name, ,) = $channel->queue_declare("", false, false, true, false);

            // 3. 定義你要監聽的事件名稱 (必須跟你 InitRabbitMQ 裡的 $eventQueues 一模一樣)
            $eventsToWatch = [
                'OrderCreateRequestedEvent',
                'InventoryDeductedEvent',
                'PaymentProcessedEvent',
                'OrderCreatedEvent',
                'RollbackInventoryEvent',
                'RollbackOrderEvent',
                
                // 也順便監聽入口請求，看看有沒有東西進來 (選用)
                // 'request.new' 
            ];

            CLI::write("   Bound to Exchange: {$exchangeName}", 'cyan');
            CLI::write("   Temporary Queue: {$queue_name}", 'dark_gray');

            // 4. 因為是 Direct 模式，必須手動將這個暫時 Queue 綁定到每一個你想聽的 Key
            foreach ($eventsToWatch as $routingKey) {
                $channel->queue_bind($queue_name, $exchangeName, $routingKey);
                CLI::write("   👂 Listening for: " . CLI::color($routingKey, 'green'));
            }

            CLI::newLine();
            CLI::write("🚀 Monitor is running... (Press Ctrl+C to exit)", 'white', 'blue');
            CLI::write("-----------------------------------------------------");

            // 5. 處理訊息的回呼函式
            $callback = function ($msg) {
                $routingKey = $msg->delivery_info['routing_key'];
                $body = $msg->body;
                
                // 嘗試解析 JSON 以便美化輸出
                $decoded = json_decode($body, true);
                $prettyBody = $decoded ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $body;

                CLI::write("🔥 [EVENT DETECTED] Key: " . CLI::color($routingKey, 'yellow'));
                CLI::write("📦 Payload:");
                CLI::write($prettyBody, 'cyan');
                CLI::write("-----------------------------------------------------");
            };

            $channel->basic_consume($queue_name, '', false, true, false, false, $callback);

            while ($channel->is_consuming()) {
                $channel->wait();
            }

            $channel->close();
            $connection->close();

        } catch (\Throwable $e) {
            CLI::error("❌ Monitor Error: " . $e->getMessage());
        }
    }
}