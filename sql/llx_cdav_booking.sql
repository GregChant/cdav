-- Public profile and bounded request state; business events stay in ActionComm.
CREATE TABLE IF NOT EXISTS llx_cdav_booking_profile (
  rowid integer NOT NULL AUTO_INCREMENT,
  entity integer NOT NULL,
  fk_user integer NOT NULL,
  label varchar(120) DEFAULT NULL,
  fk_soc integer DEFAULT NULL,
  fk_project integer DEFAULT NULL,
  enabled tinyint NOT NULL DEFAULT 0,
  config text NOT NULL,
  token_hash char(64) DEFAULT NULL,
  token_ciphertext varchar(160) DEFAULT NULL,
  PRIMARY KEY (rowid),
  KEY idx_cdav_booking_owner (entity, fk_user),
  UNIQUE KEY uk_cdav_booking_token (token_hash),
  CONSTRAINT fk_cdav_booking_owner FOREIGN KEY (fk_user) REFERENCES llx_user (rowid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llx_cdav_booking_request (
  request_hash char(64) NOT NULL,
  fk_profile integer NOT NULL,
  fk_actioncomm integer DEFAULT NULL,
  body_hash char(64) NOT NULL,
  reference char(32) NOT NULL,
  start_at bigint NOT NULL,
  end_at bigint NOT NULL,
  expires_at bigint NOT NULL,
  mail_customer tinyint NOT NULL DEFAULT 0,
  mail_owner tinyint NOT NULL DEFAULT 0,
  PRIMARY KEY (request_hash),
  UNIQUE KEY uk_cdav_booking_reference (reference),
  KEY idx_cdav_booking_expiry (expires_at),
  CONSTRAINT fk_cdav_booking_request_profile FOREIGN KEY (fk_profile) REFERENCES llx_cdav_booking_profile (rowid) ON DELETE CASCADE,
  CONSTRAINT fk_cdav_booking_request_event FOREIGN KEY (fk_actioncomm) REFERENCES llx_actioncomm (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llx_cdav_booking_rate (
  bucket_hash char(64) NOT NULL,
  hits integer NOT NULL,
  expires_at bigint NOT NULL,
  PRIMARY KEY (bucket_hash),
  KEY idx_cdav_booking_rate_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
