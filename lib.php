<?php
declare(strict_types=1);

const COLUMNS = [
    'external_id', 'created_at', 'first_name', 'last_name', 'phone', 'email', 'city',
    'source', 'utm_campaign', 'product', 'budget_uah', 'status', 'manager', 'comment', 'next_contact_at',
];

function cfg(): array
{
    static $c;
    return $c ??= require __DIR__ . '/config.php';
}

function db(): PDO
{
    static $pdo;
    if (!$pdo) {
        $c = cfg();
        $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function is_mysql(): bool
{
    return db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
}

/** Пустая строка -> null, обрезаем пробелы (включая неразрывные). */
function clean(?string $v): ?string
{
    if ($v === null) {
        return null;
    }
    $v = trim(preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $v));
    return $v === '' ? null : $v;
}

function parse_budget(?string $v): ?string
{
    $v = clean($v);
    if ($v === null) {
        return null;
    }
    // "23 700" / "23\u{00A0}700" / "23 700,50" -> 23700.50
    $v = preg_replace('/[\s\x{00A0}]+/u', '', $v);
    $v = str_replace(',', '.', $v);
    return is_numeric($v) ? number_format((float)$v, 2, '.', '') : false;
}

function parse_date(?string $v)
{
    $v = clean($v);
    if ($v === null) {
        return null;
    }
    $d = DateTime::createFromFormat('!Y-m-d H:i:s', $v);
    $err = DateTime::getLastErrors();
    if (!$d || ($err && ($err['warning_count'] || $err['error_count']))) {
        return false;
    }
    return $d->format('Y-m-d H:i:s');
}

/**
 * Превращает строку CSV в массив для БД.
 * Возвращает [array|null, string|null причина отказа, array предупреждений].
 *
 * Отказ (строка не сохраняется) — только если строку нельзя осмысленно записать:
 * не те колонки или нет external_id. Всё остальное сохраняется как есть,
 * а подозрительные значения (кривой email/телефон/дата) попадают в предупреждения:
 * по условию задачи в базе должны оказаться все строки файла.
 */
function normalize_row(array $r): array
{
    if (count($r) !== count(COLUMNS)) {
        return [null, 'Неверное количество колонок: ' . count($r), []];
    }
    $r = array_combine(COLUMNS, $r);
    $warn = [];

    $external = clean($r['external_id']);
    if ($external === null) {
        return [null, 'Пустой external_id', []];
    }

    $email = clean($r['email']);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $warn[] = 'Некорректный email: ' . mb_substr($email, 0, 60);
    }
    $phone = clean($r['phone']);
    if ($phone !== null && !preg_match('/^\d{11,13}$/', $phone)) {
        $warn[] = 'Подозрительный телефон: ' . mb_substr($phone, 0, 30);
    }

    $created = parse_date($r['created_at']);
    if ($created === false) {
        $warn[] = 'Некорректная created_at, записан NULL';
        $created = null;
    }
    $next = parse_date($r['next_contact_at']);
    if ($next === false) {
        $warn[] = 'Некорректная next_contact_at, записан NULL';
        $next = null;
    }
    $budget = parse_budget($r['budget_uah']);
    if ($budget === false) {
        $warn[] = 'Некорректный budget_uah, записан NULL';
        $budget = null;
    }

    return [[
        $external,
        $created,
        clean($r['first_name']),
        clean($r['last_name']),
        $phone,
        $email,
        clean($r['city']),
        clean($r['source']),
        clean($r['utm_campaign']),
        clean($r['product']),
        $budget,
        clean($r['status']),
        clean($r['manager']),
        clean($r['comment']),
        $next,
    ], null, $warn];
}

/** Пакетная вставка одним запросом. $rows — массивы [import_id, row_no, ...колонки]. */
function insert_batch(array $rows): void
{
    if (!$rows) {
        return;
    }
    $cols = implode(',', array_map(fn($c) => '`' . $c . '`', array_merge(['import_id', 'row_no'], COLUMNS)));
    $one  = '(' . implode(',', array_fill(0, count(COLUMNS) + 2, '?')) . ')';
    $sql  = "INSERT INTO import_leads ($cols) VALUES " . implode(',', array_fill(0, count($rows), $one));

    $params = [];
    foreach ($rows as $row) {
        foreach ($row as $v) {
            $params[] = $v;
        }
    }
    db()->prepare($sql)->execute($params);
}

/** $items: [[line_no, level, reason, raw], ...] */
function log_errors(int $importId, array $items): void
{
    foreach (array_chunk($items, 500) as $chunk) {
        $sql = 'INSERT INTO import_errors (import_id, line_no, level, reason, raw) VALUES '
             . implode(',', array_fill(0, count($chunk), '(?,?,?,?,?)'));
        $p = [];
        foreach ($chunk as $e) {
            array_push($p, $importId, $e[0], $e[1], $e[2], $e[3]);
        }
        db()->prepare($sql)->execute($p);
    }
}

function load_import(int $id): array
{
    $st = db()->prepare('SELECT * FROM imports WHERE id=?');
    $st->execute([$id]);
    $imp = $st->fetch();
    if (!$imp) {
        throw new RuntimeException('Импорт не найден');
    }
    return $imp;
}

/**
 * Один HTTP-шаг импорта: работает не дольше time_budget секунд.
 * Состояние (фаза, смещение в файле) хранится в таблице imports,
 * поэтому следующий запрос продолжает с того же места.
 *
 * Для xlsx сначала идёт фаза конвертации в CSV (потоково, тоже с лимитом времени),
 * затем общая фаза импорта CSV в БД.
 */
function process_import(int $id): array
{
    $c = cfg();
    $deadline = microtime(true) + $c['time_budget'];
    $imp = load_import($id);
    if ($imp['status'] === 'done') {
        return import_state($imp);
    }

    // Защита от двух параллельных запросов на один и тот же импорт.
    $lock = fopen($imp['src_path'] . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return import_state($imp) + ['busy' => true];
    }

    try {
        if ($imp['src_type'] === 'xlsx' && in_array($imp['status'], ['pending', 'converting'], true)) {
            require_once __DIR__ . '/xlsx.php';
            $res = xlsx_to_csv($imp['src_path'], $imp['file_path'], count(COLUMNS),
                               (int)$imp['conv_rows'], (int)$imp['conv_bytes'], $deadline);
            if ($res['done']) {
                db()->prepare('UPDATE imports SET status=?, conv_rows=?, conv_bytes=?, file_size=?, byte_offset=0 WHERE id=?')
                    ->execute(['running', $res['rows'], $res['bytes'], $res['bytes'], $id]);
            } else {
                db()->prepare('UPDATE imports SET status=?, conv_rows=?, conv_bytes=? WHERE id=?')
                    ->execute(['converting', $res['rows'], $res['bytes'], $id]);
            }
            $imp = load_import($id);
            if ($imp['status'] === 'converting') {
                return import_state($imp);
            }
        }
        import_csv_chunk($imp, $deadline);
    } catch (Throwable $e) {
        db()->prepare('UPDATE imports SET status=? WHERE id=?')->execute(['failed', $id]);
        throw $e;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    $imp = load_import($id);
    if ($imp['status'] === 'done' && $imp['src_type'] === 'xlsx') {
        @unlink($imp['file_path']);       // промежуточный CSV больше не нужен
    }
    return import_state($imp);
}

/** Читает CSV с сохранённой позиции и пишет пакетами, пока не кончится время или файл. */
function import_csv_chunk(array $imp, float $deadline): void
{
    $c  = cfg();
    $id = (int)$imp['id'];

    $fh = fopen($imp['file_path'], 'rb');
    if (!$fh) {
        throw new RuntimeException('Не удалось открыть файл');
    }

    $offset    = (int)$imp['byte_offset'];
    $line      = (int)$imp['line_no'];       // номер записи данных (без заголовка)
    $processed = (int)$imp['processed'];
    $saved     = (int)$imp['saved'];
    $errCount  = (int)$imp['errors'];
    $warnCount = (int)$imp['warnings'];

    if ($offset === 0) {
        // Заголовок + возможный BOM.
        $header = fgetcsv($fh, 0, ',', '"', '');
        if (!$header) {
            throw new RuntimeException('Пустой файл');
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        if (array_map('trim', $header) !== COLUMNS) {
            throw new RuntimeException('Заголовок файла не совпадает с ожидаемым');
        }
        $offset = ftell($fh);
    } else {
        fseek($fh, $offset);
    }

    $finished = false;
    do {
        $batch = [];
        $log   = [];
        $read  = 0;
        $bad   = 0;

        while ($read < $c['batch_size'] && ($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $line++;
            $read++;
            if ($r === [null]) {             // пустая строка
                $bad++;
                $log[] = [$line + 1, 'error', 'Пустая строка', ''];
                continue;
            }
            [$row, $reason, $warn] = normalize_row($r);
            $raw = mb_substr(implode(',', $r), 0, 1000);
            if ($row === null) {
                $bad++;
                $log[] = [$line + 1, 'error', $reason, $raw];
                continue;
            }
            array_unshift($row, $id, $line);
            $batch[] = $row;
            foreach ($warn as $w) {
                $log[] = [$line + 1, 'warning', $w, $raw];
            }
            if ($warn) {
                $warnCount++;
            }
        }

        if ($read === 0) {
            $finished = true;
            break;
        }

        $offset    = ftell($fh);
        $processed += $read;
        $saved     += count($batch);
        $errCount  += $bad;

        // Данные пакета и новая позиция в файле фиксируются атомарно:
        // после сбоя следующий запрос не пропустит и не задвоит строки.
        db()->beginTransaction();
        try {
            insert_batch($batch);
            log_errors($id, $log);
            db()->prepare('UPDATE imports SET status=?, byte_offset=?, line_no=?, processed=?, saved=?, errors=?, warnings=? WHERE id=?')
                ->execute(['running', $offset, $line, $processed, $saved, $errCount, $warnCount, $id]);
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }
    } while (microtime(true) < $deadline);

    fclose($fh);

    if ($finished) {
        db()->prepare('UPDATE imports SET status=?, finished_at=? WHERE id=?')
            ->execute(['done', date('Y-m-d H:i:s'), $id]);
    }
}

function import_state(array $imp): array
{
    $size  = max(1, (int)$imp['file_size']);
    $phase = match (true) {
        $imp['status'] === 'done' => 'done',
        in_array($imp['status'], ['pending', 'converting'], true) && $imp['src_type'] === 'xlsx' => 'converting',
        default => 'importing',
    };
    return [
        'id'        => (int)$imp['id'],
        'status'    => $imp['status'],
        'phase'     => $phase,
        'done'      => $imp['status'] === 'done',
        'percent'   => $phase === 'done' ? 100 : ($phase === 'converting' ? 0 : min(99, (int)floor($imp['byte_offset'] / $size * 100))),
        'converted' => (int)$imp['conv_rows'],
        'processed' => (int)$imp['processed'],
        'saved'     => (int)$imp['saved'],
        'errors'    => (int)$imp['errors'],
        'warnings'  => (int)$imp['warnings'],
    ];
}
