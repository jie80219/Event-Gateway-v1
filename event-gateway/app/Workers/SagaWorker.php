<?php

namespace App\Workers;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Orchestrators\CreateOrderOrchestrator;

class SagaWorker extends BaseCommand
{
    protected $group       = 'Queue';
    protected $name        = 'queue:listen';
    protected $description = 'Listen to RabbitMQ request queue and trigger Sagas';

    public function run(array $params)
    {
        $connection = service('RabbitMQ');
        $channel = $connection->channel();
        $channel->queue_declare('request_queue', false, true, false, false);

        CLI::write(" [*] Waiting for requests in 'request_queue'.", 'green');

        $callback = function ($msg) {
            $job = json_decode($msg->body, true);
            CLI::write(" [x] Received Request: " . $job['requestId']);

            try {
                // 路由分發：根據 type 決定啟動哪個 Saga
                if ($job['type'] === 'CreateOrderSaga') {
                    
                    // 初始化 Orchestrator (Anser-EDA 核心)
                    $orchestrator = new CreateOrderOrchestrator();

                    // 傳入資料並開始執行
                    $result = $orchestrator->build($job['payload'], $job['requestId']);
                    $isSuccess = is_array($result) ? ($result['success'] ?? false) : (bool) $result;

                    if ($isSuccess) {
                        CLI::write(" [v] Saga Completed: " . $job['requestId'], 'green');
                        $msg->ack(); // 成功才 Ack
                    } else {
                        CLI::write(" [!] Saga Failed (Rollback executed).", 'red');
                        // 根據策略決定是否 Ack 或進入 Dead Letter Queue
                        $msg->ack(); 
                    }
                }
            } catch (\Exception $e) {
                CLI::error($e->getMessage());
                $msg->nack(); // 處理失敗，放回佇列或丟棄
            }
        };

        $channel->basic_qos(null, 1, null);
        $channel->basic_consume('request_queue', '', false, false, false, false, $callback);

        while ($channel->is_consuming()) {
            $channel->wait();
        }
    }
}
