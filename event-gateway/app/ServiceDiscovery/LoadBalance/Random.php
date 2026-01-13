<?php 
namespace App\ServiceDiscovery\LoadBalance;

use App\ServiceDiscovery\LoadBalance\LoadBalanceInterface;

class Random implements LoadBalanceInterface
{
    /**
     * 隨機選出一個服務
     *
     * @param array $services
     * @return array
     */
    public function do(array $services, array $context = []): array
    {
        $serviceCount = count($services);
        if ($serviceCount === 0) {
            throw new \RuntimeException("服務列表為空");
        }
        $rand = rand(0, $serviceCount - 1);

        return $services[$rand];
    }
}
