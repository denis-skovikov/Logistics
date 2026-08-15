#!/bin/bash
# Тест Cache Stampede: 50 параллельных GET /api/v1/slots
# Проверяет что при одновременных запросах только один идёт в базу (MISS), остальные ждут кеш (HIT).

BASE_URL="http://localhost/api/v1/slots"
TOTAL=50
RESULTS_FILE="/tmp/stampede_results.txt"

# Подготовка: сбрасываем кеш и создаём тестовые данные
php /var/www/html/artisan cache:clear > /dev/null 2>&1
php /var/www/html/artisan tinker --execute="
    \Illuminate\Support\Facades\DB::table('holds')->truncate();
    \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0;');
    \Illuminate\Support\Facades\DB::table('slots')->truncate();
    \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    for (\$i = 0; \$i < 20; \$i++) {
        \App\Models\Slot::create(['capacity' => 10, 'remaining' => 10]);
    }
    echo 'Seeded 20 slots';
" 2>/dev/null

echo "=== Cache Stampede Test: $TOTAL parallel requests ==="
echo ""

# Очищаем файл результатов
> "$RESULTS_FILE"

# Запускаем 50 параллельных запросов
for i in $(seq 1 $TOTAL); do
    (
        START_NS=$(date +%s%N)
        RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" -D /tmp/headers_${i}.txt "$BASE_URL" 2>/dev/null)
        END_NS=$(date +%s%N)

        HTTP_CODE="$RESPONSE"
        CACHE=$(grep -i "X-Cache:" /tmp/headers_${i}.txt 2>/dev/null | tr -d '\r' | awk '{print $2}')
        STATUS="OK"
        if [ "$HTTP_CODE" != "200" ]; then
            STATUS="ERROR"
        fi

        echo "${START_NS}|${i}|${HTTP_CODE}|${STATUS}|${CACHE}" >> "$RESULTS_FILE"
        rm -f /tmp/headers_${i}.txt
    ) &
done

# Ждём завершения всех фоновых процессов
wait

# Сортируем по времени старта и выводим
echo "Time (ns)           | Request | Status code | Status | Cache"
echo "--------------------+---------+-------------+--------+------"
sort -t'|' -k1 -n "$RESULTS_FILE" | while IFS='|' read -r TIME REQ CODE STATUS CACHE; do
    printf "%-20s | %-7s | %-11s | %-6s | %s\n" "$TIME" "$REQ" "$CODE" "$STATUS" "$CACHE"
done

echo ""
MISS_COUNT=$(grep -c "|MISS" "$RESULTS_FILE")
HIT_COUNT=$(grep -c "|HIT" "$RESULTS_FILE")
echo "Summary: MISS=$MISS_COUNT, HIT=$HIT_COUNT"
echo "Expected: 1 MISS (or very few), rest HIT"

rm -f "$RESULTS_FILE"
