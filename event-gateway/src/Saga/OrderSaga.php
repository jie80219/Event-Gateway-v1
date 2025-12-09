<?php

namespace Anser\ApiGateway\Saga;

use SDPMlab\Anser\Orchestration\Saga\SimpleSaga;
use Anser\ApiGateway\Services\OrderService;
use Anser\ApiGateway\Services\PaymentService;
use Anser\ApiGateway\Services\InventoryService;

class OrderSaga extends SimpleSaga
{
    protected $orderService;
    protected $paymentService;
    protected $inventoryService;

    public function __construct(
        OrderService $orderService,
        PaymentService $paymentService,
        InventoryService $inventoryService
    ) {
        $this->orderService = $orderService;
        $this->paymentService = $paymentService;
        $this->inventoryService = $inventoryService;
    }

    public function buildSaga()
    {
        // Step 1: Create Order (Local or Remote Service)
        $this->setStep('createOrder')
             ->addAction('order_service', $this->orderService->createOrderAction())
             ->setCompensation('order_service', $this->orderService->markFailedAction());

        // Step 2: Reserve Inventory
        $this->setStep('reserveInventory')
             ->addAction('inventory_service', $this->inventoryService->deductAction())
             ->setCompensation('inventory_service', $this->inventoryService->restoreAction());

        // Step 3: Charge Payment
        $this->setStep('chargePayment')
             ->addAction('payment_service', $this->paymentService->chargeAction())
             ->setCompensation('payment_service', $this->paymentService->refundAction());
             
        // Finalize
        $this->setStep('confirmOrder')
             ->addAction('order_service', $this->orderService->confirmAction());
    }

    public function start(array $orderData, string $correlationId)
    {
        // Inject data into services context
        $this->orderService->setData($orderData);
        
        // Use Anser's orchestrator to run
        $this->startOrchestrator(); 
    }
}