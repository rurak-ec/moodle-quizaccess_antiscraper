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
 * Verifies a code found in a screenshot and shows who it belongs to.
 *
 * Usage: php cli/decode_code.php "002860004980012219"
 * The code carries the user, the attempt, the question of the attempt (slot) and a signature. The codes issued before the
 * question was added (14 digits), the 20-digit codes of version 1.3.0 and the older AS286.498.1f2e3d4c QR format are
 * accepted too; they have no question.
 *
 * @package    quizaccess_antiscraper
 * @copyright  2026 Rurak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

$code = implode(' ', array_slice($argv, 1));
$ids = \quizaccess_antiscraper\identity_code::parse($code);
if (!$ids) {
    cli_error('The code is not valid: it is malformed or its signature does not match this site.');
}

$user = $DB->get_record('user', ['id' => $ids['userid']], 'id, firstname, lastname, email');
cli_writeln('Valid code.');
$userinfo = $user ? "{$user->firstname} {$user->lastname} (id {$user->id}, {$user->email})" : "id {$ids['userid']} (deleted)";
cli_writeln('User:    ' . $userinfo);

if ($ids['attemptid'] && !$DB->record_exists('quiz_attempts', ['id' => $ids['attemptid'], 'userid' => $ids['userid']])) {
    cli_writeln(
        "Warning: attempt {$ids['attemptid']} does not belong to this user (or no longer exists); " .
        "the code may have been forged."
    );
}

if ($ids['attemptid']) {
    $sql = "SELECT qa.id, qa.attempt, qa.timestart, q.name AS quizname, c.fullname AS coursename
              FROM {quiz_attempts} qa
              JOIN {quiz} q ON q.id = qa.quiz
              JOIN {course} c ON c.id = q.course
             WHERE qa.id = :id AND qa.userid = :userid";
    $attempt = $DB->get_record_sql($sql, ['id' => $ids['attemptid'], 'userid' => $ids['userid']]);
    if ($attempt) {
        cli_writeln("Quiz:    {$attempt->quizname} (course: {$attempt->coursename})");
        cli_writeln("Attempt: #{$attempt->attempt} (id {$attempt->id}), started " . userdate($attempt->timestart));
    } else {
        cli_writeln("Attempt: id {$ids['attemptid']} no longer exists.");
    }
} else {
    cli_writeln('Attempt: none (page without attempt).');
}

// The question: its number in the attempt (slot), how it was shown and which question it is.
if ($ids['slot'] === 0) {
    cli_writeln('Question: none (this code identifies the page, not one question).');
} else if ($ids['slot'] !== null) {
    $line = "Question: slot {$ids['slot']}";
    try {
        $attemptobj = \mod_quiz\quiz_attempt::create($ids['attemptid']);
        $number = $attemptobj->get_question_number($ids['slot']);
        $question = $attemptobj->get_question_attempt($ids['slot'])->get_question(false);
        $line .= ($number !== '' ? ", shown as \"Question {$number}\"" : '') . ': ' .
            shorten_text(strip_tags(format_string($question->name)), 90) . " (question id {$question->id})";
    } catch (\Throwable $e) {
        $line .= ' (the questions of the attempt could not be loaded)';
    }
    cli_writeln($line);
}
