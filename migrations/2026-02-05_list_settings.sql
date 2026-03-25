-- Generic per-list settings (key/value) for sync and future features
CREATE TABLE IF NOT EXISTS list_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  list_id INT NOT NULL,
  setting_key VARCHAR(64) NOT NULL,
  setting_value TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_list_setting (list_id, setting_key),
  INDEX idx_list_settings_list_id (list_id),
  CONSTRAINT fk_list_settings_list
    FOREIGN KEY (list_id) REFERENCES content_lists(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
