CREATE TABLE IF NOT EXISTS "#__panopticon_coresums" (
    "id"       serial        NOT NULL,
    "path"     varchar(1024) NOT NULL DEFAULT '',
    "checksum" varchar(128)  NOT NULL DEFAULT '',
    PRIMARY KEY ("id")
);
