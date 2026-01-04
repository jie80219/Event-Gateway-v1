<?php
require_once __DIR__ . '/../../init.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use SDPMlab\AnserEDA\EventBus;
use SDPMlab\AnserEDA\MessageQueue\MessageBus;
use SDPMlab\AnserEDA\EventStore\EventStoreDB;
// 移除特定的 Event 引用，改由動態載入
// use App\Events\OrderCreateRequestedEvent; 
// use App\Handlers\OrderCreateHandler;

/**
 * 1. 路由表設定 (Route Mapping)
 * 這裡定義 "路由名稱" 對應到 "哪個事件類別"
 * 未來可以在設定檔(Config)中管理這個陣列
 */
$eventMapping = [
    'order.create'   => \App\Events\OrderCreateRequestedEvent::class,
    // TODO: 需要先建立對應 Event 類別後再啟用
    // 'order.cancel'   => \App\Events\OrderCancelRequestedEvent::class,
    // 'payment.process'=> \App\Events\PaymentProcessRequestedEvent::class,
];

// RabbitMQ 連線資訊
$rabbitHost = getenv('RABBITMQ_HOST') ?: 'localhost';
$rabbitPort = (int) (getenv('RABBITMQ_PORT') ?: 5672);
$rabbitUser = getenv('RABBITMQ_USER') ?: 'guest';
$rabbitPass = getenv('RABBITMQ_PASS') ?: 'guest';

// EventStoreDB 連線資訊
$eventStoreHost = getenv('EVENTSTORE_HOST') ?: 'localhost';
$eventStorePort = (int) (getenv('EVENTSTORE_HTTP_PORT') ?: 2113);
$eventStoreUser = getenv('EVENTSTORE_USER') ?: 'admin';
$eventStorePass = getenv('EVENTSTORE_PASS') ?: 'changeit';

try {
    // 建立連線
    $connection = new AMQPStreamConnection($rabbitHost, $rabbitPort, $rabbitUser, $rabbitPass);
    $channel = $connection->channel();
    $queueName = getenv('REQUEST_QUEUE') ?: 'request_queue'; // 建議改名為 gateway_request_queue
    
    // 宣告佇列 (Durable = true 以防 RabbitMQ 重啟後資料遺失)
    $channel->queue_declare($queueName, false, true, false, false);
    
    // 建立 EventBus 依賴
    $messageBus = new MessageBus($channel);
    $eventStoreDB = new EventStoreDB($eventStoreHost, $eventStorePort, $eventStoreUser, $eventStorePass);
    $eventBus = new EventBus($messageBus, $eventStoreDB);
    
    echo " [*] Event Gateway Consumer started. Waiting for requests...\n";
    
    $callback = function ($msg) use ($eventBus, $eventMapping) {
        echo " [x] Received Message\n";
    
        $payload = json_decode($msg->body, true);
        
        // 2. 解析 Payload 結構
        // 假設 Gateway 傳來的格式是: { "route": "order.create", "id": "uuid...", "data": {...} }
        $route     = $payload['route'] ?? null;
        $traceId   = $payload['id'] ?? uniqid();
        $eventData = $payload['data'] ?? [];
    
        if (!$route || !isset($eventMapping[$route])) {
            echo " [!] Error: Unknown route '$route'. Dropping message.\n";
            // 如果是不認識的路由，選擇 Ack 掉以免卡住 Queue，或者 Nack 並記錄 Log
            $msg->ack(); 
            return;
        }
    
        $eventClass = $eventMapping[$route];
        echo " [i] Mapping route '$route' to event '$eventClass'\n";
    
        try {
            if (!class_exists($eventClass)) {
                throw new Exception("Class $eventClass not found");
            }

            switch ($route) {
                case 'order.create':
                    $productList = $eventData['product_list'] ?? $eventData['productList'] ?? null;
                    if (!is_array($productList)) {
                        echo " [!] Error: product_list must be an array.\n";
                        $msg->ack();
                        return;
                    }
                    break;
            }

            $eventBus->publish($eventClass, array_merge($eventData, [
                'trace_id' => $traceId
            ]));

            echo " [v] Event Published Successfully for trace: $traceId\n";
            $msg->ack();
        } catch (Throwable $e) {
            echo " [!] System Error: " . $e->getMessage() . "\n";
            // 根據錯誤類型決定策略：
            // - 暫時性錯誤 (DB 連線失敗) -> $msg->nack(true); (Requeue)
            // - 永久性錯誤 (資料格式錯誤) -> $msg->ack(); (Discard)
            
            // 這裡示範保守策略，稍後重試
            // 注意：如果一直失敗會造成無窮迴圈，建議搭配 Dead Letter Exchange (DLX)
            sleep(1); 
            $msg->nack(true); 
        }
    };
    
    // 設定 QoS: 每次只拿 1 個任務，處理完再拿新的 (Fair dispatch)
    $channel->basic_qos(null, 1, null);
    $channel->basic_consume($queueName, '', false, false, false, false, $callback);
    
    while ($channel->is_consuming()) {
        $channel->wait();
    }
    
    $channel->close();
    $connection->close();

} catch (Exception $e) {
    echo "Critial Error during startup: " . $e->getMessage() . "\n";
}
