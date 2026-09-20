-- Backfill stable public-safe identifiers for legacy File Manager rows.
-- Older installs may contain user_files rows created before UID assignment was
-- mandatory in the controller. Cross-module file selection intentionally uses
-- UID rather than numeric database IDs, so those rows must be upgraded.

UPDATE `user_files`
SET `uid` = LOWER(SHA2(CONCAT(
    'workspace-file:',
    `id`, ':',
    `user_id`, ':',
    COALESCE(`path`, ''), ':',
    UUID()
), 256))
WHERE `uid` IS NULL OR TRIM(`uid`) = '';
