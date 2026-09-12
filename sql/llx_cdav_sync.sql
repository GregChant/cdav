-- RFC 6578 state and tombstone journal. Business objects remain in their
-- native Dolibarr tables; these rows only describe DAV collection history.

CREATE TABLE IF NOT EXISTS llx_cdav_sync_collection (
  entity integer NOT NULL,
  scope varchar(4) NOT NULL,
  collection_id integer NOT NULL,
  synctoken bigint unsigned NOT NULL DEFAULT 0,
  min_token bigint unsigned NOT NULL DEFAULT 0,
  initialized tinyint NOT NULL DEFAULT 0,
  tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (entity, scope, collection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS llx_cdav_sync_state (
  entity integer NOT NULL,
  scope varchar(4) NOT NULL,
  collection_id integer NOT NULL,
  uri_hash char(64) NOT NULL,
  uri varchar(1024) NOT NULL,
  etag_hash char(64) NOT NULL,
  tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (entity, scope, collection_id, uri_hash),
  CONSTRAINT fk_cdav_sync_state_collection FOREIGN KEY (entity, scope, collection_id)
    REFERENCES llx_cdav_sync_collection (entity, scope, collection_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llx_cdav_sync_change (
  rowid bigint unsigned NOT NULL AUTO_INCREMENT,
  entity integer NOT NULL,
  scope varchar(4) NOT NULL,
  collection_id integer NOT NULL,
  uri_hash char(64) NOT NULL,
  uri varchar(1024) NOT NULL,
  operation char(1) NOT NULL,
  datec datetime NOT NULL,
  PRIMARY KEY (rowid),
  KEY idx_cdav_sync_change_collection (entity, scope, collection_id, rowid),
  CONSTRAINT fk_cdav_sync_change_collection FOREIGN KEY (entity, scope, collection_id)
    REFERENCES llx_cdav_sync_collection (entity, scope, collection_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
