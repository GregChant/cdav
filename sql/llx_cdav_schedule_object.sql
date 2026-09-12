-- Local RFC 6638 inbox and bounded outgoing audit journal.

CREATE TABLE IF NOT EXISTS llx_cdav_schedule_object (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  entity integer NOT NULL,
  fk_principal integer NOT NULL,
  direction char(1) NOT NULL,
  uri varchar(255) NOT NULL,
  uri_hash char(64) NOT NULL,
  message_hash char(64) NOT NULL,
  calendardata mediumblob NOT NULL,
  etag char(64) NOT NULL,
  datec datetime NOT NULL,
  tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  expires_at datetime NOT NULL,
  UNIQUE KEY uk_cdav_schedule_uri (entity, fk_principal, direction, uri_hash),
  UNIQUE KEY uk_cdav_schedule_message (entity, fk_principal, direction, message_hash),
  KEY idx_cdav_schedule_expiry (expires_at),
  CONSTRAINT fk_cdav_schedule_principal FOREIGN KEY (fk_principal)
    REFERENCES llx_user (rowid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
