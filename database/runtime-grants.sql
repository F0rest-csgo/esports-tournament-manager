-- Docker initialization: restrict the account created by the official image.
-- For native deployments replace 'tournament'@'%' with the exact runtime account.
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'tournament'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON tournament.admin_users TO 'tournament'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON tournament.tournaments TO 'tournament'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON tournament.tournament_credentials TO 'tournament'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON tournament.teams TO 'tournament'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON tournament.stages TO 'tournament'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON tournament.stage_teams TO 'tournament'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON tournament.`groups` TO 'tournament'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON tournament.group_members TO 'tournament'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON tournament.matches TO 'tournament'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON tournament.match_games TO 'tournament'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON tournament.match_maps TO 'tournament'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON tournament.attachments TO 'tournament'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON tournament.match_dependencies TO 'tournament'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON tournament.standings TO 'tournament'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON tournament.login_attempts TO 'tournament'@'%';
GRANT SELECT, INSERT ON tournament.audit_logs TO 'tournament'@'%';
GRANT SELECT, UPDATE ON tournament.audit_head TO 'tournament'@'%';
