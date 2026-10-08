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
 * Places invisible decoy questions and reports any interaction with them.
 *
 * A person never sees the decoys, so a click, focus or checked radio means that a script
 * is reading and answering the page. Signals are only logged; nothing is blocked.
 *
 * @module     quizaccess_antiscraper/honeypot_watcher
 * @copyright  2026 Rurak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {report} from 'quizaccess_antiscraper/report';

const DECOY_COUNT = 2;
const POLL_MS = 2000;
const MAX_LOW_SIGNALS = 3;

// Markers of known automation overlays. Weak signal: logged as low severity only.
const SUSPICIOUS = /scraper|solver|tampermonkey|violentmonkey|extension-root|gpt-|copilot/i;

// The plugin's own elements (antiscraper-*) would match "scraper" above.
const OWN = /(^|\s)antiscraper/;

/**
 * Build a decoy question that looks like a real multichoice question.
 *
 * Its inputs point at a form id that does not exist, so they never belong to the quiz form
 * and are never submitted or autosaved.
 *
 * @param {number} index Decoy number.
 * @return {HTMLElement}
 */
const buildDecoy = (index) => {
    const decoy = document.createElement('div');
    decoy.className = 'que multichoice deferredfeedback notyetanswered antiscraper-decoy';
    decoy.setAttribute('aria-hidden', 'true');
    decoy.dataset.antiscraperDecoy = String(index);

    const options = ['A', 'B', 'C', 'D'].map((letter, i) => {
        return `<div class="r${i % 2}">` +
            `<input type="radio" form="antiscraper-no-form" tabindex="-1" autocomplete="off" ` +
            `id="antiscraper-d${index}-${i}" name="antiscraper-d${index}" value="${i}">` +
            `<label for="antiscraper-d${index}-${i}">${letter}. Option ${letter}</label></div>`;
    }).join('');

    decoy.innerHTML = '<div class="content"><div class="formulation clearfix">' +
        '<div class="qtext"><p>Select the option that completes the statement correctly.</p></div>' +
        `<div class="ablock"><div class="answer">${options}</div></div></div></div>`;
    return decoy;
};

/**
 * Insert the decoys in the question list of the attempt page and watch them.
 *
 * @param {Object} config Settings sent by rule.php.
 */
const placeDecoys = (config) => {
    const questions = document.querySelectorAll('#responseform .que');
    if (!questions.length) {
        return;
    }

    const reported = new Set();
    const once = (incident, severity, details) => {
        if (!reported.has(incident)) {
            reported.add(incident);
            report(config, incident, severity, details);
        }
    };

    const decoys = [];
    for (let i = 0; i < DECOY_COUNT; i++) {
        const decoy = buildDecoy(i);
        // One before the first question and one after the last one.
        if (i === 0) {
            questions[0].before(decoy);
        } else {
            questions[questions.length - 1].after(decoy);
        }
        decoys.push(decoy);
    }

    // One delegated listener per decoy instead of four per input.
    decoys.forEach((decoy) => {
        ['click', 'focusin', 'change', 'input'].forEach((type) => {
            decoy.addEventListener(type, (event) => {
                const id = event.target && event.target.id ? event.target.id : decoy.dataset.antiscraperDecoy;
                once('honeypot_interaction', 'high', `${event.type} on ${id}`);
            });
        });
    });

    // Scripts often set .checked directly, which fires no event. Poll until it happens once, then stop.
    const timer = window.setInterval(() => {
        const hit = decoys.some((decoy) => decoy.querySelector('input:checked'));
        if (hit) {
            once('honeypot_checked', 'high', 'decoy radio checked by script');
            window.clearInterval(timer);
        }
    }, POLL_MS);
};

/**
 * Log nodes with known automation markers that appear in the page.
 *
 * @param {Object} config Settings sent by rule.php.
 */
const watchInjections = (config) => {
    let sent = 0;
    // Automation overlays attach as direct children of <body> or <html>, so there is no need to watch
    // the whole subtree (which the 100 ms quiz timer would wake up constantly).
    const observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            for (const node of mutation.addedNodes) {
                if (node.nodeType !== Node.ELEMENT_NODE) {
                    continue;
                }
                const label = `${node.id || ''} ${node.className && node.className.toString() || ''}`;
                if (SUSPICIOUS.test(label) && !OWN.test(label)) {
                    sent++;
                    report(config, 'dom_injection', 'low', `${node.tagName} ${label}`.trim());
                    if (sent >= MAX_LOW_SIGNALS) {
                        observer.disconnect();
                        return;
                    }
                }
            }
        }
    });
    observer.observe(document.documentElement, {childList: true});
    observer.observe(document.body, {childList: true});
};

/**
 * Start the honeypot layer.
 *
 * @param {Object} config Settings sent by rule.php.
 * @param {boolean} config.useHoneypot Whether the quiz enables this layer.
 */
export const init = (config) => {
    if (!config.useHoneypot) {
        return;
    }
    placeDecoys(config);
    watchInjections(config);
};
