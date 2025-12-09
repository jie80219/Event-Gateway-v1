<?php

namespace App\Orchestrators;

use SDPMlab\Anser\Orchestration\Orchestrator;
use App\Services\OrderService;
use App\Services\PaymentService;

class CreateOrderOrchestrator extends Orchestrator
{
    protected $orderService;
    protected $paymentService;

    protected function configure(array $data)
    {
        $this->orderService = new OrderService();
        $this->paymentService = new PaymentService();

        // 定義 Saga 步驟 (Step)
        // Step 1: 建立訂單
        $this->setStep()
            ->addAction('create_order', $this->orderService->create($data))
            ->setCompensationMethod('create_order', $this->orderService->cancel());

        // Step 2: 扣款
        $this->setStep()
            ->addAction('charge_payment', $this->paymentService->charge($data))
            ->setCompensationMethod('charge_payment', $this->paymentService->refund());
            
        // ... 更多步驟
    }
    
    public function build(array $data, string $requestId)
    {
        $this->setOrchestratorNumber($requestId);
        $this->configure($data);
        return $this;
    }
    
    public function run()
    {
        // 這裡會觸發 Anser 的執行邏輯
        // 在混合架構中，addAction 可能發送 HTTP 請求或發送 Event (取決於 Service 實作)
        return parent::start();
    }
}