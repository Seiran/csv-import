# Импорт заявок (CSV / XLSX)
Формат файла для импорта в базу используется CSV. Если загружается XLSX, то этот файл конвертируется в CSV а потом импортится в базу.

Установка:
1. Выполнить `schema.sql` в MySQL/MariaDB (utf8mb4).
2. Прописать доступы к БД в `config.php` (или переменные IMPORT_DSN / IMPORT_USER / IMPORT_PASS).
3. Папка `storage/` должна быть доступна на запись и не отдаваться веб-сервером (лучше вынести за webroot).
4. `upload_max_filesize` и `post_max_size` >= 32M (см. `.user.ini`; для Apache mod_php — через .htaccess/php.ini).
5. Открыть `index.php`, выбрать `.xlsx` или `.csv`.

PHP 8.1+, расширения: pdo_mysql, zip, xmlreader (xlsx), mbstring.

Как это укладывается в max_execution_time=30: каждый HTTP-запрос работает не дольше `time_budget` (20 с)
и сохраняет позицию в таблице `imports`; JS повторяет запросы до завершения.
xlsx сначала потоково (XMLReader) конвертируется в CSV, затем общий импорт пакетами по 500 строк.

В `import_leads` попадают ВСЕ строки файла (external_id не уникален). Строки с подозрительными данными
(кривой email/телефон/дата) сохраняются как есть и попадают в отчёт (`import_errors`, level=warning).
