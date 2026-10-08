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
 * Blocks the right-click menu, copying, dragging and inspection shortcuts on quiz pages.
 *
 * These are deterrents, not a lock: the browser menu, another browser or a phone get around
 * them. They never apply inside text fields, where students must be able to type and paste.
 *
 * @module     quizaccess_antiscraper/input_guard
 * @copyright  2026 Rurak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {report} from 'quizaccess_antiscraper/report';

const EDITABLE = 'input, textarea, select, [contenteditable="true"], [role="textbox"], .tox-edit-area';
const QUESTION = '.que';

/**
 * Whether the node is a field where the student types.
 *
 * @param {EventTarget|Element|null} node Node to check.
 * @return {boolean}
 */
const isEditable = (node) => {
    return node instanceof Element && node.closest(EDITABLE) !== null;
};

/**
 * Whether a key press is a shortcut that opens the developer tools.
 *
 * @param {KeyboardEvent} event The keydown event.
 * @return {boolean}
 */
const isInspectShortcut = (event) => {
    const key = (event.key || '').toLowerCase();
    if (event.key === 'F12') {
        return true;
    }
    const mod = event.ctrlKey || event.metaKey;
    // Ctrl+Shift+I / J / C on Windows and Linux, Cmd+Option+I / J / C on macOS.
    return mod && (event.shiftKey || event.altKey) && ['i', 'j', 'c'].includes(key);
};

/**
 * Start the guards.
 *
 * @param {Object} config Settings sent by rule.php.
 * @param {string} config.protectedtext Text placed on the clipboard instead of the content.
 */
export const init = (config) => {
    const protectedText = String(config.protectedtext || '');

    document.addEventListener('contextmenu', (event) => {
        if (!isEditable(event.target)) {
            event.preventDefault();
            report(config, 'clipboard_attempt', 'low', 'context menu');
        }
    });

    ['copy', 'cut'].forEach((type) => {
        document.addEventListener(type, (event) => {
            if (isEditable(event.target) || isEditable(document.activeElement)) {
                return;
            }
            event.preventDefault();
            if (event.clipboardData) {
                event.clipboardData.setData('text/plain', protectedText);
            }
            report(config, 'clipboard_attempt', 'low', type);
        });
    });

    ['dragstart', 'selectstart'].forEach((type) => {
        document.addEventListener(type, (event) => {
            const target = event.target instanceof Element ? event.target : event.target.parentElement;
            if (target && target.closest(QUESTION) && !isEditable(target)) {
                event.preventDefault();
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (isInspectShortcut(event)) {
            event.preventDefault();
            report(config, 'devtools_shortcut', 'low', event.key);
            return;
        }

        const mod = event.ctrlKey || event.metaKey;
        if (!mod || event.shiftKey || event.altKey) {
            return;
        }
        const key = (event.key || '').toLowerCase();
        if (['s', 'p', 'u'].includes(key) || (['c', 'x', 'a'].includes(key) && !isEditable(event.target))) {
            event.preventDefault();
            report(config, 'clipboard_attempt', 'low', `ctrl+${key}`);
        }
    });

    // The screenshot key cannot be stopped; the best that can be done is to replace
    // what it puts on the clipboard and to leave a trace.
    document.addEventListener('keyup', (event) => {
        if (event.key === 'PrintScreen') {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(protectedText).catch(() => null);
            }
            report(config, 'printscreen_key', 'low', 'PrintScreen');
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            report(config, 'focus_lost', 'low', 'tab hidden');
        }
    });
    window.addEventListener('blur', () => {
        // Moving the focus into an iframe of the page (the editor of an essay, an embedded video) also blurs
        // the window, but the student has not left the quiz.
        const active = document.activeElement;
        if (active && active.tagName === 'IFRAME') {
            return;
        }
        report(config, 'focus_lost', 'low', 'window blur');
    });
};
