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
 * Notice addressed to AI assistants, drawn behind every question and hidden in its markup.
 *
 * @package    quizaccess_antiscraper
 * @copyright  2026 Rurak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_antiscraper;

/**
 * Builds the text of the notice and the tile that repeats it behind each question.
 *
 * No technique guarantees that an assistant refuses; see docs/ANTI-IA.md for what is known about this.
 */
class ai_notice {
    /** @var int Characters per line in the tile. */
    private const LINE = 38;

    /** @var int Width of the tile in pixels. */
    private const WIDTH = 300;

    /** @var int Distance between two lines of text in pixels. */
    private const LEADING = 18;

    /**
     * Text of the notice: the one the administrator wrote or the default of the language pack.
     *
     * @param string $custom the aiwarning_text setting.
     * @return string
     */
    public static function get_text(string $custom): string {
        $text = trim($custom);
        if ($text === '') {
            $text = get_string('aiwarning_default', 'quizaccess_antiscraper');
        }
        return preg_replace('/\s+/u', ' ', $text);
    }

    /**
     * Short reference that depends on the viewer and the attempt, so a reply that repeats it shows where it came from.
     *
     * @param int $userid user id.
     * @param int $attemptid attempt id.
     * @return string for example "ref-3fa91c".
     */
    public static function canary(int $userid, int $attemptid): string {
        $hash = hash_hmac('sha256', "canary|{$userid}|{$attemptid}", get_site_identifier());
        return 'ref-' . substr($hash, 0, 6);
    }

    /**
     * Text kept in the markup of each question for scripts and extensions that read the page.
     *
     * @param string $text the notice, from {@see self::get_text()}.
     * @param int $userid user id.
     * @param int $attemptid attempt id.
     * @return string
     */
    public static function get_dom_text(string $text, int $userid, int $attemptid): string {
        return $text . ' ' .
            get_string('aiwarning_canary', 'quizaccess_antiscraper', self::canary($userid, $attemptid));
    }

    /**
     * SVG tile that repeats the notice as faint, horizontal text.
     *
     * @param string $text the notice, from {@see self::get_text()}.
     * @param float $opacity opacity of the text between 0.01 and 0.5.
     * @return string the SVG markup.
     */
    public static function make_svg(string $text, float $opacity): string {
        $lines = explode("\n", wordwrap($text, self::LINE, "\n", true));
        $height = (count($lines) + 1) * self::LEADING + 14;

        $svgtext = '';
        foreach ($lines as $i => $line) {
            $svgtext .= '<text x="10" y="' . (22 + $i * self::LEADING) . '">' .
                htmlspecialchars($line, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</text>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . self::WIDTH . '" height="' . $height . '">' .
            '<g font-family="Arial,Helvetica,sans-serif" font-size="13" font-weight="600" fill="#000" ' .
            'fill-opacity="' . round(min(0.5, max(0.01, $opacity)), 4) . '">' . $svgtext . '</g></svg>';
    }
}
