CREATE TABLE IF NOT EXISTS `mail_logs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `log_hash` VARCHAR(64) UNIQUE NOT NULL, -- Message-ID for Rspamd or hash of raw line for Syslog
    `timestamp` DATETIME NOT NULL,
    `unix_time` INT NOT NULL,
    `host` VARCHAR(255),
    `component` VARCHAR(100),
    `message` TEXT,
    `status` VARCHAR(50),
    `action` VARCHAR(50), -- Rspamd specific
    `score` FLOAT, -- Rspamd specific
    `queue_id` VARCHAR(100),
    `sender` VARCHAR(255),
    `recipient` TEXT,
    `size` INT DEFAULT 0,
    `user` VARCHAR(255),
    `scan_time` FLOAT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_timestamp` (`timestamp`),
    INDEX `idx_sender` (`sender`),
    INDEX `idx_recipient` (`recipient`(255)),
    INDEX `idx_status` (`status`),
    INDEX `idx_queue_id` (`queue_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
