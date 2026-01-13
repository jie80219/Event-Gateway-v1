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
     * Consul 通訊協定
     */
    public $consulScheme = 'http';

    /**
     * Consul API Port
     */
    public $consulPort = 8500;

    /**
     * Consul Datacenter
     */
    public $consulDataCenter = '';

    /**
     * 服務發現快取時間 (秒)
     * 避免每次請求都打 Consul API
     */
    public $discoveryCacheTime = 60;

    /**
     * 服務發現重新載入時間 (秒)
     */
    public $reloadTime = 10;

    /**
     * 預設服務群組 (以逗號分隔)
     */
    public $defaultServiceGroup = '';

    /**
     * 負載平衡策略 (random|rr|least|ip|dynamic)
     */
    public $lbStrategy = 'random';

    public $gatewayServiceName = 'event-gateway-v1';
    public $gatewayServiceId = 'event-gateway-v1';
    public $gatewayAddress = 'localhost';
    public $gatewayPort = 8080;
    public $gatewayHealthPath = '/v1/heartbeat';
    public $gatewayHealthInterval = '10s';
    public $gatewayHealthTimeout = '2s';
    public $gatewayTags = ['gateway'];
}
