-- ---------------------------------------------------------------------------
-- Portable "one owner per team" key.
--
-- 0001 originally used `owner_scope CHAR(36) AS (IF(role = 'owner', team_id, NULL))`
-- with a unique index on it. That expression is CHAR-typed over a CHAR column,
-- so its padding semantics depend on sql_mode=PAD_CHAR_TO_FULL_LENGTH. MariaDB
-- 10.4 only warns about it; 10.5 and newer refuse it in a STORED generated
-- column with:
--
--   ERROR 1901: Function or expression 'if(`role` = 'owner',`team_id`,NULL)'
--   cannot be used in the GENERATED ALWAYS AS clause of `owner_scope`
--
-- which makes a schema dump fail to import on anything after 10.4.
--
-- The fix keeps the invariant and removes the string typing: the generated
-- column is a TINYINT flag (1 = owner, NULL otherwise) and the unique key is
-- (team_id, owner_scope). NULLs repeat in a unique index, so only owners are
-- constrained — at most one per team — exactly as before.
--
-- Runs safely on both shapes: 0001 now creates the TINYINT form, and databases
-- created from the older 0001 still have the CHAR(36) form. Both have the index
-- and the column, so drop-then-add produces the same final table.
-- ---------------------------------------------------------------------------

ALTER TABLE team_members
    DROP INDEX uq_team_members_single_owner;

ALTER TABLE team_members
    DROP COLUMN owner_scope;

ALTER TABLE team_members
    ADD COLUMN owner_scope TINYINT AS (IF(role = 'owner', 1, NULL)) PERSISTENT AFTER status;

ALTER TABLE team_members
    ADD UNIQUE KEY uq_team_members_single_owner (team_id, owner_scope);
