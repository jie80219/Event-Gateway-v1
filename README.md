# Event Gateway v1

## Overview
Event Gateway combines Anser-Gateway and Anser-EDA to provide HTTP ingress, message routing, event handling, and service discovery.

## Services (Docker)
The following services are started by `docker-compose.yml`:
- Event Gateway (app): HTTP ingress, port `8080` → container `80`.
- Worker (event-gateway-worker): consumes RabbitMQ queues.
- RabbitMQ: message broker (`5672`, management `15672`).
- Redis: cache/metrics (`6379`).
- Consul: service discovery (`8500`, DNS `8600/udp`).
- EventStoreDB: event store (`2113`).

## Quick Start (Docker)
```bash
# from repo root
docker compose up -d --build
```

Verify:
```bash
curl http://localhost:8080/v1/heartbeat
```

## Core APIs
- `GET /` or `GET /v1/heartbeat`: health check
- `POST /v1/order`: enqueue order event to RabbitMQ

Example:
```bash
curl -X POST http://localhost:8080/v1/order \
  -H 'Content-Type: application/json' \
  -d '{"product_list":[{"id":1,"qty":2}]}'
```

## Workers
You can run these locally or inside the container:
- `php event-gateway/app/Workers/RequestConsumer.php`
- `php event-gateway/app/Workers/EventConsumer.php`
- `php event-gateway/worker/recalc_worker.php`
- `php event-gateway/spark queue:listen`

## RabbitMQ Bootstrap
Create exchange/queues via CLI:
```bash
php event-gateway/spark rabbitmq:init
```

Note: `rabbitmq:init` uses `request.new` as the entry routing key. If you use `/v1/order` (default `REQUEST_ROUTING_KEY=order.create`), align the routing key via env or config.

## Environment Variables (Highlights)
`.env` is located at repo root:
- `CI_ENVIRONMENT`, `app.baseURL`
- `RABBITMQ_HOST`, `RABBITMQ_PORT`, `RABBITMQ_USER`, `RABBITMQ_PASS`
- `REDIS_HOST`, `REDIS_PORT`, `REDIS_DB`, `REDIS_TIMEOUT`
- `CONSUL_HOST`, `CONSUL_PORT`, `CONSUL_SCHEME`
- `EVENTSTORE_HOST`, `EVENTSTORE_HTTP_PORT`, `EVENTSTORE_USER`, `EVENTSTORE_PASS`
- `REQUEST_EXCHANGE`, `REQUEST_QUEUE`, `REQUEST_ROUTING_KEY`
- `SERVICEDISCOVERY_ENABLED`, `servicediscovery.lbStrategy`
- `SERVICEDISCOVERY_RECALC_INTERVAL` (recalc worker)

## Repo Layout (Short)
- `event-gateway/app`: CodeIgniter app
- `event-gateway/public`: web root
- `event-gateway/app/Workers`: RabbitMQ consumers
- `event-gateway/worker`: entropy scoring worker
- `Services/`: service definitions and domain logic
