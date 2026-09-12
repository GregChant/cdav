-- Clean technical rows left by older installs, then bind protocol metadata to
-- the native Dolibarr lifecycle. Dolibarr's SQL installer accepts duplicate
-- key errors, so this migration is safe when the module is enabled again.

DELETE FROM llx_cdav_recurrence
WHERE NOT EXISTS (SELECT 1 FROM llx_actioncomm a WHERE a.id = llx_cdav_recurrence.fk_actioncomm);

DELETE FROM llx_cdav_reminder
WHERE NOT EXISTS (SELECT 1 FROM llx_actioncomm a WHERE a.id = llx_cdav_reminder.fk_actioncomm)
   OR NOT EXISTS (SELECT 1 FROM llx_user u WHERE u.rowid = llx_cdav_reminder.fk_user)
   OR NOT EXISTS (SELECT 1 FROM llx_actioncomm_reminder r WHERE r.rowid = llx_cdav_reminder.fk_reminder);

DELETE FROM llx_cdav_schedule_object
WHERE NOT EXISTS (SELECT 1 FROM llx_user u WHERE u.rowid = llx_cdav_schedule_object.fk_principal);

DELETE FROM llx_cdav_managed_attachment
WHERE NOT EXISTS (SELECT 1 FROM llx_actioncomm a WHERE a.id = llx_cdav_managed_attachment.fk_actioncomm)
   OR NOT EXISTS (SELECT 1 FROM llx_user u WHERE u.rowid = llx_cdav_managed_attachment.fk_user);

ALTER TABLE llx_cdav_recurrence
  ADD CONSTRAINT fk_cdav_recurrence_actioncomm FOREIGN KEY (fk_actioncomm)
  REFERENCES llx_actioncomm (id) ON DELETE CASCADE;

ALTER TABLE llx_cdav_reminder
  ADD CONSTRAINT fk_cdav_reminder_actioncomm FOREIGN KEY (fk_actioncomm)
  REFERENCES llx_actioncomm (id) ON DELETE CASCADE;

ALTER TABLE llx_cdav_reminder
  ADD CONSTRAINT fk_cdav_reminder_user FOREIGN KEY (fk_user)
  REFERENCES llx_user (rowid) ON DELETE CASCADE;

ALTER TABLE llx_cdav_reminder
  ADD CONSTRAINT fk_cdav_reminder_native FOREIGN KEY (fk_reminder)
  REFERENCES llx_actioncomm_reminder (rowid) ON DELETE CASCADE;

ALTER TABLE llx_cdav_schedule_object
  ADD CONSTRAINT fk_cdav_schedule_principal FOREIGN KEY (fk_principal)
  REFERENCES llx_user (rowid) ON DELETE CASCADE;

ALTER TABLE llx_cdav_managed_attachment
  ADD CONSTRAINT fk_cdav_managed_actioncomm FOREIGN KEY (fk_actioncomm)
  REFERENCES llx_actioncomm (id) ON DELETE CASCADE;

ALTER TABLE llx_cdav_managed_attachment
  ADD CONSTRAINT fk_cdav_managed_user FOREIGN KEY (fk_user)
  REFERENCES llx_user (rowid) ON DELETE CASCADE;
