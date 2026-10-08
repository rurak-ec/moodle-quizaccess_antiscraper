# moodle-quizaccess_antiscraper

Moodle quiz access rule plugin **`quizaccess_antiscraper`** — comprehensive protection for quiz attempts against automated scrapers, copy-pasting, and unauthorized AI assistant ingestion.

- **Component:** `quizaccess_antiscraper`
- **Supported Moodle:** 4.5 – 5.3 LTS (`$plugin->supported = [405, 503]`)
- **PHP:** 8.1 – 8.4
- **License:** GNU GPL v3 or later

## Features

1. **Watermark protection**: Dynamic student watermark centered behind question formulations.
2. **Data Matrix Identity code**: Per-question/attempt cryptographic code (ISO/IEC 16022) to trace leaked screenshots.
3. **Canvas-based rendering**: Statement text rendered onto HTML5 canvas in closed shadow roots.
4. **Honeypot decoys**: Hidden decoy questions to detect and log scraper bots.
5. **Anti-IA notice**: Subtle canary prompts designed to alert vision LLMs during unauthorized attempts.
6. **Zero Performance Impact**:
   - Zero global CSS impact: rules are strictly scoped to `body.antiscraper-active`.
   - Core hooks (`before_standard_head_html_generation` / `before_standard_top_of_body_html_generation`) exit immediately when not on an active quiz attempt.
   - In-memory memoization of scripts and asset URLs for maximum concurrency.

## Requirements

- Moodle 4.5 or newer (up to Moodle 5.3 LTS).
- Standard PHP GD or Imagick extension.

## Installation

1. Copy the plugin code into `mod/quiz/accessrule/antiscraper`.
2. Visit **Site administration → Notifications** or execute:
   ```bash
   php admin/cli/upgrade.php --non-interactive
   php admin/cli/purge_caches.php
   ```

## Configuration

Enable the access rule in **Site administration → Plugins → Activity modules → Quiz → Quiz access rules → Anti-scraper protection** or enable it directly per quiz instance in the quiz settings under **Extra restrictions on attempts**.

## License

GNU General Public License v3 or later. See [LICENSE](LICENSE) for full details.

## Maintainer & Support

Maintained by [Rurak](https://github.com/rurak-ec). Issues and feature requests can be reported via GitHub Issues.
