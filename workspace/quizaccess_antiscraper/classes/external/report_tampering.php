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
 * External function that records scraping signals sent by the attempt page.
 *
 * @package    quizaccess_antiscraper
 * @copyright  2026 Rurak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_antiscraper\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use quizaccess_antiscraper\event\tampering_detected;

/**
 * Stores a scraping or tampering signal. It never blocks the student.
 */
class report_tampering extends external_api {
    /** @var string[] Incident types the page is allowed to report. */
    public const INCIDENTS = [
        'honeypot_interaction', 'honeypot_checked', 'dom_injection',
        'clipboard_attempt', 'devtools_shortcut', 'printscreen_key', 'focus_lost',
        'dom_tamper',
    ];

    /** @var int Maximum reports per user, quiz and severity in the rate-limit window. */
    public const RATE_LIMIT = 10;

    /** @var int Rate-limit window in seconds. */
    public const RATE_WINDOW = 60;

    /**
     * Parameters of {@see self::execute()}.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'quizid' => new external_value(PARAM_INT, 'Quiz id'),
            'attemptid' => new external_value(PARAM_INT, 'Attempt id, 0 if unknown', VALUE_DEFAULT, 0),
            'incident' => new external_value(PARAM_ALPHANUMEXT, 'Incident type'),
            'severity' => new external_value(PARAM_ALPHA, 'low or high', VALUE_DEFAULT, 'low'),
            'details' => new external_value(PARAM_TEXT, 'Short description of the signal', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Record one signal.
     *
     * @param int $quizid quiz id.
     * @param int $attemptid attempt id (0 if unknown).
     * @param string $incident one of {@see self::INCIDENTS}.
     * @param string $severity low or high.
     * @param string $details short free text.
     * @return array ['logged' => bool]
     */
    public static function execute(
        int $quizid,
        int $attemptid,
        string $incident,
        string $severity = 'low',
        string $details = ''
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'quizid' => $quizid,
            'attemptid' => $attemptid,
            'incident' => $incident,
            'severity' => $severity,
            'details' => $details,
        ]);

        // Checked before any database query.
        if (!in_array($params['incident'], self::INCIDENTS, true)) {
            throw new \invalid_parameter_exception('Unknown incident type');
        }

        $cm = get_coursemodule_from_instance('quiz', $params['quizid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);

        // Teachers see protected pages too, but their signals are not recorded, and the call must not
        // throw (Moodle would show them an error dialog). rule.php already tells their page not to send them.
        if (!has_capability('mod/quiz:attempt', $context)) {
            return ['logged' => false];
        }

        $severity = $params['severity'] === 'high' ? 'high' : 'low';

        // Only keep an attempt id that belongs to this user and quiz.
        $attemptid = 0;
        if (
            $params['attemptid'] && $DB->record_exists(
                'quiz_attempts',
                ['id' => $params['attemptid'], 'quiz' => $params['quizid'], 'userid' => $USER->id]
            )
        ) {
            $attemptid = $params['attemptid'];
        }

        // A misbehaving page must not be able to flood the table.
        // Severities are counted apart so that noisy low signals cannot hide a high one.
        $recent = $DB->count_records_select(
            'quizaccess_antiscraper_logs',
            'quizid = :quizid AND userid = :userid AND severity = :severity AND timecreated > :since',
            [
                'quizid' => $params['quizid'],
                'userid' => $USER->id,
                'severity' => $severity,
                'since' => time() - self::RATE_WINDOW,
            ]
        );
        if ($recent >= self::RATE_LIMIT) {
            return ['logged' => false];
        }

        $record = (object) [
            'quizid' => $params['quizid'],
            'attemptid' => $attemptid,
            'userid' => $USER->id,
            'incident' => $params['incident'],
            'severity' => $severity,
            'details' => \core_text::substr($params['details'], 0, 500),
            'timecreated' => time(),
        ];
        $record->id = $DB->insert_record('quizaccess_antiscraper_logs', $record);

        tampering_detected::create([
            'objectid' => $record->id,
            'context' => $context,
            'other' => ['incident' => $record->incident, 'severity' => $severity, 'attemptid' => $attemptid],
        ])->trigger();

        return ['logged' => true];
    }

    /**
     * Return structure of {@see self::execute()}.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'logged' => new external_value(PARAM_BOOL, 'Whether the signal was stored'),
        ]);
    }
}
