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
 * Helper to find the watermark image uploaded in the plugin settings.
 *
 * @package    quizaccess_antiscraper
 * @copyright  2026 Rurak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_antiscraper;

use context_system;
use moodle_url;

/**
 * Locates the stored watermark file.
 */
class watermark {
    /** @var string File area where the admin setting stores the image. */
    public const FILEAREA = 'watermark';

    /**
     * URL of the uploaded watermark image.
     *
     * @return string the URL, or an empty string when no image was uploaded.
     */
    public static function get_url(): string {
        static $cachedurl = null;
        if ($cachedurl !== null) {
            return $cachedurl;
        }

        // Skip the file query entirely when no image was ever uploaded (the usual case).
        if (!get_config('quizaccess_antiscraper', 'watermarkimage')) {
            $cachedurl = '';
            return $cachedurl;
        }

        $fs = get_file_storage();
        $files = $fs->get_area_files(
            context_system::instance()->id,
            'quizaccess_antiscraper',
            self::FILEAREA,
            0,
            'sortorder, itemid, filepath, filename',
            false
        );
        if (!$files) {
            $cachedurl = '';
            return $cachedurl;
        }

        $file = reset($files);
        $url = moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        );
        // The modification time busts the browser cache when the image is replaced.
        $url->param('v', $file->get_timemodified());

        $cachedurl = $url->out(false);
        return $cachedurl;
    }
}
