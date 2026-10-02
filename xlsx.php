<?php
declare(strict_types=1);

/**
 * Потоковая конвертация .xlsx -> .csv (XMLReader, без загрузки листа в память).
 * Поддерживает возобновление: $skipRows — сколько строк данных уже записано в CSV.
 * Возвращает состояние конвертации.
 */

function xlsx_col_index(string $ref): int
{
    $n = 0;
    for ($i = 0, $l = strlen($ref); $i < $l; $i++) {
        $c = ord($ref[$i]);
        if ($c < 65 || $c > 90) {
            break;
        }
        $n = $n * 26 + ($c - 64);
    }
    return $n - 1;
}

/** Общие строки (sharedStrings.xml) -> массив. */
function xlsx_shared_strings(string $xlsx): array
{
    $r = new XMLReader();
    if (!@$r->open('zip://' . $xlsx . '#xl/sharedStrings.xml')) {
        return [];
    }
    $out = [];
    while ($r->read()) {
        if ($r->nodeType === XMLReader::ELEMENT && $r->name === 'si') {
            $text = '';
            if (!$r->isEmptyElement) {
                $depth = $r->depth;
                $inPhonetic = false;
                while ($r->read() && !($r->nodeType === XMLReader::END_ELEMENT && $r->name === 'si' && $r->depth === $depth)) {
                    if ($r->nodeType === XMLReader::ELEMENT && $r->name === 'rPh') {
                        $inPhonetic = true;
                    } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->name === 'rPh') {
                        $inPhonetic = false;
                    } elseif (!$inPhonetic && $r->nodeType === XMLReader::ELEMENT && $r->name === 't' && !$r->isEmptyElement) {
                        $text .= $r->readString();
                    }
                }
            }
            $out[] = $text;
        }
    }
    $r->close();
    return $out;
}

/** Индексы стилей (cellXfs), которые отображаются как дата/время. */
function xlsx_date_styles(string $xlsx): array
{
    $xml = @file_get_contents('zip://' . $xlsx . '#xl/styles.xml');
    if ($xml === false) {
        return [];
    }
    $sx = simplexml_load_string($xml);
    if (!$sx) {
        return [];
    }
    $custom = [];
    foreach ($sx->numFmts->numFmt ?? [] as $nf) {
        $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', (string)$nf['formatCode']);
        $custom[(int)$nf['numFmtId']] = (bool)preg_match('/[ymdhs]/i', $code);
    }
    $builtin = array_merge(range(14, 22), range(45, 47));
    $res = [];
    $i = 0;
    foreach ($sx->cellXfs->xf ?? [] as $xf) {      // ключи SimpleXML — имя тега, индекс ведём сами
        $id = (int)$xf['numFmtId'];
        $res[$i++] = in_array($id, $builtin, true) || ($custom[$id] ?? false);
    }
    return $res;
}

function xlsx_serial_to_date(float $serial): string
{
    // 25569 — число дней между 1899-12-30 и 1970-01-01
    return gmdate('Y-m-d H:i:s', (int)round(($serial - 25569) * 86400));
}

function xlsx_number_to_string(string $v): string
{
    if (!is_numeric($v)) {
        return $v;
    }
    $f = (float)$v;
    if (floor($f) === $f && abs($f) < 1e15) {
        return (string)(int)$f;           // 380672341057, 23700
    }
    return rtrim(rtrim(sprintf('%.10F', $f), '0'), '.');
}

/**
 * Конвертирует первый лист в CSV.
 *
 * @param int    $skipRows  сколько строк (включая заголовок) уже в CSV
 * @param int    $csvBytes  размер CSV после последней успешно зафиксированной порции
 * @param float  $deadline  microtime(true), после которого надо остановиться
 * @return array{rows:int, bytes:int, done:bool}
 */
function xlsx_to_csv(string $xlsx, string $csv, int $columns, int $skipRows, int $csvBytes, float $deadline): array
{
    $shared = xlsx_shared_strings($xlsx);
    $dateStyles = xlsx_date_styles($xlsx);

    $r = new XMLReader();
    if (!$r->open('zip://' . $xlsx . '#xl/worksheets/sheet1.xml')) {
        throw new RuntimeException('Не удалось открыть лист xlsx');
    }
    // Если прошлый запрос оборвался посреди записи, отбрасываем «хвост» после последней зафиксированной позиции.
    $out = fopen($csv, $skipRows > 0 ? 'cb' : 'wb');
    if ($skipRows > 0) {
        ftruncate($out, $csvBytes);
        fseek($out, 0, SEEK_END);
    }

    $rowNo = 0;
    $done = true;

    // Встаём на первый <row> внутри <sheetData>.
    $more = false;
    while ($r->read()) {
        if ($r->nodeType === XMLReader::ELEMENT && $r->name === 'row') {
            $more = true;
            break;
        }
    }

    while ($more) {
        $rowNo++;
        if ($rowNo <= $skipRows) {        // уже записанные строки быстро пропускаем
            $more = $r->next('row');
            continue;
        }
        if ($rowNo % 500 === 0 && microtime(true) > $deadline) {
            $done = false;
            $rowNo--;
            break;
        }

        $line = array_fill(0, $columns, '');
        $depth = $r->depth;
        if (!$r->isEmptyElement) {
            while ($r->read() && !($r->nodeType === XMLReader::END_ELEMENT && $r->name === 'row' && $r->depth === $depth)) {
                if ($r->nodeType !== XMLReader::ELEMENT || $r->name !== 'c') {
                    continue;
                }
                $idx   = xlsx_col_index((string)$r->getAttribute('r'));
                $type  = (string)$r->getAttribute('t');
                $style = (int)$r->getAttribute('s');
                $val   = '';
                $cDepth = $r->depth;
                if (!$r->isEmptyElement) {
                    while ($r->read() && !($r->nodeType === XMLReader::END_ELEMENT && $r->name === 'c' && $r->depth === $cDepth)) {
                        if ($r->nodeType !== XMLReader::ELEMENT) {
                            continue;
                        }
                        if ($r->name === 'v' || $r->name === 'is') {
                            $val = $r->readString();
                        }
                    }
                }
                if ($val === '') {
                    continue;
                }
                if ($type === 's') {
                    $val = $shared[(int)$val] ?? '';
                } elseif ($type === 'b') {
                    $val = $val === '1' ? 'TRUE' : 'FALSE';
                } elseif ($type === '' || $type === 'n') {
                    $val = ($dateStyles[$style] ?? false) && is_numeric($val)
                        ? xlsx_serial_to_date((float)$val)
                        : xlsx_number_to_string($val);
                }
                if ($idx >= 0 && $idx < $columns) {
                    $line[$idx] = $val;
                }
            }
        }
        fputcsv($out, $line, ',', '"', '');
        $more = $r->next('row');
    }
    $r->close();
    fflush($out);
    $bytes = (int)ftell($out);
    fclose($out);

    return ['rows' => $rowNo, 'bytes' => $bytes, 'done' => $done];
}
