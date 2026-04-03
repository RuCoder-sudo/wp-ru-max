<?php
/**
 * Генератор лицензионных ключей WP Ru-max
 *
 * ИСПОЛЬЗОВАНИЕ (запускать только на вашем сервере или локально):
 *   php generate-key.php
 *   php generate-key.php 5       ← генерирует 5 ключей
 *
 * ВАЖНО: После генерации:
 * 1. Сохраните сам ключ (WPRM-...) — он нужен покупателю
 * 2. SHA256-хэш добавьте в license-keys.json → массив "keys"
 * 3. Загрузите обновлённый license-keys.json на GitHub
 *
 * НЕ загружайте этот файл на GitHub! Он только для локальной генерации.
 */

$count = isset( $argv[1] ) ? max( 1, min( 100, (int) $argv[1] ) ) : 1;

echo "\n=== Генератор ключей WP Ru-max ===\n\n";

$results = array();

for ( $i = 0; $i < $count; $i++ ) {
    // Генерируем криптографически стойкий ключ
    $random = bin2hex( random_bytes( 8 ) ); // 16 hex символов = 64-bit entropy
    // Форматируем как WPRM-XXXX-XXXX-XXXX-XXXX
    $key = strtoupper( 'WPRM-' . implode( '-', str_split( $random, 4 ) ) );
    $hash = hash( 'sha256', strtolower( $key ) );

    $results[] = array( 'key' => $key, 'hash' => $hash );

    echo "Ключ #" . ( $i + 1 ) . ":\n";
    echo "  Ключ (для покупателя): " . $key . "\n";
    echo "  SHA256 (в license-keys.json): " . $hash . "\n";
    echo "\n";
}

echo "---\n";
echo "Добавьте SHA256-хэши в license-keys.json:\n\n";
echo "\"keys\": [\n";
foreach ( $results as $idx => $r ) {
    $comma = ( $idx < count( $results ) - 1 ) ? ',' : '';
    echo '  "' . $r['hash'] . '"' . $comma . "\n";
}
echo "]\n\n";
echo "=== Готово! Не забудьте загрузить обновлённый license-keys.json на GitHub ===\n\n";
