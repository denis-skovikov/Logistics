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
 * Feature-тесты для DELETE /api/v1/holds/{id}
 * Работают одинаково для обеих реализаций (denormalized / dynamic).
 */
class HoldCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * Проверяет успешную отмену холда — статус меняется на cancelled.
     */
    public function test_cancel_hold_success(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 4]);
        $hold = Hold::create([
            'slot_id' => $slot->id,
            'idempotency_key' => Str::uuid()->toString(),
            'status' => HoldStatus::Held,
        ]);

        $response = $this->deleteJson("/api/v1/holds/{$hold->id}");

        $response->assertOk();
        $this->assertDatabaseHas('holds', [
            'id' => $hold->id,
            'status' => HoldStatus::Cancelled->value,
        ]);
    }

    /**
     * Проверяет что после отмены холда место возвращается в слот.
     */
    public function test_cancel_hold_returns_remaining(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 4]);
        $hold = Hold::create([
            'slot_id' => $slot->id,
            'idempotency_key' => Str::uuid()->toString(),
            'status' => HoldStatus::Held,
        ]);

        $this->deleteJson("/api/v1/holds/{$hold->id}");

        $response = $this->getJson('/api/v1/slots');
        $slots = $response->json();
        $found = collect($slots)->firstWhere('slot_id', $slot->id);
        $this->assertEquals(5, $found['remaining']);
    }

    /**
     * Проверяет что нельзя отменить уже подтверждённый холд.
     */
    public function test_cancel_confirmed_hold_fails(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 4]);
        $hold = Hold::create([
            'slot_id' => $slot->id,
            'idempotency_key' => Str::uuid()->toString(),
            'status' => HoldStatus::Confirmed,
        ]);

        $response = $this->deleteJson("/api/v1/holds/{$hold->id}");

        $response->assertStatus(409);
    }

    /**
     * Проверяет что повторная отмена уже отменённого холда возвращает 200 (идемпотентность).
     */
    public function test_cancel_already_cancelled_hold_is_idempotent(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 4]);
        $hold = Hold::create([
            'slot_id' => $slot->id,
            'idempotency_key' => Str::uuid()->toString(),
            'status' => HoldStatus::Cancelled,
        ]);

        $response = $this->deleteJson("/api/v1/holds/{$hold->id}");

        $response->assertOk();
        $response->assertJsonFragment(['status' => 'cancelled']);
    }

    /**
     * Проверяет что отмена несуществующего холда возвращает 404.
     */
    public function test_cancel_nonexistent_hold_returns_404(): void
    {
        $response = $this->deleteJson('/api/v1/holds/9999');

        $response->assertStatus(404);
    }

    /**
     * Проверяет что после отмены холда кеш слотов инвалидируется.
     */
    public function test_cancel_hold_invalidates_cache(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 4]);
        $hold = Hold::create([
            'slot_id' => $slot->id,
            'idempotency_key' => Str::uuid()->toString(),
            'status' => HoldStatus::Held,
        ]);

        // Прогреваем кеш
        $this->getJson('/api/v1/slots')->assertHeader('X-Cache', 'MISS');
        $this->getJson('/api/v1/slots')->assertHeader('X-Cache', 'HIT');

        // Отменяем холд
        $this->deleteJson("/api/v1/holds/{$hold->id}");

        // Кеш должен быть сброшен
        $this->getJson('/api/v1/slots')->assertHeader('X-Cache', 'MISS');
    }

    /**
     * Проверяет наличие заголовка X-Provider в ответе.
     */
    public function test_cancel_hold_returns_provider_header(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 4]);
        $hold = Hold::create([
            'slot_id' => $slot->id,
            'idempotency_key' => Str::uuid()->toString(),
            'status' => HoldStatus::Held,
        ]);

        $response = $this->deleteJson("/api/v1/holds/{$hold->id}");

        $provider = $response->headers->get('X-Provider');
        $this->assertContains($provider, ['denormalized', 'dynamic']);
    }
}
