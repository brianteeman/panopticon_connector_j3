CREATE TABLE IF NOT EXISTS `#__panopticon_coresums` (
    `id`       INT UNSIGNED AUTO_INCREMENT NOT NULL,
    `path`     VARCHAR(1024)              NOT NULL,
    `checksum` VARCHAR(128)               NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4;
