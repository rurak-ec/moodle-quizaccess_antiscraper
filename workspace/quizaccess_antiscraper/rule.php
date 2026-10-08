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
 * Quiz access rule that hardens the attempt pages against page scrapers.
 *
 * @package    quizaccess_antiscraper
 * @copyright  2026 Rurak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use mod_quiz\local\access_rule_base;
use mod_quiz\quiz_settings;

/**
 * Adds the watermark, print blocking, canvas rendering and decoy traps to quiz pages.
 *
 * The class has no namespace on purpose: {@see \mod_quiz\access_manager::get_rule_classes()}
 * looks for a global class named after the component in rule.php.
 */
class quizaccess_antiscraper extends access_rule_base {
    /**
     * Create the rule when it is enabled for the whole site (or was enabled for this quiz before).
     *
     * Only administrators control this: the site setting lives in the admin settings page. The quiz form
     * offers teachers a few yes/no choices (identity code, watermark and, if enabled, the notice for AI assistants),
     * and none of them can turn the protection off.
     *
     * @param quiz_settings $quizobj the quiz being attempted.
     * @param int $timenow the current time.
     * @param bool $canignoretimelimits whether the user can ignore time limits.
     * @return quizaccess_antiscraper|null the rule, or null when it does not apply.
     */
    public static function make(quiz_settings $quizobj, $timenow, $canignoretimelimits) {
        $quiz = $quizobj->get_quiz();
        $enableall = (bool) get_config('quizaccess_antiscraper', 'enableall');

        if (!$enableall && empty($quiz->antiscraper_enabled)) {
            return null;
        }

        // The bypass capability (e.g. screen reader users) is only honoured when it comes from a role
        // assigned at system level, which only administrators can do. Checking it in the quiz context
        // would let a teacher grant it to themselves with a safe permission override.
        // Teachers are protected like students; the last argument is false so admins are not exempt either.
        if (has_capability('quizaccess/antiscraper:bypass', context_system::instance(), null, false)) {
            return null;
        }

        return new self($quizobj, $timenow);
    }

    /**
     * The choices a teacher has in the quiz settings: the identity code, the watermark and, while the administrator
     * has turned that experimental feature on, the notice for AI assistants. All are off unless chosen.
     *
     * Everything else (the protection itself, the image, the wording, the sizes) stays in the site settings. The
     * field names are the aliases of {@see get_settings_sql()}, which is how the form is filled in.
     *
     * @param mod_quiz_mod_form $quizform the quiz settings form.
     * @param MoodleQuickForm $mform the form being built.
     */
    public static function add_settings_form_fields(mod_quiz_mod_form $quizform, MoodleQuickForm $mform) {
        $mform->addElement('header', 'antiscraperhdr', get_string('quizsetting:header', 'quizaccess_antiscraper'));
        $mform->setExpanded('antiscraperhdr', true);

        // Yes / No lists, like the other yes-or-no settings of the quiz form.
        $yesno = [0 => get_string('no'), 1 => get_string('yes')];

        $mform->addElement(
            'select',
            'antiscraper_code',
            get_string('quizsetting:code', 'quizaccess_antiscraper'),
            $yesno
        );
        $mform->addHelpButton('antiscraper_code', 'quizsetting:code', 'quizaccess_antiscraper');
        $mform->setType('antiscraper_code', PARAM_INT);
        $mform->setDefault('antiscraper_code', 0);

        $mform->addElement(
            'select',
            'antiscraper_watermark',
            get_string('quizsetting:watermark', 'quizaccess_antiscraper'),
            $yesno
        );
        $mform->addHelpButton('antiscraper_watermark', 'quizsetting:watermark', 'quizaccess_antiscraper');
        $mform->setType('antiscraper_watermark', PARAM_INT);
        $mform->setDefault('antiscraper_watermark', 0);

        // The notice for AI assistants is experimental: it is offered only while the administrator has turned the
        // feature on, and a quiz keeps its choice while it is off.
        if (get_config('quizaccess_antiscraper', 'aiwarning_enable')) {
            $mform->addElement('select', 'antiscraper_ai', get_string('quizsetting:ai', 'quizaccess_antiscraper'), $yesno);
            $mform->addHelpButton('antiscraper_ai', 'quizsetting:ai', 'quizaccess_antiscraper');
            $mform->setType('antiscraper_ai', PARAM_INT);
            $mform->setDefault('antiscraper_ai', 0);
        }
    }

    /**
     * Save the three choices of the quiz form.
     *
     * Only the fields the form sent are written, so nothing changes when the quiz is updated without them (the
     * API, or the administrator forcing the notice on every quiz). The rows made here do not turn the protection on:
     * that is still the site setting.
     *
     * @param stdClass $quiz the data from the quiz form, including $quiz->id.
     */
    public static function save_settings($quiz) {
        global $DB;

        $values = [];
        $fields = ['antiscraper_code' => 'identity_code', 'antiscraper_watermark' => 'watermark', 'antiscraper_ai' => 'ai_notice'];
        foreach ($fields as $field => $column) {
            if (isset($quiz->$field)) {
                $values[$column] = empty($quiz->$field) ? 0 : 1;
            }
        }
        if (!$values) {
            return;
        }

        $row = $DB->get_record('quizaccess_antiscraper_cfg', ['quizid' => $quiz->id]);
        if ($row) {
            $values['id'] = $row->id;
            $DB->update_record('quizaccess_antiscraper_cfg', (object) $values);
        } else {
            $DB->insert_record('quizaccess_antiscraper_cfg', (object) ($values + [
                'quizid' => $quiz->id,
                'enabled' => 0,
                'use_canvas' => 1,
                'use_honeypot' => 1,
                'ai_notice' => 0,
                'watermark' => 0,
                'identity_code' => 0,
            ]));
        }
    }

    /**
     * Remove the quiz data when the quiz is deleted.
     *
     * @param stdClass $quiz the quiz being deleted.
     */
    public static function delete_settings($quiz) {
        global $DB;
        $DB->delete_records('quizaccess_antiscraper_cfg', ['quizid' => $quiz->id]);
        $DB->delete_records('quizaccess_antiscraper_logs', ['quizid' => $quiz->id]);
    }

    /**
     * SQL used to load the settings together with the quiz record.
     *
     * @param int $quizid the quiz id.
     * @return array [fields, joins, params]
     */
    public static function get_settings_sql($quizid) {
        return [
            'antiscraper.enabled AS antiscraper_enabled, ' .
                'antiscraper.use_canvas AS antiscraper_canvas, ' .
                'antiscraper.use_honeypot AS antiscraper_honeypot, ' .
                'COALESCE(antiscraper.ai_notice, 0) AS antiscraper_ai, ' .
                'COALESCE(antiscraper.watermark, 0) AS antiscraper_watermark, ' .
                'COALESCE(antiscraper.identity_code, 0) AS antiscraper_code',
            'LEFT JOIN {quizaccess_antiscraper_cfg} antiscraper ON antiscraper.quizid = quiz.id',
            [],
        ];
    }

    /**
     * Load the CSS and the AMD modules on the attempt, summary and review pages.
     *
     * @param moodle_page $page the page being set up.
     */
    public function setup_attempt_page($page) {
        global $USER;

        $quiz = $this->quizobj->get_quiz();
        $page->add_body_class('antiscraper-active');

        // One read of the plugin settings. A setting added without an upgrade is missing here, and a missing value
        // falls back to its default below.
        $config = get_config('quizaccess_antiscraper');
        $userid = (int) $USER->id;
        $attemptid = optional_param('attempt', 0, PARAM_INT);
        // The notice for AI assistants (experimental) needs both: the administrator turned the feature on and the
        // teacher turned the notice on in this quiz.
        $aion = !empty($config->aiwarning_enable) && !empty($quiz->antiscraper_ai);
        $codeon = !empty($quiz->antiscraper_code);
        $aitext = $aion ? \quizaccess_antiscraper\ai_notice::get_text((string) ($config->aiwarning_text ?? '')) : '';

        $params = [
            'quizid' => (int) $quiz->id,
            'attemptid' => $attemptid,
            'watermarkurl' => empty($quiz->antiscraper_watermark) ? '' : \quizaccess_antiscraper\watermark::get_url(),
            // The identity code is off unless the teacher turned it on in this quiz. Each question gets its own code
            // (user, attempt, question); the SVG is the one a question shows when its number is not known.
            'codesvg' => $codeon ? \quizaccess_antiscraper\identity_code::make_svg($userid, $attemptid) : '',
            'codes' => $codeon ? \quizaccess_antiscraper\identity_code::make_slot_codes($userid, $attemptid) : [],
            'codeheight' => min(60, max(20, (int) ($config->codeheight ?? 0) ?: 30)),
            'protectedtext' => get_string('protectedcopy', 'quizaccess_antiscraper'),
            'opacity' => min(1.0, max(0.05, (float) ($config->watermarkopacity ?? 0) ?: 0.20)),
            // Longest side of the watermark in pixels; the stylesheet keeps the proportions and never lets it
            // outgrow the statement and the options.
            'size' => min(2000, max(50, (int) ($config->watermarksize ?? 0) ?: 420)),
            'aisvg' => $aitext === '' ? '' :
                \quizaccess_antiscraper\ai_notice::make_svg($aitext, (float) ($config->aiwarning_opacity ?? 0) ?: 0.05),
            'aitext' => $aitext === '' ? '' : \quizaccess_antiscraper\ai_notice::get_dom_text($aitext, $userid, $attemptid),
            'printtext' => get_string('printblocked', 'quizaccess_antiscraper'),
            'useCanvas' => (bool) ($quiz->antiscraper_canvas ?? 1),
            'useHoneypot' => (bool) ($quiz->antiscraper_honeypot ?? 1),
        ];

        // The watermark is drawn behind the statement and the options of each question, which needs this class.
        if ($params['watermarkurl'] !== '') {
            $page->add_body_class('antiscraper-wm');
        }

        // The images, the variables, the hidden content rule and the early module are written into the page during
        // the header, so the protection is there from the first paint.
        \quizaccess_antiscraper\hook_callbacks::activate($params);

        // Only what the AMD modules need, and only once: the watermark module starts the others.
        // The images are not repeated here; they already travel in the inline style.
        $jsparams = [
            'quizid' => $params['quizid'],
            'attemptid' => $params['attemptid'],
            // Same check as report_tampering, which discards the signals of users who cannot attempt the quiz.
            'report' => has_capability('mod/quiz:attempt', $this->quizobj->get_context()),
            'code' => $params['codesvg'] !== '',
            'codes' => (object) $params['codes'],
            'aitext' => $params['aitext'],
            'protectedtext' => $params['protectedtext'],
            'useCanvas' => $params['useCanvas'],
            'useHoneypot' => $params['useHoneypot'],
        ];
        $page->requires->js_call_amd('quizaccess_antiscraper/watermark', 'init', [$jsparams]);
    }
}
