-- For an existing database. Execute these ALTER statements once only.
-- Prefer bin/upgrade-match-details.php: it detects columns/constraints and is repeatable.
ALTER TABLE matches ADD COLUMN description TEXT NULL;
ALTER TABLE matches ADD COLUMN forfeit_team_id BIGINT UNSIGNED NULL;
ALTER TABLE matches ADD CONSTRAINT matches_forfeit_team_fk
 FOREIGN KEY (tournament_id,forfeit_team_id) REFERENCES teams(tournament_id,id);
