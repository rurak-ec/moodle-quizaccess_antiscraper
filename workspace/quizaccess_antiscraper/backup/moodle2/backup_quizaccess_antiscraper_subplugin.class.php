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
 * Backup instructions for the anti-scraper quiz access rule.
 *
 * @package    quizaccess_antiscraper
 * @category   backup
 * @copyright  2026 Rurak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/backup/moodle2/backup_mod_quiz_access_subplugin.class.php');

/**
 * Saves the per-quiz settings. The incident logs hold user data and are not backed up.
 */
class backup_quizaccess_antiscraper_subplugin extends backup_mod_quiz_access_subplugin {

    /**
     * Define the XML structure of the subplugin data.
     *
     * @return backup_subplugin_element
     */
    protected function define_quiz_subplugin_structure() {
        parent::define_quiz_subplugin_structure();

        $subplugin = $this->get_subplugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $config = new backup_nested_element('quizaccess_antiscraper_cfg', null,
            ['enabled', 'use_canvas', 'use_honeypot', 'ai_notice', 'watermark', 'identity_code']);

        $subplugin->add_child($wrapper);
        $wrapper->add_child($config);

        $config->set_source_table('quizaccess_antiscraper_cfg', ['quizid' => backup::VAR_ACTIVITYID]);

        return $subplugin;
    }
}
