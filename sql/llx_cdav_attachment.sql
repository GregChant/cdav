-- Track only external appointment links created by CalDAV.  The business
-- attachment remains a native llx_links row and is always created/deleted
-- through Dolibarr's Link API.  This table records provenance so removing an
-- ATTACH property never deletes a link that a user added directly in Dolibarr.

CREATE TABLE IF NOT EXISTS llx_cdav_attachment (
  fk_actioncomm integer NOT NULL,
  fk_link integer NOT NULL,
  source_uri_hash char(64) NOT NULL,
  datec datetime NOT NULL,
  PRIMARY KEY (fk_actioncomm, fk_link),
  UNIQUE KEY uk_cdav_attachment_uri (fk_actioncomm, source_uri_hash),
  CONSTRAINT fk_cdav_attachment_actioncomm
    FOREIGN KEY (fk_actioncomm) REFERENCES llx_actioncomm (id) ON DELETE CASCADE,
  CONSTRAINT fk_cdav_attachment_link
    FOREIGN KEY (fk_link) REFERENCES llx_links (rowid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
