<?php

namespace App\Events;

class OrderCreateRequestedEvent
{
    public string $traceId;
    public array $orderData;

    public function __construct(string $traceId, array $orderData)
    {
        $this->traceId = $traceId;
        $this->orderData = $orderData;
    }

    public function getTraceId(): string
    {
        return $this->traceId;
    }

    public function getOrderData(): array
    {
        return $this->orderData;
    }
}