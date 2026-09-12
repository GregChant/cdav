-- Preserve CardDAV resource names and source UIDs without the size limits of
-- Dolibarr business objects' ref_ext fields. Business data remains in the
-- native contact, third-party and member tables.

CREATE TABLE IF NOT EXISTS llx_cdav_cardmap (
  entity integer NOT NULL,
  object_type varchar(2) NOT NULL,
  fk_object integer NOT NULL,
  uuidext varchar(1024) NOT NULL,
  uuidhash char(64) NOT NULL,
  sourceuid varchar(1024) NOT NULL,
  sourceuid_hash char(64) NOT NULL,
  tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (object_type, fk_object),
  UNIQUE KEY uk_cdav_cardmap_uri (entity, object_type, uuidhash),
  UNIQUE KEY uk_cdav_cardmap_uid (entity, object_type, sourceuid_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
