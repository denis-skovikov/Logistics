<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SlotHoldSeeder100000 extends Seeder
{
    public function run(): void
    {
        ini_set('memory_limit', '512M');

        if (!$this->command->confirm('Обе таблицы (holds, slots) будут полностью очищены. Продолжить?')) {
            $this->command->info('Отменено.');
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('holds')->truncate();
        DB::table('slots')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $totalSlots = 100000;
        $totalHolds = 1000000;
        $holdsPerSlot = (int) ceil($totalHolds / $totalSlots); // ~10 в среднем
        $slotBatchSize = 5000;
        $holdBatchSize = 5000;

        $now = now()->format('Y-m-d H:i:s');

        $this->command->info('Начинаю создание слотов и холдов...');

        $holdsCreated = 0;

        for ($i = 0; $i < $totalSlots; $i += $slotBatchSize) {
            $slotsBatch = [];
            $batchCount = min($slotBatchSize, $totalSlots - $i);
            $batchHoldsData = []; // slot index => [holds array]

            for ($j = 0; $j < $batchCount; $j++) {
                $capacity = rand(5, 30);
                $slotId = $i + $j + 1;

                // Кол-во холдов для этого слота
                $count = rand(max(1, $holdsPerSlot - 5), $holdsPerSlot + 5);
                if ($holdsCreated + $count > $totalHolds) {
                    $count = $totalHolds - $holdsCreated;
                }

                $activeCount = 0;
                $slotHolds = [];

                for ($h = 0; $h < $count; $h++) {
                    $roll = rand(1, 100);
                    if ($roll <= 60) {
                        $status = 'held';
                    } elseif ($roll <= 90) {
                        $status = 'confirmed';
                    } else {
                        $status = 'cancelled';
                    }

                    if ($status !== 'cancelled' && $activeCount >= $capacity) {
                        $status = 'cancelled';
                    }

                    if ($status !== 'cancelled') {
                        $activeCount++;
                    }

                    $slotHolds[] = [
                        'slot_id' => $slotId,
                        'idempotency_key' => Str::uuid()->toString(),
                        'status' => $status,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                $holdsCreated += $count;
                $batchHoldsData[$j] = $slotHolds;

                $remaining = $capacity - $activeCount;

                $slotsBatch[] = [
                    'capacity' => $capacity,
                    'remaining' => $remaining,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Сначала вставляем слоты
            DB::table('slots')->insert($slotsBatch);
            $this->command->info('Создано ' . ($i + $batchCount) . " записей в табличке slots");

            // Затем вставляем холды порциями
            $holdsBatch = [];
            foreach ($batchHoldsData as $slotHolds) {
                foreach ($slotHolds as $hold) {
                    $holdsBatch[] = $hold;

                    if (count($holdsBatch) >= $holdBatchSize) {
                        DB::table('holds')->insert($holdsBatch);
                        $holdsBatch = [];
                    }
                }
            }

            if (!empty($holdsBatch)) {
                DB::table('holds')->insert($holdsBatch);
                $holdsBatch = [];
            }

            $this->command->info("  Создано холдов: {$holdsCreated}");

            // Освобождаем память
            unset($batchHoldsData);
        }

        $this->command->info("Итого: {$totalSlots} слотов, {$holdsCreated} холдов");
        $this->command->info('Готово!');
    }
}
