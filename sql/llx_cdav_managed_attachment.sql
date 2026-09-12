-- RFC 8607 attachment metadata. Bytes stay in Dolibarr's native Agenda tree.

CREATE TABLE IF NOT EXISTS llx_cdav_managed_attachment (
  managed_id char(64) NOT NULL,
  entity integer NOT NULL,
  fk_actioncomm integer NOT NULL,
  fk_user integer NOT NULL,
  filename varchar(255) NOT NULL,
  content_type varchar(128) NOT NULL,
  file_size bigint NOT NULL,
  file_hash char(64) NOT NULL,
  datec datetime NOT NULL,
  tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (managed_id),
  KEY idx_cdav_managed_event (entity, fk_actioncomm),
  KEY idx_cdav_managed_owner (entity, fk_user),
  CONSTRAINT fk_cdav_managed_actioncomm FOREIGN KEY (fk_actioncomm)
    REFERENCES llx_actioncomm (id) ON DELETE CASCADE,
  CONSTRAINT fk_cdav_managed_user FOREIGN KEY (fk_user)
    REFERENCES llx_user (rowid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
