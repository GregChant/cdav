-- Lossless CardDAV sidecar. Only properties without a faithful native
-- Dolibarr field are kept here; all business fields stay authoritative.

CREATE TABLE IF NOT EXISTS llx_cdav_vcard (
  entity integer NOT NULL,
  object_type varchar(2) NOT NULL,
  fk_object integer NOT NULL,
  carddata mediumtext NOT NULL,
  datahash char(64) NOT NULL,
  tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (entity, object_type, fk_object)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
