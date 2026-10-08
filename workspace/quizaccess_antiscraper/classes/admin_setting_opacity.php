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
 * Admin setting for an opacity: a decimal number inside a range.
 *
 * @package    quizaccess_antiscraper
 * @copyright  2026 Rurak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_antiscraper;

/**
 * A text setting that takes an opacity such as 0.20, 0.2 or 0,20.
 *
 * Moodle's own text setting with PARAM_FLOAT rejects every value that does not read back
 * identically after being turned into a float, so "0.20" (read back as "0.2"), "0.30" or "0.10"
 * were refused as "invalid value". This one reads the number, checks the range and stores it with a
 * decimal point, which is what the code that uses it expects.
 */
class admin_setting_opacity extends \admin_setting_configtext {
    /** @var float Smallest value accepted. */
    private $min;

    /** @var float Largest value accepted. */
    private $max;

    /**
     * Constructor.
     *
     * @param string $name unique setting name, with the plugin prefix
     * @param string $visiblename localised label
     * @param string $description localised help text
     * @param string $defaultsetting default value, with a decimal point
     * @param float $min smallest opacity accepted
     * @param float $max largest opacity accepted
     */
    public function __construct(
        string $name,
        string $visiblename,
        string $description,
        string $defaultsetting,
        float $min,
        float $max
    ) {
        $this->min = $min;
        $this->max = $max;
        parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_RAW, 4);
    }

    /**
     * Reads what the administrator typed.
     *
     * @param string $data text such as "0.20", "0,2" or ".5"
     * @return float|null the number, or null when it is not a plain decimal number
     */
    public static function parse(string $data): ?float {
        $data = trim($data);
        if (!preg_match('/^(?:\d+(?:[.,]\d*)?|[.,]\d+)$/', $data)) {
            return null;
        }
        return (float) str_replace(',', '.', $data);
    }

    /**
     * Checks that the text is a number inside the range.
     *
     * @param string $data value typed in the form
     * @return true|string true when valid, otherwise the error message
     */
    public function validate($data) {
        $value = self::parse((string) $data);
        if ($value === null || $value < $this->min || $value > $this->max) {
            return get_string('setting:opacity_invalid', 'quizaccess_antiscraper', (object) [
                'min' => format_float($this->min, 2, true, true),
                'max' => format_float($this->max, 2, true, true),
                'example' => format_float((float) self::parse((string) $this->defaultsetting), 2),
            ]);
        }
        return true;
    }

    /**
     * Saves the value with a decimal point, whichever separator was typed.
     *
     * @param string $data value typed in the form
     * @return string an error message, or an empty string when saved
     */
    public function write_setting($data) {
        $validated = $this->validate($data);
        if ($validated !== true) {
            return $validated;
        }
        $data = str_replace(',', '.', trim((string) $data));
        return $this->config_write($this->name, $data) ? '' : get_string('errorsetting', 'admin');
    }
}
