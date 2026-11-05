-- Run once against the existing tournament database using a schema-maintenance account.
-- This adds an empty table only. It never deletes or rewrites existing scores/accounts/logs.
CREATE TABLE IF NOT EXISTS match_maps (
 tournament_id BIGINT UNSIGNED NOT NULL,
 match_id BIGINT UNSIGNED NOT NULL,
 game_number TINYINT UNSIGNED NOT NULL,
 map_name VARCHAR(100) NOT NULL,
 PRIMARY KEY (match_id,game_number),
 KEY (tournament_id,match_id),
 FOREIGN KEY (tournament_id,match_id) REFERENCES matches(tournament_id,id),
 CHECK (game_number BETWEEN 1 AND 5)
) ENGINE=InnoDB;
