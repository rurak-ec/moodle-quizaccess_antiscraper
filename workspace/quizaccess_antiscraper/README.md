# quizaccess_antiscraper

Moodle quiz access rule subplugin that protects quiz attempt pages against automated screen scrapers, copy-paste extraction, and AI assistants.

| | |
|---|---|
| Component | `quizaccess_antiscraper` |
| Display name | Anti-scraper protection |
| Version | `2026100804` |
| Release | `v1.9.2` |
| Maturity | `MATURITY_STABLE` |
| Moodle | 4.5 – 5.3 (`$plugin->supported = [405, 503]`) |
| PHP | 8.1 – 8.4 |
| License | GNU GPL v3 or later |

## Features

1. **Watermark protection**: Optional dynamic watermark centered behind question formulations.
2. **Data Matrix Identity code**: Per-question/attempt cryptographic code (ISO/IEC 16022) to trace leaked screenshots.
3. **Canvas-based rendering**: Statement text rendered onto HTML5 canvas in closed shadow roots.
4. **Honeypot decoys**: Hidden decoy questions to detect and log scraper bots.
5. **Anti-IA notice**: Subtle, low-opacity canary prompts designed to alert vision LLMs during unauthorized attempts.
6. **Zero Performance Impact**:
   - Zero global CSS impact: rules are strictly scoped to `body.antiscraper-active`.
   - Core hooks (`before_standard_head_html_generation` / `before_standard_top_of_body_html_generation`) exit immediately when not on an active quiz attempt.
   - In-memory memoization of scripts and asset URLs for maximum concurrency.

## Installation

1. Copy into `mod/quiz/accessrule/antiscraper`.
2. Run `php admin/cli/upgrade.php --non-interactive`.
3. Purge caches: `php admin/cli/purge_caches.php`.
