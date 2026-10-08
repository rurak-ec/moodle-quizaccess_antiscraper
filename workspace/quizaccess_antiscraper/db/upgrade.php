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
 * Upgrade steps for the anti-scraper quiz access rule.
 *
 * @package    quizaccess_antiscraper
 * @copyright  2026 Rurak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion the version being upgraded from.
 * @return bool
 */
function xmldb_quizaccess_antiscraper_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026100801) {
        // The notice for AI assistants and the watermark are now chosen in each quiz.
        $table = new xmldb_table('quizaccess_antiscraper_cfg');
        $fields = [
            new xmldb_field('ai_notice', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'use_honeypot'),
            new xmldb_field('watermark', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'ai_notice'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // The quizzes that were listed by course module id in the old setting keep their notice.
        $listed = (string) get_config('quizaccess_antiscraper', 'aiwarning_cmids');
        $cmids = array_filter(array_map('intval', explode(',', $listed)));
        $moduleid = $DB->get_field('modules', 'id', ['name' => 'quiz']);
        foreach ($cmids as $cmid) {
            $quizid = $moduleid ? $DB->get_field('course_modules', 'instance', ['id' => $cmid, 'module' => $moduleid]) : false;
            if (!$quizid) {
                continue;
            }
            if ($DB->record_exists('quizaccess_antiscraper_cfg', ['quizid' => $quizid])) {
                $DB->set_field('quizaccess_antiscraper_cfg', 'ai_notice', 1, ['quizid' => $quizid]);
            } else {
                $DB->insert_record('quizaccess_antiscraper_cfg', (object) [
                    'quizid' => $quizid,
                    'enabled' => 0,
                    'use_canvas' => 1,
                    'use_honeypot' => 1,
                    'ai_notice' => 1,
                    'watermark' => 0,
                ]);
            }
        }
        unset_config('aiwarning_cmids', 'quizaccess_antiscraper');

        // The height of the identity code replaced its width. A setting saved while it still had no value reads as 0.
        $height = (int) get_config('quizaccess_antiscraper', 'codeheight');
        if ($height < 20 || $height > 60) {
            set_config('codeheight', 30, 'quizaccess_antiscraper');
        }

        upgrade_plugin_savepoint(true, 2026100801, 'quizaccess', 'antiscraper');
    }

    if ($oldversion < 2026100802) {
        // The identity code is now chosen in each quiz as well (off unless chosen).
        $table = new xmldb_table('quizaccess_antiscraper_cfg');
        $field = new xmldb_field('identity_code', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'watermark');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026100802, 'quizaccess', 'antiscraper');
    }

    if ($oldversion < 2026100803) {
        // The notice for AI assistants (experimental) is now a feature the administrator turns on; each quiz still
        // chooses. "Force in every quiz" is gone, and the watermark width in percent gave way to a size in pixels.
        if (get_config('quizaccess_antiscraper', 'aiwarning_enable')) {
            // It was forced on every quiz: keep that by choosing it in each of them.
            $DB->set_field('quizaccess_antiscraper_cfg', 'ai_notice', 1);
            $rows = $DB->get_fieldset_select('quizaccess_antiscraper_cfg', 'quizid', '1 = 1');
            $quizzes = array_diff($DB->get_fieldset_select('quiz', 'id', '1 = 1'), $rows);
            foreach ($quizzes as $quizid) {
                $DB->insert_record('quizaccess_antiscraper_cfg', (object) ['quizid' => $quizid, 'enabled' => 0,
                    'use_canvas' => 1, 'use_honeypot' => 1, 'ai_notice' => 1, 'watermark' => 0, 'identity_code' => 0]);
            }
        } else if ($DB->record_exists('quizaccess_antiscraper_cfg', ['ai_notice' => 1])) {
            // A quiz already shows the notice: the feature has to stay on for it to keep doing so.
            set_config('aiwarning_enable', 1, 'quizaccess_antiscraper');
        }
        unset_config('watermarkscale', 'quizaccess_antiscraper');
        upgrade_plugin_savepoint(true, 2026100803, 'quizaccess', 'antiscraper');
    }

    return true;
}
