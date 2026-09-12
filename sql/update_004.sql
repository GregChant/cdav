-- Scope stable CardDAV identities by Dolibarr entity and enforce conflicts at
-- database level as well as in the application transaction.

ALTER TABLE llx_cdav_cardmap
  ADD entity integer NOT NULL DEFAULT 1 AFTER object_type;

ALTER TABLE llx_cdav_cardmap
  ADD sourceuid_hash char(64) NOT NULL DEFAULT '' AFTER sourceuid;

UPDATE llx_cdav_cardmap cm
INNER JOIN llx_socpeople contact ON cm.object_type = 'ct' AND contact.rowid = cm.fk_object
SET cm.entity = contact.entity
WHERE cm.entity <> contact.entity;

UPDATE llx_cdav_cardmap cm
INNER JOIN llx_societe thirdparty ON cm.object_type = 'th' AND thirdparty.rowid = cm.fk_object
SET cm.entity = thirdparty.entity
WHERE cm.entity <> thirdparty.entity;

UPDATE llx_cdav_cardmap cm
INNER JOIN llx_adherent member ON cm.object_type = 'mb' AND member.rowid = cm.fk_object
SET cm.entity = member.entity
WHERE cm.entity <> member.entity;

DELETE cm FROM llx_cdav_cardmap cm
LEFT JOIN llx_socpeople native_contact
  ON cm.object_type = 'ct' AND native_contact.rowid = cm.fk_object AND native_contact.entity = cm.entity
LEFT JOIN llx_societe native_thirdparty
  ON cm.object_type = 'th' AND native_thirdparty.rowid = cm.fk_object AND native_thirdparty.entity = cm.entity
LEFT JOIN llx_adherent native_member
  ON cm.object_type = 'mb' AND native_member.rowid = cm.fk_object AND native_member.entity = cm.entity
WHERE cm.object_type NOT IN ('ct', 'th', 'mb')
   OR (cm.object_type = 'ct' AND native_contact.rowid IS NULL)
   OR (cm.object_type = 'th' AND native_thirdparty.rowid IS NULL)
   OR (cm.object_type = 'mb' AND native_member.rowid IS NULL);

-- Legacy development builds could contain an empty UID. Give those mappings
-- a stable, object-specific value before creating the unique hash index.
UPDATE llx_cdav_cardmap
SET sourceuid = CONCAT('cdav-', object_type, '-', fk_object)
WHERE sourceuid = '';

UPDATE llx_cdav_cardmap
SET sourceuid_hash = SHA2(sourceuid, 256)
WHERE sourceuid_hash = '' OR sourceuid_hash <> SHA2(sourceuid, 256);

DELETE duplicate FROM llx_cdav_cardmap duplicate
INNER JOIN llx_cdav_cardmap retained
  ON retained.entity = duplicate.entity
 AND retained.object_type = duplicate.object_type
 AND retained.uuidhash = duplicate.uuidhash
 AND retained.fk_object < duplicate.fk_object;

DELETE duplicate FROM llx_cdav_cardmap duplicate
INNER JOIN llx_cdav_cardmap retained
  ON retained.entity = duplicate.entity
 AND retained.object_type = duplicate.object_type
 AND retained.sourceuid_hash = duplicate.sourceuid_hash
 AND retained.fk_object < duplicate.fk_object;

ALTER TABLE llx_cdav_cardmap
  ADD UNIQUE KEY uk_cdav_cardmap_uri (entity, object_type, uuidhash);

ALTER TABLE llx_cdav_cardmap
  ADD UNIQUE KEY uk_cdav_cardmap_uid (entity, object_type, sourceuid_hash);

ALTER TABLE llx_cdav_cardmap ALTER entity DROP DEFAULT;

ALTER TABLE llx_cdav_cardmap ALTER sourceuid_hash DROP DEFAULT;
