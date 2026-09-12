-- Remove mappings left by installations that predate the foreign key, then
-- make future native ActionComm deletions cascade to CDav metadata.

DELETE FROM llx_actioncomm_cdav
WHERE NOT EXISTS (
  SELECT 1 FROM llx_actioncomm
  WHERE llx_actioncomm.id = llx_actioncomm_cdav.fk_object
);

ALTER TABLE llx_actioncomm_cdav
  ADD CONSTRAINT fk_actioncomm_cdav_actioncomm
  FOREIGN KEY (fk_object) REFERENCES llx_actioncomm (id) ON DELETE CASCADE;
