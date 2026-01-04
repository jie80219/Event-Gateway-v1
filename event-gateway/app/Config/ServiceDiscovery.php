<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class ServiceDiscovery extends BaseConfig
{
    /**
     * Consul 服務地址
     * 對應 docker-compose 中的 service name 'consul'
     */
    public $consulHost = 'anser_consul';

    /**
     * Consul API Port
     */
    public $consulPort = 8500;

    /**
     * 服務發現快取時間 (秒)
     * 避免每次請求都打 Consul API
     */
    public $discoveryCacheTime = 60;

    public $gatewayServiceName = 'event-gateway-v1';
    public $gatewayServiceId = 'event-gateway-v1';
    public $gatewayAddress = 'localhost';
    public $gatewayPort = 8080;
    public $gatewayHealthPath = '/v1/heartbeat';
    public $gatewayHealthInterval = '10s';
    public $gatewayHealthTimeout = '2s';
    public $gatewayTags = ['gateway'];
}
