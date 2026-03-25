ALTER TABLE members
ADD COLUMN group_type VARCHAR(32) NULL
AFTER profile_type;

