# Changelog

All notable changes to **quizaccess_antiscraper** are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.9.2] - 2026-10-08

### Added
- Official support for **Moodle 5.3 (LTS)** (`$plugin->supported = [405, 503]`).
- CI matrix covering `MOODLE_503_STABLE` on PHP 8.3 (PostgreSQL) and PHP 8.4 (MariaDB).

### Optimized
- **Zero-impact Page Rendering and High Concurrency**:
  - In `hook_callbacks::add_body_script()`, added static memoization for the early inline script (`early.min.js`), completely eliminating disk reads and regex parsing on concurrent attempt views.
  - In `watermark::get_url()`, added static in-memory caching to avoid redundant file-storage database queries during question rendering.
  - Verified that all styling is strictly scoped under `body.antiscraper-active` with zero overhead or DOM matching on regular Moodle pages.

## [1.9.1] - 2026-10-08

### Fixed
- Fixed decimal setting validation in admin settings for opacity values.

## [1.9.0] - 2026-10-07

### Added
- Admin settings redesign with per-quiz toggles and size calculation improvements.
