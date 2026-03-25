ALTER TABLE ep_groups
  ADD COLUMN is_role_group TINYINT(1) NOT NULL DEFAULT 0
  AFTER is_all_members;
