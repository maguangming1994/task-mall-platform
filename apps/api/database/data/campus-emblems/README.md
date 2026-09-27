# Campus emblem sources and usage

This directory contains optimized PNG copies of school emblems used only to identify campuses in the application.

`manifest.json` is the authoritative inventory. Every item records the Ministry of Education school code and exact school name, bundled filename, SHA-256 digest, source name, and an optional source URL. The sync command rejects mismatched names, checksums, missing files, and paths that escape this directory.

School emblems and wordmarks remain trademarks or copyrighted works of their respective institutions. Bundling an optimized copy does not transfer ownership or grant a general-purpose license. Each image must come from a school-controlled or otherwise documented public source and must be used only for campus identification. Remove or replace an item if its institution requests it or if its source cannot be verified.

Run `php artisan campuses:sync-branding --dry-run` before importing. The write command never replaces branding already configured in the admin console and is safe to run again after an interrupted import.

Each successful import records the processed Ministry of Education school code in the media asset metadata. That history is intentionally retained if an administrator later removes the campus emblem, so a future deployment does not restore a mark that was deliberately removed.
