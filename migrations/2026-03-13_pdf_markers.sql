CREATE TABLE IF NOT EXISTS pdf_markers (
  id INT NOT NULL AUTO_INCREMENT,
  surrogate INT NOT NULL,
  owner VARCHAR(120) NOT NULL,
  annotator VARCHAR(120) NOT NULL,
  markers MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pdf_markers_surrogate (surrogate),
  KEY idx_pdf_markers_annotator (annotator),
  KEY idx_pdf_markers_owner (owner)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
