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
 * Entry point of the anti-scraper client layers that need Moodle's AMD modules.
 *
 * The barcode, the notice, the canvases and the CSS variables are already in the page: classes/hook_callbacks.php
 * writes the variables during the header and runs the early module inline. This module starts the decoys and the
 * input guards and keeps the barcode and the notice in place if they are removed.
 *
 * @module     quizaccess_antiscraper/watermark
 * @copyright  2026 Rurak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {report} from 'quizaccess_antiscraper/report';
import {init as protect, placeCode, placeNotice} from 'quizaccess_antiscraper/early';
import * as honeypotWatcher from 'quizaccess_antiscraper/honeypot_watcher';
import * as inputGuard from 'quizaccess_antiscraper/input_guard';

const QUESTION = '.que:not(.antiscraper-decoy)';

/**
 * Put back the barcode, the notice and the body class if they are removed, and log the attempt.
 *
 * A person with the developer tools can always edit their own copy of the page; this does not stop
 * that, it restores what was removed and records a low-severity signal. It never blocks the student.
 * Only the direct children of each question and of its header are observed, which is where the barcode and the
 * notice live: answering, flagging, dragging or typing in an editor happen deeper and never wake the observer,
 * and neither does the 100 ms quiz timer, which is outside the questions.
 *
 * @param {NodeListOf<HTMLElement>} questions The real questions.
 * @param {Object} config Settings sent by rule.php.
 */
const watch = (questions, config) => {
    if (!window.MutationObserver) {
        return;
    }

    const headers = new WeakMap();
    let observer = null;

    // Observe the current header of a question, also when it was replaced by a new one.
    const follow = (que) => {
        const info = que.querySelector(':scope > .info');
        if (info && headers.get(que) !== info) {
            headers.set(que, info);
            observer.observe(info, {childList: true});
        }
    };

    const restore = (que) => {
        follow(que);
        const code = config.code && placeCode(que, config.codes);
        const notice = config.aitext && placeNotice(que, config.aitext);
        if (code || notice) {
            report(config, 'dom_tamper', 'low', 'element removed');
        }
    };

    observer = new MutationObserver((mutations) => {
        const touched = new Set();
        mutations.forEach((mutation) => {
            const target = mutation.target;
            touched.add(target.classList.contains('info') ? target.parentElement : target);
        });
        touched.forEach((que) => {
            if (que && que.isConnected) {
                restore(que);
            }
        });
    });
    questions.forEach((que) => {
        observer.observe(que, {childList: true});
        follow(que);
    });

    const bodyObserver = new MutationObserver(() => {
        if (!document.body.classList.contains('antiscraper-active')) {
            document.body.classList.add('antiscraper-active');
            report(config, 'dom_tamper', 'low', 'body class removed');
        }
    });
    bodyObserver.observe(document.body, {attributes: true, attributeFilter: ['class']});
};

/**
 * Start the client layers.
 *
 * @param {Object} config Settings sent by rule.php.
 * @param {number} config.quizid Quiz id.
 * @param {number} config.attemptid Attempt id, 0 if unknown.
 * @param {boolean} config.report Whether the signals of this user are recorded.
 * @param {boolean} config.code Whether the identity barcode is shown.
 * @param {Object} config.codes Packed identity code of each question of the attempt, by question (slot) number.
 * @param {string} config.aitext Hidden notice text for each question, empty if disabled.
 * @param {boolean} config.useCanvas Whether to draw the question text on a canvas.
 * @param {boolean} config.useHoneypot Whether to place the decoys.
 * @param {string} config.protectedtext Text placed on the clipboard instead of the content.
 */
export const init = (config) => {
    // The inline copy of the early module has normally protected and shown every question already.
    if (document.querySelector(`${QUESTION}:not(.antiscraper-ready)`)) {
        protect(config);
    }

    honeypotWatcher.init(config);
    inputGuard.init(config);
    watch(document.querySelectorAll(QUESTION), config);
};
