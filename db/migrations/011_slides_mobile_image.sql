-- Migration 011 — Optional mobile-specific hero image per slide
-- Lets admins upload a separate, less-cropped image for phones instead of
-- the desktop banner being force-cropped into a short mobile frame.
--
-- Applied automatically by setup.php's column-migration step; this file
-- exists for DBAs who prefer applying migrations manually.

ALTER TABLE slides
    ADD COLUMN image_mobile VARCHAR(255) DEFAULT NULL AFTER image;
