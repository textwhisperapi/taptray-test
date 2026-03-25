CREATE TABLE IF NOT EXISTS change_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  action VARCHAR(64) NOT NULL,
  surrogate INT NOT NULL DEFAULT 0,
  owner_username VARCHAR(255) NOT NULL,
  actor_username VARCHAR(255) NOT NULL,
  file_type VARCHAR(32) NULL,
  file_url VARCHAR(1024) NULL,
  source VARCHAR(64) NULL,
  meta_json TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_change_log_surrogate (surrogate),
  KEY idx_change_log_owner (owner_username),
  KEY idx_change_log_actor (actor_username),
  KEY idx_change_log_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
