-- Provenance for browser reminders created through ActionCommReminder.
-- It lets DAV updates remove only reminders previously created by DAV.

CREATE TABLE IF NOT EXISTS llx_cdav_reminder (
  fk_actioncomm integer NOT NULL,
  fk_user integer NOT NULL,
  fk_reminder integer NOT NULL,
  PRIMARY KEY (fk_actioncomm, fk_user, fk_reminder),
  KEY idx_cdav_reminder_native (fk_reminder),
  CONSTRAINT fk_cdav_reminder_actioncomm FOREIGN KEY (fk_actioncomm)
    REFERENCES llx_actioncomm (id) ON DELETE CASCADE,
  CONSTRAINT fk_cdav_reminder_user FOREIGN KEY (fk_user)
    REFERENCES llx_user (rowid) ON DELETE CASCADE,
  CONSTRAINT fk_cdav_reminder_native FOREIGN KEY (fk_reminder)
    REFERENCES llx_actioncomm_reminder (rowid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
