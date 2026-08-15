# Logistics Slot Booking API

Минимальный API бронирования слотов с горячим кешем и защитой от оверсела.

## Стек

- PHP 8.2 + Apache (php:8.2-apache)
- Laravel 12
- MySQL 8.0
- Redis 7

## Запуск

```bash
# Собрать и поднять контейнеры
docker-compose up -d --build

# Установить зависимости
docker-compose exec app composer install

# Скопировать .env и сгенерировать ключ
docker-compose exec app cp .env.example .env
docker-compose exec app php artisan key:generate

# Настроить .env (DB_HOST=mysql, DB_DATABASE=logistics, DB_USERNAME=logistics, DB_PASSWORD=secret, CACHE_STORE=redis, REDIS_HOST=redis)

# Права на storage
docker-compose exec app chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Миграции
docker-compose exec app php artisan migrate

# Сидер (100 слотов)
docker-compose exec app php artisan db:seed --class=SlotSeeder100

# Сброс кеша конфига
docker-compose exec app php artisan config:clear
```

## Переключение реализации

В `.env` файле:

```
SLOTS_PROVIDER=denormalized   # использует поле remaining в таблице slots
SLOTS_PROVIDER=dynamic        # считает remaining через LEFT JOIN (поле remaining игнорируется)
```

После смены — сброс кеша конфига:

```bash
docker-compose exec app php artisan config:clear
```

## Конфигурация (ENV)

| Параметр | По умолчанию | Описание |
|----------|-------------|----------|
| SLOTS_PROVIDER | denormalized | Реализация сервиса |
| SLOTS_CACHE_KEY | slots:all | Ключ кеша Redis |
| SLOTS_CACHE_TTL | 15 | Время жизни кеша (секунды) |
| SLOTS_LOCK_TIMEOUT | 5 | Таймаут лока от stampede (секунды) |
| SLOTS_HOLD_TTL | 5 | Время жизни холда (минуты) |

## URL

| Сервис | URL |
|--------|-----|
| Laravel | http://localhost:8080 |
| phpMyAdmin | http://localhost:8081 |

## API Endpoints

### GET /api/v1/slots — Получение доступных слотов

```
curl -s -D - http://localhost:8080/api/v1/slots
```

Только заголовки, без тела (удобно для теста на 100000 записей):

```
curl -s -o /dev/null -D - http://localhost:8080/api/v1/slots
```

С лимитом:

```
curl -s -D - "http://localhost:8080/api/v1/slots?limit=10"
```

Параметры: `?limit=N` (опционально, число)

Заголовки ответа:
- `X-Cache: HIT|MISS`
- `X-Query-Time: <ms>|Cache`
- `X-Provider: denormalized|dynamic`

### POST /api/v1/slots/{id}/hold — Создание холда

```
curl -s -D - -X POST http://localhost:8080/api/v1/slots/1/hold -H "Content-Type: application/json" -H "Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000"
```

Повторный запрос с тем же ключом (идемпотентность):

```
curl -s -D - -X POST http://localhost:8080/api/v1/slots/1/hold -H "Content-Type: application/json" -H "Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000"
```

Заголовок запроса: `Idempotency-Key: <UUID>`

Коды:
- 201 — холд создан (или идемпотентный повтор)
- 404 — слот не найден
- 409 — нет доступных мест
- 422 — невалидный или отсутствующий Idempotency-Key

### POST /api/v1/holds/{id}/confirm — Подтверждение холда

```
curl -s -D - -X POST http://localhost:8080/api/v1/holds/1/confirm
```

Коды:
- 200 — подтверждено
- 404 — холд не найден
- 409 — холд не в статусе held
- 410 — холд просрочен (автоматически отменён)

### DELETE /api/v1/holds/{id} — Отмена холда

```
curl -s -D - -X DELETE http://localhost:8080/api/v1/holds/1
```

Коды:
- 200 — отменено (или идемпотентный повтор для cancelled)
- 404 — холд не найден
- 409 — холд не в статусе held (confirmed нельзя отменить)

### Конфликт при оверселе

Слот с capacity=1, два разных ключа — второй получит 409:

```
curl -s -D - -X POST http://localhost:8080/api/v1/slots/1/hold -H "Content-Type: application/json" -H "Idempotency-Key: 660e8400-e29b-41d4-a716-446655440001"
```

```
curl -s -D - -X POST http://localhost:8080/api/v1/slots/1/hold -H "Content-Type: application/json" -H "Idempotency-Key: 770e8400-e29b-41d4-a716-446655440002"
```

## Тесты

### Feature-тесты (PHPUnit)

```bash
docker-compose exec app php artisan test --testsuite=Feature
```

Тесты работают на текущей реализации из ENV. Переключи `SLOTS_PROVIDER` и запусти снова для проверки другой реализации.

### Bash-тест: Cache Stampede (50 параллельных GET)

```bash
docker-compose exec app bash /var/www/html/tests/scripts/test_cache_stampede.sh
```

### Bash-тест: Oversell (10 параллельных холдов на последний слот)

```bash
docker-compose exec app bash /var/www/html/tests/scripts/test_oversell.sh
```

## Архитектура

```
app/
├── Enums/
│   ├── HoldStatus.php          # held, confirmed, cancelled
│   ├── CacheStatus.php         # HIT, MISS, Cache
│   └── ResponseHeader.php      # X-Cache, X-Query-Time, X-Provider
├── Exceptions/
│   ├── SlotNotFoundException.php
│   ├── HoldNotFoundException.php
│   ├── CapacityExhaustedException.php
│   ├── HoldConflictException.php
│   └── HoldExpiredException.php
├── Http/
│   ├── Controllers/
│   │   ├── SlotController.php
│   │   └── HoldController.php
│   └── Requests/
│       ├── SlotIndexRequest.php
│       └── HoldCreateRequest.php
├── Models/
│   ├── Slot.php
│   └── Hold.php
├── Providers/
│   └── AppServiceProvider.php  # DI binding
└── Services/
    ├── SlotServiceInterface.php
    ├── DenormalizedSlotService.php
    ├── DynamicSlotService.php
    └── SlotCacheService.php
```
