CREATE TABLE IF NOT EXISTS imports (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    src_type      ENUM('csv','xlsx') NOT NULL DEFAULT 'csv',
    src_path      VARCHAR(255) NOT NULL,            -- загруженный файл
    file_path     VARCHAR(255) NOT NULL,            -- CSV, который читает импорт (для xlsx — результат конвертации)
    original_name VARCHAR(255) NOT NULL,
    file_size     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status        ENUM('pending','converting','running','done','failed') NOT NULL DEFAULT 'pending',
    conv_rows     INT UNSIGNED NOT NULL DEFAULT 0,  -- xlsx: сколько строк уже в CSV
    conv_bytes    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    byte_offset   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    line_no       INT UNSIGNED NOT NULL DEFAULT 0,
    processed     INT UNSIGNED NOT NULL DEFAULT 0,
    saved         INT UNSIGNED NOT NULL DEFAULT 0,
    errors        INT UNSIGNED NOT NULL DEFAULT 0,  -- строки, которые НЕ удалось сохранить
    warnings      INT UNSIGNED NOT NULL DEFAULT 0,  -- строки сохранены, но с подозрительными данными
    created_at    DATETIME NOT NULL,
    finished_at   DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS import_errors (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_id INT UNSIGNED NOT NULL,
    line_no   INT UNSIGNED NOT NULL,
    level     ENUM('error','warning') NOT NULL,
    reason    VARCHAR(255) NOT NULL,
    raw       TEXT NULL,
    KEY idx_import (import_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- external_id НЕ уникален: в файле 205 значений повторяются у разных людей,
-- а по условию в базе должны оказаться все строки.
-- Защита от дублей при повторах/возобновлении — UNIQUE (import_id, row_no).
CREATE TABLE IF NOT EXISTS import_leads (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_id       INT UNSIGNED NOT NULL,
    row_no          INT UNSIGNED NOT NULL,
    external_id     VARCHAR(32)  NOT NULL,
    created_at      DATETIME NULL,
    first_name      VARCHAR(100) NULL,
    last_name       VARCHAR(100) NULL,
    phone           VARCHAR(32)  NULL,
    email           VARCHAR(190) NULL,
    city            VARCHAR(100) NULL,
    source          VARCHAR(100) NULL,
    utm_campaign    VARCHAR(100) NULL,
    product         VARCHAR(150) NULL,
    budget_uah      DECIMAL(12,2) NULL,
    status          VARCHAR(32)  NULL,
    manager         VARCHAR(100) NULL,
    comment         TEXT NULL,
    next_contact_at DATETIME NULL,
    UNIQUE KEY uq_import_row (import_id, row_no),
    KEY idx_external_id (external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
