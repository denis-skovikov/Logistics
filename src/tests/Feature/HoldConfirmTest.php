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
 * Feature-тесты для POST /api/v1/holds/{id}/confirm
 * Работают одинаково для обеих реализаций (denormalized / dynamic).
 */
class HoldConfirmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * Проверяет успешное подтверждение холда — статус меняется на confirmed.
     */
    public function test_confirm_hold_success(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 4]);
        $hold = Hold::create([
            'slot_id' => $slot->id,
            'idempotency_key' => Str::uuid()->toString(),
            'status' => HoldStatus::Held,
        ]);

        $response = $this->postJson("/api/v1/holds/{$hold->id}/confirm");

        $response->assertOk();
        $this->assertDatabaseHas('holds', [
            'id' => $hold->id,
            'status' => HoldStatus::Confirmed->value,
        ]);
    }

    /**
     * Проверяет что нельзя подтвердить просроченный холд (created_at > hold_ttl).
     */
    public function test_confirm_expired_hold_fails(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 4]);
        $hold = Hold::create([
            'slot_id' => $slot->id,
            'idempotency_key' => Str::uuid()->toString(),
            'status' => HoldStatus::Held,
        ]);

        // Искусственно устариваем холд
        $hold->update(['created_at' => now()->subMinutes(10)]);

        $response = $this->postJson("/api/v1/holds/{$hold->id}/confirm");

        $response->assertStatus(410);
    }

    /**
     * Проверяет ленивую очистку: просроченный холд отменяется и место возвращается.
     */
    public function test_confirm_expired_hold_cancels_and_returns_slot(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 4]);
        $hold = Hold::create([
            'slot_id' => $slot->id,
            'idempotency_key' => Str::uuid()->toString(),
            'status' => HoldStatus::Held,
        ]);

        $hold->update(['created_at' => now()->subMinutes(10)]);

        $this->postJson("/api/v1/holds/{$hold->id}/confirm");

        // Холд должен быть cancelled
        $this->assertDatabaseHas('holds', [
            'id' => $hold->id,
            'status' => HoldStatus::Cancelled->value,
        ]);

        // Для denormalized: remaining должен вернуться
        // Для dynamic: remaining считается автоматически
        $response = $this->getJson('/api/v1/slots');
        $slots = $response->json();
        $found = collect($slots)->firstWhere('slot_id', $slot->id);
        $this->assertEquals(5, $found['remaining']);
    }

    /**
     * Проверяет что при ленивой очистке просроченного холда кеш инвалидируется.
     */
    public function test_confirm_expired_hold_invalidates_cache(): void
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

        // Устариваем и пытаемся подтвердить
        $hold->update(['created_at' => now()->subMinutes(10)]);
        $this->postJson("/api/v1/holds/{$hold->id}/confirm");

        // Кеш должен быть сброшен
        $this->getJson('/api/v1/slots')->assertHeader('X-Cache', 'MISS');
    }

    /**
     * Проверяет что нельзя подтвердить уже подтверждённый холд.
     */
    public function test_confirm_already_confirmed_hold_fails(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 4]);
        $hold = Hold::create([
            'slot_id' => $slot->id,
            'idempotency_key' => Str::uuid()->toString(),
            'status' => HoldStatus::Confirmed,
        ]);

        $response = $this->postJson("/api/v1/holds/{$hold->id}/confirm");

        $response->assertStatus(409);
    }

    /**
     * Проверяет что нельзя подтвердить отменённый холд.
     */
    public function test_confirm_cancelled_hold_fails(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 4]);
        $hold = Hold::create([
            'slot_id' => $slot->id,
            'idempotency_key' => Str::uuid()->toString(),
            'status' => HoldStatus::Cancelled,
        ]);

        $response = $this->postJson("/api/v1/holds/{$hold->id}/confirm");

        $response->assertStatus(409);
    }

    /**
     * Проверяет что подтверждение несуществующего холда возвращает 404.
     */
    public function test_confirm_nonexistent_hold_returns_404(): void
    {
        $response = $this->postJson('/api/v1/holds/9999/confirm');

        $response->assertStatus(404);
    }

    /**
     * Проверяет наличие заголовка X-Provider в ответе.
     */
    public function test_confirm_hold_returns_provider_header(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 4]);
        $hold = Hold::create([
            'slot_id' => $slot->id,
            'idempotency_key' => Str::uuid()->toString(),
            'status' => HoldStatus::Held,
        ]);

        $response = $this->postJson("/api/v1/holds/{$hold->id}/confirm");

        $provider = $response->headers->get('X-Provider');
        $this->assertContains($provider, ['denormalized', 'dynamic']);
    }
}
