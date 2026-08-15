#!/bin/bash
# Тест конкурентного оверселла: 10 параллельных запросов на последний оставшийся слот.
# Ожидаемый результат: ровно 1 запрос получает 201, остальные 9 — 409 Conflict.

BASE_URL="http://localhost/api/v1/slots"
TOTAL=10
RESULTS_FILE="/tmp/oversell_results.txt"

# Подготовка: создаём слот с capacity=1, remaining=1
php /var/www/html/artisan cache:clear > /dev/null 2>&1
SLOT_ID=$(php /var/www/html/artisan tinker --execute="
    \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0;');
    \Illuminate\Support\Facades\DB::table('holds')->truncate();
    \Illuminate\Support\Facades\DB::table('slots')->truncate();
    \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    \$slot = \App\Models\Slot::create(['capacity' => 1, 'remaining' => 1]);
    echo \$slot->id;
" 2>/dev/null | tail -1)

echo "=== Oversell Test: $TOTAL parallel holds on slot #$SLOT_ID (capacity=1) ==="
echo ""

# Очищаем файл результатов
> "$RESULTS_FILE"

# Запускаем 10 параллельных запросов на создание холда
for i in $(seq 1 $TOTAL); do
    (
        UUID=$(cat /proc/sys/kernel/random/uuid)
        START_NS=$(date +%s%N)
        HTTP_CODE=$(curl -s -o /tmp/oversell_body_${i}.txt -w "%{http_code}" \
            -X POST \
            -H "Content-Type: application/json" \
            -H "Idempotency-Key: ${UUID}" \
            "${BASE_URL}/${SLOT_ID}/hold" 2>/dev/null)
        END_NS=$(date +%s%N)

        STATUS="OK"
        if [ "$HTTP_CODE" != "201" ] && [ "$HTTP_CODE" != "409" ]; then
            STATUS="ERROR"
        fi

        echo "${START_NS}|${i}|${HTTP_CODE}|${STATUS}|${UUID}" >> "$RESULTS_FILE"
        rm -f /tmp/oversell_body_${i}.txt
    ) &
done

# Ждём завершения всех фоновых процессов
wait

# Сортируем по времени старта и выводим
echo "Time (ns)           | Request | Status code | Status | UUID"
echo "--------------------+---------+-------------+--------+--------------------------------------"
sort -t'|' -k1 -n "$RESULTS_FILE" | while IFS='|' read -r TIME REQ CODE STATUS UUID; do
    printf "%-20s | %-7s | %-11s | %-6s | %s\n" "$TIME" "$REQ" "$CODE" "$STATUS" "$UUID"
done

echo ""
SUCCESS_COUNT=$(grep -c "|201|" "$RESULTS_FILE")
CONFLICT_COUNT=$(grep -c "|409|" "$RESULTS_FILE")
ERROR_COUNT=$(grep -c "|ERROR" "$RESULTS_FILE")
echo "Summary: 201 Created=$SUCCESS_COUNT, 409 Conflict=$CONFLICT_COUNT, Errors=$ERROR_COUNT"
echo "Expected: 1 Created, 9 Conflict, 0 Errors"

if [ "$SUCCESS_COUNT" -eq 1 ] && [ "$CONFLICT_COUNT" -eq 9 ]; then
    echo "✅ PASS: No oversell detected!"
else
    echo "❌ FAIL: Oversell detected or unexpected errors!"
fi

rm -f "$RESULTS_FILE"
