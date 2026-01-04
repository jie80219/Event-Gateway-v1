<?php

namespace App\Handlers;

use SDPMlab\AnserEDA\Attributes\EventHandler;
use App\Events\OrderCreateRequestedEvent;
use App\Orchestrators\CreateOrderOrchestrator;

class OrderCreateHandler
{
    /**
     * 這裡使用 Attribute 標記
     */
    #[EventHandler] 
    public function handle(OrderCreateRequestedEvent $event)
    {
        $traceId = $event->getTraceId();
        echo " [x] Handler: 開始處理訂單建立邏輯 (TraceID: " . ($traceId ?? 'unknown') . ")\n";
        
        // 1. 從事件中提取資料
        $eventData = $event->getData();
        
        // *假設* eventData 結構是: ['user_id' => 1, 'products' => [...]]
        $userId = $eventData['user_id'] ?? $eventData['userId'] ?? $eventData['userKey'] ?? null;
        $products = $eventData['productList']
            ?? $eventData['productList']
            ?? $eventData['products']
            ?? $event->productList
            ?? [];

        if (!$userId || empty($products)) {
            echo " [!] Error: 訂單資料不完整，無法啟動 Saga。\n";
            return;
        }

        // 2. 啟動 Orchestrator
        try {
            echo " [>] 啟動 Saga Orchestrator...\n";
            $orchestrator = new CreateOrderOrchestrator();
            $orchestrator->setOrderDetails($userId, $products);
            $result = $orchestrator->build();
            $isSuccess = is_array($result) ? ($result['success'] ?? false) : (bool) $result;
            if ($isSuccess) {
                echo " [v] Saga 執行成功！TraceID: " . ($traceId ?? 'unknown') . "\n";
            } else {
                echo " [x] Saga 執行失敗。\n";
            }

        } catch (\Exception $e) {
            echo " [!] Saga 執行發生例外: " . $e->getMessage() . "\n";
            // 這裡可以考慮是否要發送「失敗事件」回 Queue 或是寫入 Log
        }
    }
}
