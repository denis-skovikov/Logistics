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
 * Feature-тесты для POST /api/v1/slots/{id}/hold
 * Работают одинаково для обеих реализаций (denormalized / dynamic).
 */
class HoldCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * Проверяет успешное создание холда на слот с доступными местами.
     */
    public function test_create_hold_success(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 5]);
        $key = Str::uuid()->toString();

        $response = $this->postJson("/api/v1/slots/{$slot->id}/hold", [], [
            'Idempotency-Key' => $key,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('holds', [
            'slot_id' => $slot->id,
            'idempotency_key' => $key,
            'status' => HoldStatus::Held->value,
        ]);
    }

    /**
     * Проверяет что remaining уменьшается на 1 после создания холда (для обоих подходов проверяем через GET).
     */
    public function test_create_hold_decreases_remaining(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 5]);
        $key = Str::uuid()->toString();

        $this->postJson("/api/v1/slots/{$slot->id}/hold", [], [
            'Idempotency-Key' => $key,
        ]);

        $response = $this->getJson('/api/v1/slots');
        $slots = $response->json();
        $found = collect($slots)->firstWhere('slot_id', $slot->id);

        $this->assertEquals(4, $found['remaining']);
    }

    /**
     * Проверяет что при отсутствии Idempotency-Key возвращается 422.
     */
    public function test_create_hold_requires_idempotency_key(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 5]);

        $response = $this->postJson("/api/v1/slots/{$slot->id}/hold");

        $response->assertStatus(422);
    }

    /**
     * Проверяет что невалидный UUID в Idempotency-Key возвращает 422.
     */
    public function test_create_hold_validates_uuid_format(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 5]);

        $response = $this->postJson("/api/v1/slots/{$slot->id}/hold", [], [
            'Idempotency-Key' => 'not-a-valid-uuid',
        ]);

        $response->assertStatus(422);
    }

    /**
     * Проверяет что слишком короткий ключ возвращает 422.
     */
    public function test_create_hold_rejects_short_key(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 5]);

        $response = $this->postJson("/api/v1/slots/{$slot->id}/hold", [], [
            'Idempotency-Key' => '123',
        ]);

        $response->assertStatus(422);
    }

    /**
     * Проверяет идемпотентность — повторный запрос с тем же ключом возвращает тот же результат.
     */
    public function test_create_hold_idempotent(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 5]);
        $key = Str::uuid()->toString();

        $first = $this->postJson("/api/v1/slots/{$slot->id}/hold", [], [
            'Idempotency-Key' => $key,
        ]);
        $first->assertStatus(201);

        $second = $this->postJson("/api/v1/slots/{$slot->id}/hold", [], [
            'Idempotency-Key' => $key,
        ]);
        $second->assertStatus(201);
        $second->assertJson($first->json());

        // Убеждаемся что создана только одна запись
        $this->assertDatabaseCount('holds', 1);
    }

    /**
     * Проверяет что при исчерпании capacity возвращается 409 Conflict.
     */
    public function test_create_hold_conflict_when_no_remaining(): void
    {
        $slot = Slot::create(['capacity' => 1, 'remaining' => 1]);

        // Первый холд — успех
        $this->postJson("/api/v1/slots/{$slot->id}/hold", [], [
            'Idempotency-Key' => Str::uuid()->toString(),
        ])->assertStatus(201);

        // Второй холд — конфликт
        $response = $this->postJson("/api/v1/slots/{$slot->id}/hold", [], [
            'Idempotency-Key' => Str::uuid()->toString(),
        ]);

        $response->assertStatus(409);
    }

    /**
     * Проверяет что после создания холда кеш слотов инвалидируется.
     */
    public function test_create_hold_invalidates_slots_cache(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 5]);

        // Прогреваем кеш
        $this->getJson('/api/v1/slots')->assertHeader('X-Cache', 'MISS');
        $this->getJson('/api/v1/slots')->assertHeader('X-Cache', 'HIT');

        // Создаём холд
        $this->postJson("/api/v1/slots/{$slot->id}/hold", [], [
            'Idempotency-Key' => Str::uuid()->toString(),
        ])->assertStatus(201);

        // Кеш должен быть сброшен
        $this->getJson('/api/v1/slots')->assertHeader('X-Cache', 'MISS');
    }

    /**
     * Проверяет что холд на несуществующий слот возвращает 404.
     */
    public function test_create_hold_slot_not_found(): void
    {
        $response = $this->postJson('/api/v1/slots/9999/hold', [], [
            'Idempotency-Key' => Str::uuid()->toString(),
        ]);

        $response->assertStatus(404);
    }

    /**
     * Проверяет наличие заголовка X-Provider в ответе.
     */
    public function test_create_hold_returns_provider_header(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 5]);

        $response = $this->postJson("/api/v1/slots/{$slot->id}/hold", [], [
            'Idempotency-Key' => Str::uuid()->toString(),
        ]);

        $provider = $response->headers->get('X-Provider');
        $this->assertContains($provider, ['denormalized', 'dynamic']);
    }
}
