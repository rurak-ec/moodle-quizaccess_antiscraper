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
 * First-paint protection: identity code, hidden notice and question text drawn on a canvas.
 *
 * classes/hook_callbacks.php inlines the built copy of this module at the top of <body> with a tiny define()
 * shim, so it works while the page is parsed and reveals the questions at DOMContentLoaded instead of waiting
 * for Moodle's AMD bundle. That is why this module must not import anything. watermark.js imports it too, to
 * restore what is removed and as a fallback when the inline copy did not run.
 *
 * Texts that contain images, formulas, links, lists or form controls are left untouched,
 * because turning them into a picture would break the question or its accessibility.
 *
 * @module     quizaccess_antiscraper/early
 * @copyright  2026 Rurak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const QUESTION = '.que:not(.antiscraper-decoy)';
const FLAG = ':scope > .questionflag, :scope > .editquestion, :scope > .badge';
const NOTICE_STYLE = 'position:absolute;left:0;top:0;width:1px;height:1px;overflow:hidden;' +
    'clip-path:inset(50%);white-space:nowrap;pointer-events:none;user-select:none;';

// Each question draws its own identity code. rule.php sends, for every question (slot) of the attempt, the 8 rows of
// 32 modules of its Data Matrix packed in 32 bytes of base64; the question number is the last part of the id that
// Moodle gives each question: question-<usage>-<slot>. It is drawn on a canvas of one pixel per module (with a quiet
// zone of one), which CSS scales up without smoothing: a page of a question bank has hundreds of codes, and one image
// per code (an SVG as a background) made the browser decode hundreds of images and recalculate styles for each.
const SLOT = /-(\d+)$/;
const CODE_BYTES = 32;
const CODE_ROWS = 8;
const CODE_COLS = 32;

// Added nodes that mean a question, or its finished header, has just been parsed.
const PARSE_TRIGGERS = ['que', 'info', 'content'];

// Statement, text of each answer option (the "a." number stays as real text), and the
// review blocks that repeat the correct answer.
const SELECTORS = [
    '.que .qtext',
    '.que [data-region="answer-label"] .flex-fill',
    '.que .rightanswer',
    '.que .generalfeedback',
    '.que .specificfeedback',
].join(', ');

// Only these tags may appear inside a statement that is turned into a canvas.
const SIMPLE_TAGS = new Set(['P', 'BR', 'DIV', 'SPAN', 'STRONG', 'B', 'EM', 'I']);

// Raw LaTeX that MathJax has not typeset yet, or a filter that will rewrite the text later.
const MATH_PATTERN = /\\\(|\\\[|\$\$|\\begin\{|\[\[|\{mlang/;

// Up to this many texts every canvas gets drawn: the ones on screen before the questions are shown, the rest
// right after. Above it (whole question banks on one page) only the canvases near the viewport keep a bitmap,
// so memory does not grow with the number of questions.
const EAGER_LIMIT = 100;

/**
 * Canvas with the Data Matrix of one question.
 *
 * @param {string} data The packed modules, in base64.
 * @return {?HTMLCanvasElement} The canvas, or null if the data is not valid.
 */
const codeCanvas = (data) => {
    let bytes = '';
    try {
        bytes = window.atob(data);
    } catch (e) {
        return null;
    }
    if (bytes.length !== CODE_BYTES) {
        return null;
    }
    const canvas = document.createElement('canvas');
    canvas.width = CODE_COLS + 2;
    canvas.height = CODE_ROWS + 2;
    const context = canvas.getContext('2d');
    if (!context) {
        return null;
    }
    const dark = (row, col) => Math.floor(bytes.charCodeAt(row * 4 + Math.floor(col / 8)) / (2 ** (7 - (col % 8)))) % 2 === 1;

    context.fillStyle = 'rgba(255,255,255,0.92)';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.fillStyle = '#111';
    // One rectangle per run of dark modules in a row.
    for (let row = 0; row < CODE_ROWS; row++) {
        let col = 0;
        while (col < CODE_COLS) {
            if (!dark(row, col)) {
                col++;
                continue;
            }
            const start = col;
            while (col < CODE_COLS && dark(row, col)) {
                col++;
            }
            context.fillRect(start + 1, row + 1, col - start, 1);
        }
    }
    // Inline, so that they hold even if the browser still has an older theme CSS: sharp modules, and not the
    // code of the page behind the translucent white.
    canvas.style.imageRendering = 'pixelated';
    canvas.style.backgroundImage = 'none';
    return canvas;
};

/**
 * Add the identity code to a question header, between the mark and the flag button.
 *
 * It is also moved back there when the header changed around it (the parser may still be adding the header).
 * The code is the one of this question, on a canvas; a question whose number is not in the list shows the code of the
 * page, a div with the image that the head style defines for all of them.
 *
 * @param {HTMLElement} que The question element.
 * @param {Object} [codes] Packed code of each question of the attempt, by question (slot) number.
 * @return {boolean} Whether the code was missing.
 */
export const placeCode = (que, codes) => {
    const info = que.querySelector(':scope > .info');
    if (!info) {
        return false;
    }
    let code = info.querySelector(':scope > .antiscraper-code');
    const missing = !code;
    if (missing) {
        const match = codes ? SLOT.exec(que.id) : null;
        code = (match && codes[match[1]] && codeCanvas(codes[match[1]])) || document.createElement('div');
        code.className = 'antiscraper-code';
        code.setAttribute('aria-hidden', 'true');
    }
    const flag = info.querySelector(FLAG);
    if (flag ? code.nextElementSibling !== flag : code !== info.lastElementChild) {
        info.insertBefore(code, flag);
    }
    return missing;
};

/**
 * Add the hidden notice for AI assistants to a question.
 *
 * @param {HTMLElement} que The question element.
 * @param {string} text Notice text.
 * @return {boolean} Whether the notice was missing.
 */
export const placeNotice = (que, text) => {
    if (que.querySelector(':scope > .antiscraper-ai-dom')) {
        return false;
    }
    const notice = document.createElement('div');
    notice.className = 'antiscraper-ai-dom';
    notice.setAttribute('aria-hidden', 'true');
    // Inline style so the text stays hidden even if the browser still holds an older theme CSS.
    notice.style.cssText = NOTICE_STYLE;
    notice.textContent = text;
    que.insertBefore(notice, que.firstChild);
    return true;
};

/**
 * Add the code and the notice to one question, as configured.
 *
 * @param {HTMLElement} que The question element.
 * @param {Object} config Settings sent by rule.php.
 */
const place = (que, config) => {
    if (config.aitext) {
        placeNotice(que, config.aitext);
    }
    if (config.code) {
        placeCode(que, config.codes);
    }
};

/**
 * Place the barcode and the notice in each question while the page is being parsed.
 *
 * A MutationObserver callback runs at the end of each parser task, before the browser paints, so they are in
 * the question the first time it is shown. Only the questions touched by each batch are visited.
 *
 * @param {Object} config Settings sent by rule.php.
 * @return {MutationObserver}
 */
const watchParsing = (config) => {
    const observer = new MutationObserver((mutations) => {
        const touched = new Set();
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node.nodeType === Node.ELEMENT_NODE && PARSE_TRIGGERS.some((name) => node.classList.contains(name))) {
                    const que = node.closest(QUESTION);
                    if (que) {
                        touched.add(que);
                    }
                }
            });
        });
        touched.forEach((que) => place(que, config));
    });
    observer.observe(document.documentElement, {childList: true, subtree: true});
    return observer;
};

/**
 * Whether a text can safely become a canvas.
 *
 * @param {HTMLElement} element The text element.
 * @return {boolean}
 */
const isPlainText = (element) => {
    if (element.dataset.antiscraperDone) {
        return false;
    }
    if (!element.textContent.trim() || MATH_PATTERN.test(element.textContent)) {
        return false;
    }
    return Array.from(element.querySelectorAll('*')).every((el) => {
        return SIMPLE_TAGS.has(el.tagName) && !el.className.toString().includes('filter_');
    });
};

/**
 * Split text into lines that fit the width, keeping the paragraph breaks.
 *
 * @param {CanvasRenderingContext2D} ctx Context with the font already set.
 * @param {string} text Text to draw.
 * @param {number} maxWidth Available width in CSS pixels.
 * @return {string[]}
 */
const wrap = (ctx, text, maxWidth) => {
    const lines = [];
    text.split(/\n+/).forEach((paragraph) => {
        let line = '';
        paragraph.split(/\s+/).filter(Boolean).forEach((word) => {
            const candidate = line ? `${line} ${word}` : word;
            if (line && ctx.measureText(candidate).width > maxWidth) {
                lines.push(line);
                line = word;
            } else {
                line = candidate;
            }
        });
        lines.push(line);
    });
    return lines;
};

// Every converted text, and what all of them share: a context to measure text and the observers.
const items = [];
const itemsByHost = new WeakMap();
let measurer = null;
let resizeObserver = null;
let viewObserver = null;
let pendingWidths = null;

/**
 * Break the text of one item into lines for a width and give its canvas the matching CSS size.
 *
 * It only measures text, it never reads the layout, so it can run for every item in a row.
 *
 * @param {Object} item The converted text.
 * @param {number} width Available width in CSS pixels.
 */
const fit = (item, width) => {
    item.width = width;
    measurer.font = item.font;
    item.lines = wrap(measurer, item.text, width);
    item.height = Math.ceil(item.lines.length * item.lineHeight);
    item.canvas.style.width = `${width}px`;
    item.canvas.style.height = `${item.height}px`;
};

/**
 * Draw one item, or free its bitmap while it is far from the viewport (its CSS size stays, so nothing moves).
 *
 * @param {Object} item The converted text.
 */
const draw = (item) => {
    const canvas = item.canvas;
    if (!item.near || !item.width) {
        if (item.drawn) {
            canvas.width = 0;
            canvas.height = 0;
            item.drawn = false;
        }
        return;
    }
    const dpr = window.devicePixelRatio || 1;
    // Resizing the canvas clears it and resets its state, so it always comes before the drawing settings.
    canvas.width = Math.ceil(item.width * dpr);
    canvas.height = Math.ceil(item.height * dpr);
    const ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);
    ctx.font = item.font;
    ctx.fillStyle = item.color;
    ctx.textBaseline = 'middle';
    item.lines.forEach((line, index) => {
        ctx.fillText(line, 0, (index + 0.5) * item.lineHeight);
    });
    item.drawn = true;
};

/**
 * Fit and draw again every item at its known width, for example once a web font has loaded.
 */
const refit = () => {
    items.forEach((item) => {
        if (item.width) {
            fit(item, item.width);
            draw(item);
        }
    });
};

/**
 * Follow width changes. Drawing waits for the next frame: changing the size of an observed element inside the
 * ResizeObserver callback would raise "ResizeObserver loop completed with undelivered notifications".
 *
 * @param {ResizeObserverEntry[]} entries Changed hosts.
 */
const onResize = (entries) => {
    const scheduled = pendingWidths !== null;
    pendingWidths = pendingWidths || new Map();
    entries.forEach((entry) => pendingWidths.set(entry.target, Math.floor(entry.contentRect.width)));
    if (scheduled) {
        return;
    }
    window.requestAnimationFrame(() => {
        const widths = pendingWidths;
        pendingWidths = null;
        widths.forEach((width, host) => {
            const item = itemsByHost.get(host);
            if (item && width > 0 && width !== item.width) {
                fit(item, width);
                draw(item);
            }
        });
    });
};

/**
 * Draw the items that come near the viewport and free the ones that leave it.
 *
 * @param {IntersectionObserverEntry[]} entries Changed hosts.
 */
const onView = (entries) => {
    entries.forEach((entry) => {
        const item = itemsByHost.get(entry.target);
        if (item && item.near !== entry.isIntersecting) {
            item.near = entry.isIntersecting;
            draw(item);
        }
    });
};

/**
 * Redraw once the web fonts used by the canvases are loaded. Asking for them also downloads a font that only the
 * canvases use, which the browser would otherwise never fetch. Nothing is redrawn when no font face was loaded.
 *
 * @param {string[]} fonts CSS font shorthands in use.
 */
const loadFonts = (fonts) => {
    if (!document.fonts) {
        return;
    }
    let missing;
    try {
        missing = Array.from(new Set(fonts)).filter((font) => !document.fonts.check(font));
    } catch (e) {
        document.fonts.ready.then(refit).catch(() => null);
        return;
    }
    if (missing.length) {
        Promise.all(missing.map((font) => document.fonts.load(font).catch(() => [])))
            .then((loaded) => loaded.some((faces) => faces.length) && refit())
            .catch(() => null);
    }
};

/**
 * Turn the plain-text statements, options and feedback into canvases inside closed shadow roots.
 *
 * Every read is done before every write, so the browser lays the page out twice in total instead of twice
 * per text.
 */
const secure = () => {
    const targets = Array.from(document.querySelectorAll(SELECTORS)).filter(isPlainText);
    if (!targets.length) {
        return;
    }

    // The innerText property leaves out text that is not visible, and the content is hidden until it is protected
    // (hook_callbacks.php). Nothing is painted during this synchronous block, so the text never shows.
    targets.forEach((element) => {
        element.style.visibility = 'visible';
    });
    const added = targets.map((element) => {
        const style = window.getComputedStyle(element);
        const fontSize = parseFloat(style.fontSize) || 16;
        return {
            element,
            text: element.innerText.trim(),
            font: `${style.fontStyle} ${style.fontWeight} ${fontSize}px ${style.fontFamily}`,
            lineHeight: parseFloat(style.lineHeight) || fontSize * 1.5,
            color: style.color,
            width: 0,
            near: false,
            drawn: false,
        };
    });
    targets.forEach((element) => element.style.removeProperty('visibility'));

    added.forEach((item) => {
        const element = item.element;
        item.host = document.createElement('div');
        item.host.className = 'qtext-secure-host';
        // Lets flex items shrink instead of being held open by the canvas width.
        element.classList.add('antiscraper-secure');
        // The answer label is shrink-to-fit by default, so its width would depend on the canvas it holds.
        // Making it take the rest of the row gives a width the observer can follow.
        const label = element.closest('[data-region="answer-label"]');
        if (label) {
            label.classList.add('antiscraper-secure-label');
        }
        item.canvas = document.createElement('canvas');
        // No height until the text is fitted, so the positions read below can only grow afterwards. The width keeps
        // the default size of a canvas, which is what a shrink-to-fit container would give it.
        item.canvas.style.cssText = 'display:block;width:300px;height:0';
        // A closed root is not reachable through host.shadowRoot from other scripts.
        item.host.attachShadow({mode: 'closed'}).appendChild(item.canvas);
        element.dataset.antiscraperDone = '1';
        element.replaceChildren(item.host);
        items.push(item);
        itemsByHost.set(item.host, item);
    });

    const rects = added.map((item) => item.host.getBoundingClientRect());
    const eager = added.length <= EAGER_LIMIT || !window.IntersectionObserver;
    const top = -window.innerHeight;
    const bottom = window.innerHeight;
    measurer = measurer || document.createElement('canvas').getContext('2d');
    added.forEach((item, index) => {
        const rect = rects[index];
        // Read while every canvas is 0 px high, so this includes whatever is on screen once they have their height.
        const onScreen = rect.bottom > top && rect.top < bottom;
        item.near = eager || onScreen;
        // A text in a hidden part of the page has no width yet; the resize observer draws it when it shows.
        if (rect.width >= 1) {
            fit(item, Math.floor(rect.width));
            if (onScreen) {
                draw(item);
            }
        }
    });
    if (eager) {
        // The rest are drawn just after the questions are shown, so they do not delay it.
        const rest = added.filter((item) => !item.drawn);
        window.requestAnimationFrame(() => setTimeout(() => rest.forEach(draw)));
    }

    if (window.ResizeObserver) {
        resizeObserver = resizeObserver || new ResizeObserver(onResize);
        added.forEach((item) => resizeObserver.observe(item.host));
    }
    if (!eager) {
        viewObserver = viewObserver || new IntersectionObserver(onView, {rootMargin: '100% 0px'});
        added.forEach((item) => viewObserver.observe(item.host));
    }
    loadFonts(added.map((item) => item.font));
};

/**
 * Protect every question and let it be shown.
 *
 * @param {Object} config Settings sent by rule.php.
 */
const ready = (config) => {
    document.querySelectorAll(QUESTION).forEach((que) => place(que, config));
    try {
        if (config.useCanvas) {
            secure();
        }
    } finally {
        // The early style of hook_callbacks.php keeps the content of each question hidden until this point.
        document.querySelectorAll('.que').forEach((que) => que.classList.add('antiscraper-ready'));
    }
};

/**
 * Start the first-paint protection.
 *
 * @param {Object} config Settings sent by rule.php.
 * @param {boolean} config.code Whether the identity code is shown.
 * @param {Object} config.codes Packed identity code of each question of the attempt, by question (slot) number.
 * @param {string} config.aitext Hidden notice text for each question, empty if disabled.
 * @param {boolean} config.useCanvas Whether to draw the question text on a canvas.
 */
export const init = (config) => {
    if (document.readyState !== 'loading') {
        ready(config);
        return;
    }
    const observer = window.MutationObserver ? watchParsing(config) : null;
    document.addEventListener('DOMContentLoaded', () => {
        if (observer) {
            observer.disconnect();
        }
        ready(config);
    }, {once: true});
};
