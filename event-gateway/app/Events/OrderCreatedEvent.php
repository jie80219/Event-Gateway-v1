<?php

namespace App\Events;


class OrderCreatedEvent
{
    public string $orderId;
    public array $productData;
    public float $totalAmount;
    public string $createdAt;

    public function __construct(string $orderId, array $productData, float $totalAmount)
    {
        $this->orderId = $orderId;
        $this->productData = $productData;
        $this->totalAmount = $totalAmount;
        $this->createdAt = date('Y-m-d H:i:s');
    }
    
    // Anser-EDA 可能需要這個方法來決定 Routing Key
    public function getName(): string
    {
        return 'OrderCreated'; // 必須對應 Saga 中 #[EventHandler(event: 'OrderCreated')]
    }
}