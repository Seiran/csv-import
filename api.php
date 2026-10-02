<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
session_start();

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    if ($action === 'errors') {           // скачать CSV
        $id = (int)($_GET['id'] ?? 0);
        $st = db()->prepare('SELECT line_no, level, reason, raw FROM import_errors WHERE import_id=? ORDER BY line_no, id');
        $st->execute([$id]);
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=import_{$id}_errors.csv");
        $o = fopen('php://output', 'w');
        fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, ['row_in_file', 'level', 'reason', 'raw'], ',', '"', '');
        foreach ($st as $r) {
            fputcsv($o, [$r['line_no'], $r['level'], $r['reason'], $r['raw']], ',', '"', '');
        }
        exit;
    }

    // Остальные действия — POST + CSRF.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        out(['error' => 'POST required'], 405);
    }
    if (!hash_equals($_SESSION['csrf'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        out(['error' => 'Неверный CSRF-токен, обновите страницу'], 403);
    }

    if ($action === 'upload') {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            $msg = ($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($f['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE
                ? 'Файл больше upload_max_filesize/post_max_size' : 'Файл не получен';
            out(['error' => $msg], 400);
        }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            out(['error' => 'Нужен файл .csv или .xlsx'], 400);
        }
        if ($f['size'] > cfg()['max_upload']) {
            out(['error' => 'Файл слишком большой'], 400);
        }
        $dir = cfg()['storage_dir'];
        is_dir($dir) || mkdir($dir, 0775, true);
        $base = $dir . '/' . bin2hex(random_bytes(12));
        $src  = $base . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $src)) {
            out(['error' => 'Не удалось сохранить файл'], 500);
        }
        if ($ext === 'xlsx') {
            if (!str_starts_with((string)file_get_contents($src, false, null, 0, 2), 'PK')) {
                @unlink($src);
                out(['error' => 'Файл не похож на настоящий .xlsx'], 400);
            }
            $csv = $base . '.csv';          // сюда потоково сконвертируется лист
        } else {
            $csv = $src;                    // csv читается как есть
        }
        db()->prepare('INSERT INTO imports (src_type, src_path, file_path, original_name, file_size, status, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$ext, $src, $csv, basename($f['name']), $ext === 'csv' ? filesize($src) : 0, 'pending', date('Y-m-d H:i:s')]);
        out(['id' => (int)db()->lastInsertId()]);
    }

    if ($action === 'process') {
        ignore_user_abort(true);          // закрытая вкладка не прерывает пакет на полпути
        out(process_import((int)($_POST['id'] ?? 0)));
    }

    out(['error' => 'Неизвестное действие'], 400);
} catch (Throwable $e) {
    out(['error' => $e->getMessage()], 500);
}
