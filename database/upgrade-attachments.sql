CREATE TABLE IF NOT EXISTS attachments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 tournament_id BIGINT UNSIGNED NOT NULL,
 match_id BIGINT UNSIGNED NULL,
 original_name VARCHAR(255) NOT NULL,
 size_bytes INT UNSIGNED NOT NULL,
 is_public TINYINT NOT NULL DEFAULT 0,
 content MEDIUMBLOB NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY (tournament_id,match_id,is_public,id),
 FOREIGN KEY (tournament_id) REFERENCES tournaments(id),
 FOREIGN KEY (tournament_id,match_id) REFERENCES matches(tournament_id,id),
 CHECK (is_public IN (0,1) AND (match_id IS NULL OR is_public=0)),
 CHECK (size_bytes BETWEEN 1 AND 10485760)
) ENGINE=InnoDB;
