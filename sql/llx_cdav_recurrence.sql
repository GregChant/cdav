-- Marks ActionComm recurrence fields maintained by the CalDAV projection.

CREATE TABLE IF NOT EXISTS llx_cdav_recurrence (
  fk_actioncomm integer NOT NULL,
  rfc_rrule_hash char(64) DEFAULT NULL,
  tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (fk_actioncomm),
  CONSTRAINT fk_cdav_recurrence_actioncomm FOREIGN KEY (fk_actioncomm)
    REFERENCES llx_actioncomm (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
