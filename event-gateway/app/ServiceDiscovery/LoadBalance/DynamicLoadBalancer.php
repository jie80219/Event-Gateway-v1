<?php
namespace App\ServiceDiscovery\LoadBalance;

use App\ServiceDiscovery\LoadBalance\LoadBalanceInterface;
use App\ServiceDiscovery\LoadBalance\CachedScoreManager;

class DynamicLoadBalancer implements LoadBalanceInterface
{
    private array $addressToHost = [];

    public function __construct()
    {
        $this->addressToHost = $this->loadAddressMap();
    }

    /**
     * 根據服務的 IP 地址選擇伺服器
     *
     * @param array $services [['address' => '10.1.1.x', ...], ...]
     * @return array
     */
    public function do(array $services, array $context = []): array
    {
        if (empty($services)) {
            throw new \RuntimeException("服務列表為空");
        }
        $scores = [];
        $scoreManager = CachedScoreManager::getInstance();

        foreach ($services as $service) {
            $ip = $service['address'] ?? null;
            if ($ip === null) {
                continue;
            }
            $host = $service['host'] ?? $this->addressToHost[$ip] ?? $ip;

            // 根據 host 從 score manager 取得分數
            $score = $scoreManager->getScore($host);
            if ($score !== null) {
                $scores[] = [
                    'service' => $service,  // 存儲該服務
                    'score' => $score,      // 對應的分數
                ];
            }
        }

        // 如果沒有可用的服務，隨機返回一個
        if (empty($scores)) {
            error_log("[DynamicLoadBalancer] No usable scores, fallback to random");
            return $services[array_rand($services)];
        }

        // 根據分數加權隨機選擇服務
        $total = array_sum(array_column($scores, 'score'));
        $rand = mt_rand() / mt_getrandmax();
        $acc = 0;

        // 根據分數加權選擇
        foreach ($scores as $entry) {
            $acc += $entry['score'] / $total;
            if ($rand <= $acc) {
                return $entry['service'];  // 返回選中的服務
            }
        }

        // 默認情況下隨機返回服務
        return $services[array_rand($services)];
    }

    private function loadAddressMap(): array
    {
        $raw = getenv('DYNAMIC_LB_HOST_MAP');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            error_log("[DynamicLoadBalancer] Invalid DYNAMIC_LB_HOST_MAP JSON");
            return [];
        }
        return $decoded;
    }
}
