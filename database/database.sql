-- MySQL 8.0.16+ / 8.4. Import as a schema owner; runtime user grants are in README.
CREATE DATABASE IF NOT EXISTS tournament CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tournament;
CREATE TABLE admin_users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(100) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE tournaments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, slug VARCHAR(100) NOT NULL UNIQUE,
 description TEXT NOT NULL, logo_path VARCHAR(255) NULL,
 score_mode ENUM('detailed','winner') NOT NULL DEFAULT 'detailed', default_bo TINYINT UNSIGNED NOT NULL DEFAULT 3,
 game_win_score INT UNSIGNED NOT NULL DEFAULT 13, overtime_rules JSON NOT NULL, timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Shanghai',
 stage_count TINYINT UNSIGNED NOT NULL DEFAULT 1, status ENUM('active','disabled','archived') NOT NULL DEFAULT 'active',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CHECK (default_bo IN (1,3,5)), CHECK (stage_count IN (1,2)), CHECK (game_win_score BETWEEN 1 AND 10000)
) ENGINE=InnoDB;
CREATE TABLE tournament_credentials (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tournament_id BIGINT UNSIGNED NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL, password_version INT UNSIGNED NOT NULL DEFAULT 1,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (tournament_id) REFERENCES tournaments(id)
) ENGINE=InnoDB;
CREATE TABLE teams (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tournament_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(100) NOT NULL, seed INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE (tournament_id,name), UNIQUE (tournament_id,id), KEY (tournament_id,seed),
 FOREIGN KEY (tournament_id) REFERENCES tournaments(id)
) ENGINE=InnoDB;
CREATE TABLE stages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tournament_id BIGINT UNSIGNED NOT NULL,
 stage_order TINYINT UNSIGNED NOT NULL, name VARCHAR(100) NOT NULL,
 format_type ENUM('single','double','round_robin','double_round_robin','swiss') NOT NULL,
 configuration_json JSON NOT NULL, status ENUM('pending','running','completed') NOT NULL DEFAULT 'pending',
 locked TINYINT NOT NULL DEFAULT 0, UNIQUE (tournament_id,stage_order), UNIQUE (tournament_id,id),
 FOREIGN KEY (tournament_id) REFERENCES tournaments(id)
) ENGINE=InnoDB;
CREATE TABLE stage_teams (
 tournament_id BIGINT UNSIGNED NOT NULL, stage_id BIGINT UNSIGNED NOT NULL, team_id BIGINT UNSIGNED NOT NULL,
 seed INT UNSIGNED NOT NULL, PRIMARY KEY (stage_id,team_id),
 FOREIGN KEY (tournament_id,stage_id) REFERENCES stages(tournament_id,id),
 FOREIGN KEY (tournament_id,team_id) REFERENCES teams(tournament_id,id)
) ENGINE=InnoDB;
CREATE TABLE `groups` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tournament_id BIGINT UNSIGNED NOT NULL,
 stage_id BIGINT UNSIGNED NOT NULL, name VARCHAR(100) NOT NULL,
 UNIQUE (stage_id,name), UNIQUE (tournament_id,stage_id,id),
 FOREIGN KEY (tournament_id,stage_id) REFERENCES stages(tournament_id,id)
) ENGINE=InnoDB;
CREATE TABLE group_members (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tournament_id BIGINT UNSIGNED NOT NULL,
 stage_id BIGINT UNSIGNED NOT NULL, group_id BIGINT UNSIGNED NOT NULL, team_id BIGINT UNSIGNED NOT NULL,
 UNIQUE (stage_id,team_id),
 FOREIGN KEY (tournament_id,stage_id,group_id) REFERENCES `groups`(tournament_id,stage_id,id),
 FOREIGN KEY (stage_id,team_id) REFERENCES stage_teams(stage_id,team_id)
) ENGINE=InnoDB;
CREATE TABLE matches (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tournament_id BIGINT UNSIGNED NOT NULL,
 stage_id BIGINT UNSIGNED NOT NULL, group_id BIGINT UNSIGNED NULL,
 round_number INT UNSIGNED NOT NULL, match_number INT UNSIGNED NOT NULL, bracket VARCHAR(16) NOT NULL DEFAULT 'main',
 node_key VARCHAR(50) NOT NULL, team_a_id BIGINT UNSIGNED NULL, team_b_id BIGINT UNSIGNED NULL,
 best_of TINYINT UNSIGNED NOT NULL, scheduled_at DATETIME NULL,
 status ENUM('scheduled','live','completed','postponed','cancelled') NOT NULL DEFAULT 'scheduled',
 winner_id BIGINT UNSIGNED NULL, score_a INT NULL, score_b INT NULL,
 description TEXT NULL, forfeit_team_id BIGINT UNSIGNED NULL,
 is_bye TINYINT NOT NULL DEFAULT 0, is_active TINYINT NOT NULL DEFAULT 1, version INT NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE (stage_id,node_key), UNIQUE (tournament_id,match_number), UNIQUE (tournament_id,id),
 KEY (tournament_id,scheduled_at), KEY (stage_id,round_number), KEY (tournament_id,status),
 FOREIGN KEY (tournament_id,stage_id) REFERENCES stages(tournament_id,id),
 FOREIGN KEY (tournament_id,stage_id,group_id) REFERENCES `groups`(tournament_id,stage_id,id),
 FOREIGN KEY (tournament_id,team_a_id) REFERENCES teams(tournament_id,id),
 FOREIGN KEY (tournament_id,team_b_id) REFERENCES teams(tournament_id,id),
 FOREIGN KEY (tournament_id,winner_id) REFERENCES teams(tournament_id,id),
 CONSTRAINT matches_forfeit_team_fk FOREIGN KEY (tournament_id,forfeit_team_id) REFERENCES teams(tournament_id,id),
 CHECK (best_of IN (1,3,5)), CHECK (team_a_id IS NULL OR team_b_id IS NULL OR team_a_id<>team_b_id),
 CHECK (winner_id IS NULL OR winner_id=team_a_id OR winner_id=team_b_id)
) ENGINE=InnoDB;
CREATE TABLE match_games (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, match_id BIGINT UNSIGNED NOT NULL,
 game_number INT UNSIGNED NOT NULL, team_a_score INT UNSIGNED NOT NULL, team_b_score INT UNSIGNED NOT NULL,
 winner_id BIGINT UNSIGNED NOT NULL, UNIQUE (match_id,game_number),
 FOREIGN KEY (match_id) REFERENCES matches(id), FOREIGN KEY (winner_id) REFERENCES teams(id)
) ENGINE=InnoDB;
CREATE TABLE match_maps (
 tournament_id BIGINT UNSIGNED NOT NULL,
 match_id BIGINT UNSIGNED NOT NULL,
 game_number TINYINT UNSIGNED NOT NULL,
 map_name VARCHAR(100) NOT NULL,
 PRIMARY KEY (match_id,game_number),
 KEY (tournament_id,match_id),
 FOREIGN KEY (tournament_id,match_id) REFERENCES matches(tournament_id,id),
 CHECK (game_number BETWEEN 1 AND 5)
) ENGINE=InnoDB;

CREATE TABLE attachments (
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

CREATE TABLE match_dependencies (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tournament_id BIGINT UNSIGNED NOT NULL,
 source_match_id BIGINT UNSIGNED NOT NULL, target_match_id BIGINT UNSIGNED NOT NULL,
 source_result_type ENUM('winner','loser') NOT NULL, target_slot ENUM('a','b') NOT NULL,
 UNIQUE (target_match_id,target_slot), KEY (source_match_id),
 FOREIGN KEY (tournament_id,source_match_id) REFERENCES matches(tournament_id,id),
 FOREIGN KEY (tournament_id,target_match_id) REFERENCES matches(tournament_id,id), CHECK (source_match_id<>target_match_id)
) ENGINE=InnoDB;
CREATE TABLE standings (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tournament_id BIGINT UNSIGNED NOT NULL,
 stage_id BIGINT UNSIGNED NOT NULL, group_id BIGINT UNSIGNED NULL,
 group_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(group_id,0)) STORED,
 team_id BIGINT UNSIGNED NOT NULL, wins INT NOT NULL, losses INT NOT NULL, points INT NOT NULL,
 game_difference INT NOT NULL, buchholz INT NOT NULL DEFAULT 0, rank_position INT NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE (stage_id,group_key,team_id), FOREIGN KEY (tournament_id,stage_id) REFERENCES stages(tournament_id,id),
 FOREIGN KEY (tournament_id,stage_id,group_id) REFERENCES `groups`(tournament_id,stage_id,id),
 FOREIGN KEY (stage_id,team_id) REFERENCES stage_teams(stage_id,team_id)
) ENGINE=InnoDB;
CREATE TABLE login_attempts (
 attempt_key CHAR(64) PRIMARY KEY, failures INT UNSIGNED NOT NULL, window_start DATETIME NOT NULL, KEY (window_start)
) ENGINE=InnoDB;
CREATE TABLE audit_head (id TINYINT PRIMARY KEY, entry_hash CHAR(64) NOT NULL) ENGINE=InnoDB;
INSERT INTO audit_head VALUES (1,REPEAT('0',64));
-- Historical tournament IDs remain in immutable logs after live tournaments are deleted.
CREATE TABLE audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tournament_id BIGINT UNSIGNED NULL,
 actor_type VARCHAR(100) NOT NULL, action VARCHAR(100) NOT NULL, target_type VARCHAR(50) NOT NULL,
 target_id BIGINT UNSIGNED NULL, old_data JSON NULL, new_data JSON NULL, ip_address VARCHAR(45) NOT NULL,
 created_at DATETIME NOT NULL, previous_hash CHAR(64) NOT NULL, entry_hash CHAR(64) NOT NULL, payload LONGTEXT NOT NULL,
 KEY (tournament_id,id), KEY (created_at)
) ENGINE=InnoDB;
-- Neither application administrators nor normal business SQL can mutate history.
DELIMITER $$
CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit history is append only'; END$$
CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit history is append only'; END$$
DELIMITER ;
