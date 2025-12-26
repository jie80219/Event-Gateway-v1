<?php
// app/Orchestrators/CreateOrderOrchestrator.php

namespace App\Orchestrators;

use SDPMlab\Anser\Orchestration\Orchestrator;
use SDPMlab\Anser\Service\ServiceList; // 引入 ServiceList

class CreateOrderOrchestrator extends Orchestrator
{
    /**
     * 暫存 Handler 傳過來的資料
     */
    protected $userId;
    protected $orderData;

    /**
     * 初始化協調器 (建構子)
     */
    public function __construct()
    {
        // 確保在協調器啟動時，有註冊微服務的位置
        // 如果你在全域設定檔(init.php)做過了，這裡可以省略，但為了保險起見建議保留
        if (!ServiceList::getServiceData('order_service')) {
            $host = getenv('ORDER_SERVICE_HOST') ?: 'localhost';
            $port = (int) (getenv('ORDER_SERVICE_PORT') ?: 8001);
            $isHttps = filter_var(getenv('ORDER_SERVICE_HTTPS') ?: 'false', FILTER_VALIDATE_BOOLEAN);

            ServiceList::addLocalService('order_service', $host, $port, $isHttps);
        }
    }

    /**
     * 定義 Saga 流程
     */
    protected function definition()
    {
        // Step 1: 呼叫 Order Service
        // 使用 setStep() 開始一個步驟
        $this->setStep()
             ->addAction('order_service', 'createOrder', [
                 'user_id' => $this->userId,
                 'items'   => $this->orderData
             ]);
             
        // 如果有補償機制 (Rollback)，可以在這裡定義
        // ->setCompensationMethod('order_service', 'deleteOrder', [...]);
    }

    /**
     * [新增] 讓 Worker 可以注入資料的方法
     * @param int $userId
     * @param array $orderData
     * @return CreateOrderOrchestrator
     */
    public function setOrderDetails($userId, array $orderData)
    {
        $this->userId = $userId;
        $this->orderData = $orderData;
        return $this;
    }
}
