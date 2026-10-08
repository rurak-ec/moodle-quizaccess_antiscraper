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
 * Restore instructions for the anti-scraper quiz access rule.
 *
 * @package    quizaccess_antiscraper
 * @category   backup
 * @copyright  2026 Rurak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/backup/moodle2/restore_mod_quiz_access_subplugin.class.php');

/**
 * Restores the per-quiz settings.
 */
class restore_quizaccess_antiscraper_subplugin extends restore_mod_quiz_access_subplugin {
    /**
     * Path structure to restore.
     *
     * @return array
     */
    protected function define_quiz_subplugin_structure() {
        $path = $this->get_pathfor('/quizaccess_antiscraper_cfg');
        return [new restore_path_element('quizaccess_antiscraper_cfg', $path)];
    }

    /**
     * Restore one settings row.
     *
     * @param array $data the row read from the backup.
     */
    public function process_quizaccess_antiscraper_cfg($data) {
        global $DB;

        $data = (object) $data;
        unset($data->id);
        $data->quizid = $this->get_new_parentid('quiz');

        if (!$DB->record_exists('quizaccess_antiscraper_cfg', ['quizid' => $data->quizid])) {
            $DB->insert_record('quizaccess_antiscraper_cfg', $data);
        }
    }
}
