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
 * Event fired when a scraping signal is reported from a quiz page.
 *
 * @package    quizaccess_antiscraper
 * @copyright  2026 Rurak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_antiscraper\event;

/**
 * Scraping or tampering signal detected in a quiz attempt page.
 */
class tampering_detected extends \core\event\base {
    /**
     * Set the event defaults.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'quizaccess_antiscraper_logs';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventtamperingdetected', 'quizaccess_antiscraper');
    }

    /**
     * Description shown in the logs report.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' triggered the anti-scraper signal '{$this->other['incident']}' " .
            "(severity '{$this->other['severity']}') in the quiz with course module id '{$this->contextinstanceid}'.";
    }

    /**
     * Link to the quiz.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/quiz/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Validate the event data.
     */
    protected function validate_data() {
        parent::validate_data();
        if ($this->contextlevel != CONTEXT_MODULE) {
            throw new \coding_exception('The context must be a quiz module context.');
        }
        if (!isset($this->other['incident']) || !isset($this->other['severity'])) {
            throw new \coding_exception('The incident and severity must be set in other.');
        }
    }
}
