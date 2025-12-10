<?php

namespace Config;

/**
 * CodeIgniter 路徑設定。
 * 這個檔案會在 autoloader 啟動前被直接 require，
 * 所以不要在這裡繼承或引用其他類別。
 */
class Paths
{
    /**
     * 系統目錄 (CI framework)
     */
    public string $systemDirectory = __DIR__ . '/../../vendor/codeigniter4/framework/system';

    /**
     * 專案 app 目錄
     */
    public string $appDirectory = __DIR__ . '/..';

    /**
     * 可寫目錄
     */
    public string $writableDirectory = __DIR__ . '/../../writable';

    /**
     * 測試目錄
     */
    public string $testsDirectory = __DIR__ . '/../../tests';

    /**
     * 視圖目錄
     */
    public string $viewDirectory = __DIR__ . '/../Views';
}
