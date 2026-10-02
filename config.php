<?php
// Настройки подключения к БД. Можно переопределить переменными окружения.
return [
    'dsn'  => getenv('IMPORT_DSN')  ?: 'mysql:host=127.0.0.1;dbname=leads_import;charset=utf8mb4',
    'user' => getenv('IMPORT_USER') ?: 'root',
    'pass' => getenv('IMPORT_PASS') ?: '',

    // Сколько секунд один HTTP-запрос обрабатывает файл (max_execution_time = 30, берём запас).
    'time_budget' => (float)(getenv('IMPORT_TIME_BUDGET') ?: 20),
    // Размер пакета для одного многострочного INSERT.
    'batch_size'  => 500,
    // Куда складываем загруженные файлы (вне webroot).
    'storage_dir' => __DIR__ . '/storage',
    'max_upload'  => 64 * 1024 * 1024,
];
