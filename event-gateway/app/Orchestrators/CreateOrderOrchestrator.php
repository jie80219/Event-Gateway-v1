<?php
// app/Orchestrators/CreateOrderOrchestrator.php

namespace App\Orchestrators;

use SDPMlab\Anser\Orchestration\Orchestrator;
use SDPMlab\Anser\Service\ServiceList; // 引入 ServiceList
use Services\OrderService;
use Services\Models\OrderProductDetail;

class CreateOrderOrchestrator extends Orchestrator
{
    /**
     * 暫存 Handler 傳過來的資料
     */
    protected $userId;
    protected $orderData;
    protected $orderId;
    protected $orderService;

    /**
     * 初始化協調器 (建構子)
     */
    public function __construct()
    {
        // 確保在協調器啟動時，有註冊微服務的位置
        // 如果你在全域設定檔(init.php)做過了，這裡可以省略，但為了保險起見建議保留
        if (!ServiceList::getServiceData('OrderService')) {
            $host = getenv('ORDER_SERVICE_HOST') ?: 'localhost';
            $port = (int) (getenv('ORDER_SERVICE_PORT') ?: 8001);
            $isHttps = filter_var(getenv('ORDER_SERVICE_HTTPS') ?: 'false', FILTER_VALIDATE_BOOLEAN);

            ServiceList::addLocalService('OrderService', $host, $port, $isHttps);
        }

        $this->orderService = new OrderService();
    }

    /**
     * 定義 Saga 流程
     */
    protected function definition()
    {
        $productDetails = $this->buildProductDetails($this->orderData);
        $orderId = $this->orderId ?: $this->generateOrderId();

        // Step 1: 呼叫 Order Service
        // 使用 setStep() 開始一個步驟
        $this->setStep()
             ->addAction('create_order', $this->orderService->createOrderAction(
                 (int) $this->userId,
                 $orderId,
                 $productDetails
             ));
             
        // 如果有補償機制 (Rollback)，可以在這裡定義
        // ->setCompensationMethod('order_service', 'deleteOrder', [...]);
    }

    /**
     * [新增] 讓 Worker 可以注入資料的方法
     * @param int $userId
     * @param array $orderData
     * @return CreateOrderOrchestrator
     */
    public function setOrderDetails($userId, array $orderData, ?string $orderId = null)
    {
        $this->userId = $userId;
        $this->orderData = $orderData;
        if ($orderId === null || $orderId === '') {
            $orderId = $orderData['order_id'] ?? $orderData['orderId'] ?? null;
        }
        $this->orderId = $orderId ?: null;
        return $this;
    }

    private function buildProductDetails(array $rawList): array
    {
        $list = $rawList['product_list']
            ?? $rawList['productList']
            ?? $rawList['products']
            ?? $rawList;

        if (!is_array($list)) {
            return [];
        }

        $details = [];
        foreach ($list as $item) {
            if ($item instanceof OrderProductDetail) {
                $details[] = $item;
                continue;
            }
            if (!is_array($item) || !isset($item['p_key'], $item['amount'])) {
                continue;
            }
            $details[] = new OrderProductDetail(
                (int) $item['p_key'],
                (int) ($item['price'] ?? 0),
                (int) $item['amount']
            );
        }

        return $details;
    }

    private function generateOrderId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}
