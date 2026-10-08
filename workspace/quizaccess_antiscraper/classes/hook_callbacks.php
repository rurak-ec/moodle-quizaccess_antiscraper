<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Output hook callbacks that protect quiz pages from the first paint.
 *
 * @package    quizaccess_antiscraper
 * @copyright  2026 Rurak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_antiscraper;

use core\hook\output\before_standard_head_html_generation;
use core\hook\output\before_standard_top_of_body_html_generation;

/**
 * Writes the watermark, barcode and notice into the page itself instead of waiting for the AMD modules.
 *
 * Moodle runs AMD modules only once its whole bundle (about 4 MB) has loaded, after the page has been painted, so
 * without this the questions are visible for a moment with no barcode, no notice and their plain text, or hidden
 * until the bundle runs. The rule calls {@see self::activate()} from setup_attempt_page(), which runs before the
 * header; pages that are not protected get nothing.
 */
class hook_callbacks {
    /** @var array|null Settings of the protected page being rendered. */
    private static ?array $params = null;

    /**
     * Mark the current page as protected.
     *
     * @param array $params the settings rule.php also sends to the AMD modules.
     */
    public static function activate(array $params): void {
        self::$params = $params;
    }

    /**
     * Inline style in <head>: the images as CSS variables, and the question content hidden until it is protected.
     *
     * Inline so that it does not depend on the copy of the theme CSS the browser has cached.
     *
     * @param before_standard_head_html_generation $hook Output hook.
     */
    public static function add_head_style(before_standard_head_html_generation $hook): void {
        $params = self::$params;
        if ($params === null) {
            return;
        }

        $vars = [];
        if (!empty($params['watermarkurl'])) {
            $vars['--antiscraper-wm-image'] = 'url("' . self::css_string($params['watermarkurl']) . '")';
            $vars['--antiscraper-wm-opacity'] = (string) (float) $params['opacity'];
            $vars['--antiscraper-wm-size'] = (int) $params['size'] . 'px';
            $vars['--antiscraper-wm-display'] = 'block';
        }
        if (!empty($params['codesvg'])) {
            $vars['--antiscraper-code'] = self::svg_url($params['codesvg']);
            $vars['--antiscraper-code-display'] = 'block';
            $vars['--antiscraper-code-height'] = (int) $params['codeheight'] . 'px';
        }
        if (!empty($params['aisvg'])) {
            $vars['--antiscraper-ai'] = self::svg_url($params['aisvg']);
        }
        $vars['--antiscraper-print-text'] = '"' . self::css_string($params['printtext'] ?? '') . '"';

        $declarations = '';
        foreach ($vars as $name => $value) {
            $declarations .= "{$name}:{$value};";
        }

        // The content of each question stays hidden until the early module marks it ready; if the
        // JavaScript never runs, it shows anyway after 3 seconds so nobody is locked out of the quiz.
        $css = "body.antiscraper-active{{$declarations}}" .
            'body.antiscraper-active .que:not(.antiscraper-decoy):not(.antiscraper-ready)>.content{' .
            'visibility:hidden;animation:antiscraper-reveal 0s linear 3s forwards}' .
            '@keyframes antiscraper-reveal{to{visibility:visible}}';

        $hook->add_html('<style id="antiscraper-early">' . $css . '</style>');
    }

    /**
     * Inline script at the top of <body>: the early AMD module, run without waiting for Moodle's AMD bundle.
     *
     * It places the barcode and the hidden notice while the page is parsed, and at DOMContentLoaded draws the
     * texts on canvases and reveals the questions (amd/src/early.js). The built file is inlined with a tiny
     * define() that runs it at once; the module has no dependencies, so it needs nothing else.
     *
     * @param before_standard_top_of_body_html_generation $hook Output hook.
     */
    public static function add_body_script(before_standard_top_of_body_html_generation $hook): void {
        $params = self::$params;
        if ($params === null) {
            return;
        }

        static $cachedmodule = null;
        if ($cachedmodule === null) {
            $file = __DIR__ . '/../amd/build/early.min.js';
            if (!is_readable($file)) {
                // Watermark.js runs the same module later from the AMD bundle.
                return;
            }
            // Drop the doc comments and the source map link, as lib/requirejs.php does for the source map.
            $cachedmodule = preg_replace(['~/\*\*.*?\*/~s', '~//# sourceMappingURL.*$~s'], '', file_get_contents($file));
        }

        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
        $config = json_encode([
            'code' => !empty($params['codesvg']),
            'codes' => (object) ($params['codes'] ?? []),
            'aitext' => (string) ($params['aitext'] ?? ''),
            'useCanvas' => !empty($params['useCanvas']),
        ], $flags);

        $hook->add_html('<script>(function(define){' . trim($cachedmodule) . '})' .
            '(function(n,d,f){var e={};f(e);e.init(' . $config . ');});</script>');
    }

    /**
     * CSS url() of an SVG image.
     *
     * Percent-encoded instead of base64: the page is about a third lighter once compressed, and the result never
     * contains a quote, a backslash or a "<" that could end the string or the style element.
     *
     * @param string $svg the SVG markup.
     * @return string
     */
    private static function svg_url(string $svg): string {
        return 'url("data:image/svg+xml;charset=utf-8,' . rawurlencode($svg) . '")';
    }

    /**
     * Make a value safe inside a double-quoted CSS string within an inline style element.
     *
     * @param string $value the raw value.
     * @return string
     */
    private static function css_string(string $value): string {
        return str_replace(['\\', '"', '<', '>', "\r", "\n"], ['', '', '', '', ' ', ' '], $value);
    }
}
