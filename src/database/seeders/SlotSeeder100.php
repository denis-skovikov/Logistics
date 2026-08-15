<?php

namespace Database\Seeders;

use App\Models\Slot;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SlotSeeder100 extends Seeder
{
    public function run(): void
    {
        if (!$this->command->confirm('Обе таблицы (holds, slots) будут полностью очищены. Продолжить?')) {
            $this->command->info('Отменено.');
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('holds')->truncate();
        DB::table('slots')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $slots = [];
        $now = now();

        for ($i = 0; $i < 100; $i++) {
            $capacity = rand(1, 20);
            $slots[] = [
                'capacity' => $capacity,
                'remaining' => $capacity,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Slot::insert($slots);

        $this->command->info('Создано 100 слотов.');
    }
}
