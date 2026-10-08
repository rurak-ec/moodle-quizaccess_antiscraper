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
 * Sends scraping and tampering signals to the server.
 *
 * @module     quizaccess_antiscraper/report
 * @copyright  2026 Rurak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

const THROTTLE_MS = 60000;
const lastSent = new Map();

/**
 * Send one signal. Failures are ignored: the student must never notice them.
 *
 * Low severity signals are sent at most once per minute per type, so that repeated
 * keystrokes or window switches cannot flood the log.
 *
 * @param {Object} config Settings sent by rule.php (config.report is false when the server would not record it).
 * @param {string} incident Incident type, one of those accepted by the server.
 * @param {string} severity 'low' or 'high'.
 * @param {string} details Short description.
 */
export const report = (config, incident, severity, details) => {
    // Teachers see protected pages too, but the server discards their signals: do not send them at all.
    if (config.report === false) {
        return;
    }
    if (severity === 'low') {
        const now = Date.now();
        if (now - (lastSent.get(incident) || 0) < THROTTLE_MS) {
            return;
        }
        lastSent.set(incident, now);
    }

    Ajax.call([{
        methodname: 'quizaccess_antiscraper_report_tampering',
        args: {
            quizid: config.quizid,
            attemptid: config.attemptid,
            incident,
            severity,
            details: String(details).slice(0, 200),
        },
    }])[0].catch(() => null);
};
