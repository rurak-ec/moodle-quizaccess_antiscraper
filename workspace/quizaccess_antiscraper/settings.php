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
 * Settings for the anti-scraper quiz access rule.
 *
 * @package    quizaccess_antiscraper
 * @copyright  2026 Rurak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings->add(
        new admin_setting_configcheckbox(
            'quizaccess_antiscraper/enableall',
            get_string('setting:enableall', 'quizaccess_antiscraper'),
            get_string('setting:enableall_desc', 'quizaccess_antiscraper'),
            1
        )
    );

    $settings->add(
        new admin_setting_configstoredfile(
            'quizaccess_antiscraper/watermarkimage',
            get_string('setting:watermarkimage', 'quizaccess_antiscraper'),
            get_string('setting:watermarkimage_desc', 'quizaccess_antiscraper'),
            \quizaccess_antiscraper\watermark::FILEAREA,
            0,
            ['subdirs' => 0, 'maxfiles' => 1, 'accepted_types' => ['.png', '.jpg', '.jpeg', '.gif', '.svg', '.webp']]
        )
    );

    // Opacity of the mark, 0.05 to 1 (core's PARAM_FLOAT text setting refuses "0.20": it reads back as "0.2").
    $settings->add(
        new \quizaccess_antiscraper\admin_setting_opacity(
            'quizaccess_antiscraper/watermarkopacity',
            get_string('setting:watermarkopacity', 'quizaccess_antiscraper'),
            get_string('setting:watermarkopacity_desc', 'quizaccess_antiscraper'),
            '0.20',
            0.05,
            1.0
        )
    );

    // The longest side of the mark in pixels; the other side follows the proportions of the image.
    $settings->add(
        new admin_setting_configtext(
            'quizaccess_antiscraper/watermarksize',
            get_string('setting:watermarksize', 'quizaccess_antiscraper'),
            get_string('setting:watermarksize_desc', 'quizaccess_antiscraper'),
            '420',
            PARAM_INT,
            4
        )
    );

    $settings->add(
        new admin_setting_configtext(
            'quizaccess_antiscraper/codeheight',
            get_string('setting:codeheight', 'quizaccess_antiscraper'),
            get_string('setting:codeheight_desc', 'quizaccess_antiscraper'),
            '30',
            PARAM_INT,
            4
        )
    );

    // Experimental. Off by default. Turning it on adds the choice to the settings of every quiz (off in each one until
    // the teacher chooses) and shows the wording and the opacity here; turned off, nothing of it is shown anywhere.
    $settings->add(
        new admin_setting_heading(
            'quizaccess_antiscraper/aiwarning_heading',
            get_string('setting:aiwarning_heading', 'quizaccess_antiscraper'),
            get_string('setting:aiwarning_heading_desc', 'quizaccess_antiscraper')
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'quizaccess_antiscraper/aiwarning_enable',
            get_string('setting:aiwarning_enable', 'quizaccess_antiscraper'),
            get_string('setting:aiwarning_enable_desc', 'quizaccess_antiscraper'),
            0
        )
    );

    $settings->add(
        new admin_setting_configtextarea(
            'quizaccess_antiscraper/aiwarning_text',
            get_string('setting:aiwarning_text', 'quizaccess_antiscraper'),
            get_string('setting:aiwarning_text_desc', 'quizaccess_antiscraper'),
            '',
            PARAM_TEXT,
            60,
            5
        )
    );
    $settings->hide_if('quizaccess_antiscraper/aiwarning_text', 'quizaccess_antiscraper/aiwarning_enable');

    $settings->add(
        new \quizaccess_antiscraper\admin_setting_opacity(
            'quizaccess_antiscraper/aiwarning_opacity',
            get_string('setting:aiwarning_opacity', 'quizaccess_antiscraper'),
            get_string('setting:aiwarning_opacity_desc', 'quizaccess_antiscraper'),
            '0.05',
            0.01,
            0.5
        )
    );
    $settings->hide_if('quizaccess_antiscraper/aiwarning_opacity', 'quizaccess_antiscraper/aiwarning_enable');
}
