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
 * Privacy provider for the anti-scraper quiz access rule.
 *
 * @package    quizaccess_antiscraper
 * @copyright  2026 Rurak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_antiscraper\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\transform;
use mod_quiz\privacy\quizaccess_provider;
use mod_quiz\privacy\quizaccess_user_provider;
use mod_quiz\quiz_settings;

/**
 * Describes, exports and deletes the signals stored for each user.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        quizaccess_provider,
        quizaccess_user_provider {

    /**
     * Describe the stored data.
     *
     * @param collection $collection the collection to extend.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('quizaccess_antiscraper_logs', [
            'quizid' => 'privacy:metadata:logs:quizid',
            'attemptid' => 'privacy:metadata:logs:attemptid',
            'userid' => 'privacy:metadata:logs:userid',
            'incident' => 'privacy:metadata:logs:incident',
            'severity' => 'privacy:metadata:logs:severity',
            'details' => 'privacy:metadata:logs:details',
            'timecreated' => 'privacy:metadata:logs:timecreated',
        ], 'privacy:metadata:logs');

        return $collection;
    }

    /**
     * Export the signals recorded for a user in a quiz.
     *
     * @param quiz_settings $quiz the quiz.
     * @param \stdClass $user the user.
     * @return \stdClass the data to export.
     */
    public static function export_quizaccess_user_data(quiz_settings $quiz, \stdClass $user): \stdClass {
        global $DB;

        $logs = $DB->get_records('quizaccess_antiscraper_logs',
            ['quizid' => $quiz->get_quizid(), 'userid' => $user->id], 'timecreated ASC');

        $incidents = [];
        foreach ($logs as $log) {
            $incidents[] = (object) [
                'attemptid' => $log->attemptid,
                'incident' => $log->incident,
                'severity' => $log->severity,
                'details' => $log->details,
                'timecreated' => transform::datetime($log->timecreated),
            ];
        }

        return (object) ['incidents' => $incidents];
    }

    /**
     * Delete the signals of every user in a quiz.
     *
     * @param quiz_settings $quiz the quiz.
     */
    public static function delete_quizaccess_data_for_all_users_in_context(quiz_settings $quiz) {
        global $DB;
        $DB->delete_records('quizaccess_antiscraper_logs', ['quizid' => $quiz->get_quizid()]);
    }

    /**
     * Delete the signals of one user in a quiz.
     *
     * @param quiz_settings $quiz the quiz.
     * @param \stdClass $user the user.
     */
    public static function delete_quizaccess_data_for_user(quiz_settings $quiz, \stdClass $user) {
        global $DB;
        $DB->delete_records('quizaccess_antiscraper_logs',
            ['quizid' => $quiz->get_quizid(), 'userid' => $user->id]);
    }

    /**
     * Delete the signals of several users in a quiz context.
     *
     * @param approved_userlist $userlist the approved users and context.
     */
    public static function delete_quizaccess_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if (!$userids || $context->contextlevel != CONTEXT_MODULE) {
            return;
        }

        $cm = get_coursemodule_from_id('quiz', $context->instanceid);
        if (!$cm) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['quizid'] = $cm->instance;
        $DB->delete_records_select('quizaccess_antiscraper_logs', "quizid = :quizid AND userid $insql", $params);
    }
}
