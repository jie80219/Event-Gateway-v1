# Event Gateway v1

## 專案簡介
Event Gateway 結合 Anser-Gateway 與 Anser-EDA，提供 API 入口、訊息佇列轉發、事件處理與服務發現。

## 服務與組件（Docker）
以下服務會由 `docker-compose.yml` 啟動：
- Event Gateway（app）：HTTP 入口，對外 API。對外埠 `8080` → 容器 `80`。
- Worker（event-gateway-worker）：消費 RabbitMQ 佇列，處理事件流程。
- RabbitMQ：訊息佇列（`5672`、管理介面 `15672`）。
- Redis：快取/統計（`6379`）。
- Consul：服務發現（`8500`、DNS `8600/udp`）。
- EventStoreDB：事件儲存（`2113`）。

## 快速開始（Docker）
```bash
# 在專案根目錄
docker compose up -d --build
```

驗證：
```bash
curl http://localhost:8080/v1/heartbeat
```

## 主要 API
- `GET /` 或 `GET /v1/heartbeat`：健康檢查
- `POST /v1/order`：提交訂單事件到 RabbitMQ

範例：
```bash
curl -X POST http://localhost:8080/v1/order \
  -H 'Content-Type: application/json' \
  -d '{"product_list":[{"id":1,"qty":2}]}'
```

## Worker / 背景程序
可在本機或容器內手動啟動：
- `php event-gateway/app/Workers/RequestConsumer.php`：消費入口佇列，將事件送入 EventBus
- `php event-gateway/app/Workers/EventConsumer.php`：消費事件佇列並觸發對應 Handler
- `php event-gateway/worker/recalc_worker.php`：重算服務分數（EntropyScoring）
- `php event-gateway/spark queue:listen`：Saga Worker（CLI 指令）

## RabbitMQ 初始化
提供 CLI 指令建立交換器與佇列：
```bash
php event-gateway/spark rabbitmq:init
```

注意：`rabbitmq:init` 的入口 routing key 預設為 `request.new`，
若你使用 `/v1/order`（預設 `REQUEST_ROUTING_KEY=order.create`），請調整環境變數或指令設定。

## 環境變數（重點）
`.env` 位於專案根目錄，可依需求調整：
- `CI_ENVIRONMENT`、`app.baseURL`
- `RABBITMQ_HOST`、`RABBITMQ_PORT`、`RABBITMQ_USER`、`RABBITMQ_PASS`
- `REDIS_HOST`、`REDIS_PORT`、`REDIS_DB`、`REDIS_TIMEOUT`
- `CONSUL_HOST`、`CONSUL_PORT`、`CONSUL_SCHEME`
- `EVENTSTORE_HOST`、`EVENTSTORE_HTTP_PORT`、`EVENTSTORE_USER`、`EVENTSTORE_PASS`
- `REQUEST_EXCHANGE`、`REQUEST_QUEUE`、`REQUEST_ROUTING_KEY`
- `SERVICEDISCOVERY_ENABLED`、`servicediscovery.lbStrategy`
- `SERVICEDISCOVERY_RECALC_INTERVAL`（recalc worker）

## 專案結構（簡述）
- `event-gateway/app`：CodeIgniter 應用程式
- `event-gateway/public`：Web 根目錄
- `event-gateway/app/Workers`：RabbitMQ 消費者
- `event-gateway/worker`：重算服務分數的背景程式
- `Services/`：服務定義與業務邏輯物件
