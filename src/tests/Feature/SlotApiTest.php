<?php

namespace Tests\Feature;

use App\Enums\HoldStatus;
use App\Models\Hold;
use App\Models\Slot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-тесты для GET /api/v1/slots
 * Работают одинаково для обеих реализаций (denormalized / dynamic).
 */
class SlotApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * Проверяет что эндпоинт возвращает список слотов с полями slot_id, capacity, remaining.
     */
    public function test_get_slots_returns_list(): void
    {
        Slot::create(['capacity' => 10, 'remaining' => 10]);
        Slot::create(['capacity' => 5, 'remaining' => 3]);

        $response = $this->getJson('/api/v1/slots');

        $response->assertOk();
        $response->assertJsonCount(2);
        $response->assertJsonStructure([
            ['slot_id', 'capacity', 'remaining'],
        ]);
    }

    /**
     * Проверяет что слоты с remaining=0 тоже возвращаются клиенту.
     */
    public function test_get_slots_includes_zero_remaining(): void
    {
        $slot = Slot::create(['capacity' => 1, 'remaining' => 0]);

        // Создаём активный холд чтобы remaining=0 работало и для Dynamic
        Hold::create([
            'slot_id' => $slot->id,
            'idempotency_key' => \Illuminate\Support\Str::uuid()->toString(),
            'status' => \App\Enums\HoldStatus::Held,
        ]);

        $response = $this->getJson('/api/v1/slots');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['remaining' => 0]);
    }

    /**
     * Проверяет что параметр limit ограничивает количество записей в ответе.
     */
    public function test_get_slots_respects_limit_parameter(): void
    {
        for ($i = 0; $i < 10; $i++) {
            Slot::create(['capacity' => 5, 'remaining' => 5]);
        }

        $response = $this->getJson('/api/v1/slots?limit=3');

        $response->assertOk();
        $response->assertJsonCount(3);
    }

    /**
     * Проверяет что невалидный limit (не число) возвращает ошибку валидации.
     */
    public function test_get_slots_validates_limit_is_numeric(): void
    {
        $response = $this->getJson('/api/v1/slots?limit=abc');

        $response->assertStatus(422);
    }

    /**
     * Проверяет что при повторном запросе данные берутся из кеша (X-Cache: HIT).
     */
    public function test_get_slots_returns_cache_hit_on_second_request(): void
    {
        Slot::create(['capacity' => 10, 'remaining' => 10]);

        $first = $this->getJson('/api/v1/slots');
        $first->assertHeader('X-Cache', 'MISS');

        $second = $this->getJson('/api/v1/slots');
        $second->assertHeader('X-Cache', 'HIT');
    }

    /**
     * Проверяет наличие заголовка X-Query-Time с значением в мс при MISS и "Cache" при HIT.
     */
    public function test_get_slots_returns_query_time_header(): void
    {
        Slot::create(['capacity' => 10, 'remaining' => 10]);

        $first = $this->getJson('/api/v1/slots');
        $first->assertOk();
        $queryTime = $first->headers->get('X-Query-Time');
        $this->assertNotNull($queryTime);
        $this->assertNotEquals('Cache', $queryTime);
        $this->assertTrue(is_numeric($queryTime));

        $second = $this->getJson('/api/v1/slots');
        $second->assertHeader('X-Query-Time', 'Cache');
    }

    /**
     * Проверяет наличие заголовка X-Provider с корректным значением.
     */
    public function test_get_slots_returns_provider_header(): void
    {
        Slot::create(['capacity' => 10, 'remaining' => 10]);

        $response = $this->getJson('/api/v1/slots');

        $provider = $response->headers->get('X-Provider');
        $this->assertContains($provider, ['denormalized', 'dynamic']);
    }

    /**
     * Проверяет что без параметра limit возвращаются все записи.
     */
    public function test_get_slots_returns_all_without_limit(): void
    {
        for ($i = 0; $i < 15; $i++) {
            Slot::create(['capacity' => 5, 'remaining' => 5]);
        }

        $response = $this->getJson('/api/v1/slots');

        $response->assertOk();
        $response->assertJsonCount(15);
    }
}
