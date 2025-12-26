<?php

namespace App\Handlers;

use SDPMlab\AnserEDA\Attributes\EventHandler; 
use App\Events\OrderCreateOrchestratorRequestedEvent;
// 注意：這裡是 App\Orchestrators，不是 App\Anser\Orchestrators
use App\Orchestrators\CreateOrderOrchestrator; 

class OrderCreateHandler
{
    #[EventHandler] 
    public function handle(OrderCreateOrchestratorRequestedEvent $event)
    {
        $data = $event->getData();
        echo " [x] Handler: 開始處理訂單建立邏輯 (TraceID: " . $event->getTraceId() . ")\n";
        
        $eventData = $event->getData();
        $userId = $eventData['user_id'] ?? null;
        $products = $eventData['products'] ?? [];

        // 實例化編排器
        $orchestrator = new CreateOrderOrchestrator();

        // 注入資料 (請確保您的 CreateOrderOrchestrator 有這個方法)
        if (method_exists($orchestrator, 'setOrderDetails')) {
            $orchestrator->setOrderDetails($userId, $products);
        } else {
             echo " [!] Warning: Orchestrator 缺少 setOrderDetails 方法。\n";
        }
        
        try {
            echo " [>] 啟動 Saga Orchestrator...\n";
            
            // 執行 Saga
            $result = $orchestrator->build()->process();

            if ($result->isSuccess()) {
                // 如果您的 Orchestrator 有 getOrderId() 方法
                // echo " [v] Saga 執行成功！訂單 ID: " . $orchestrator->getOrderId() . "\n";
                echo " [v] Saga 執行成功！\n";
            } else {
                echo " [x] Saga 執行失敗。\n";
            }

        } catch (\Exception $e) {
            echo " [!] Saga 執行發生例外: " . $e->getMessage() . "\n";
        }
    }
}
