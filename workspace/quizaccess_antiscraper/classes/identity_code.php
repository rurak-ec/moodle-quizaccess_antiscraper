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
 * Signed code that identifies the viewer in every screenshot of a quiz page.
 *
 * It is drawn as a rectangular Data Matrix (8 x 32 modules, ISO/IEC 16022), which fits the height of a question header
 * where a square QR code would not. Each question of the attempt carries its own code: user, attempt, question (slot)
 * and a signature.
 *
 * @package    quizaccess_antiscraper
 * @copyright  2026 Rurak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_antiscraper;

/**
 * Builds, draws and verifies the code printed in the corner of each question.
 */
class identity_code {
    /** @var string Prefix of the codes of version 1.2.0 (QR), still accepted when reading. */
    private const LEGACY_PREFIX = 'AS';

    /**
     * Digit layouts as [user, attempt, question, check]. The short one has 18 digits; the long one (20 digits) is only
     * the fallback for very large ids. Both fit the 8 x 32 symbol (10 data codewords, two digits each). The question
     * is the slot of the attempt, 1 to 999; 0 means "no question" (a code for the page as a whole).
     */
    private const FORMATS = [[5, 6, 3, 4], [7, 7, 3, 3]];

    /**
     * Layouts of the codes issued before the question was added, as [user, attempt, check]: 14 digits from version
     * 1.3.1 to 1.7.x and 20 digits of version 1.3.0. They are still read, and have no question.
     */
    private const LEGACY_FORMATS = [[5, 6, 3], [7, 8, 5]];

    /** @var int Highest question (slot) number a code can carry. */
    private const MAX_SLOT = 999;

    /** @var int Quiet zone, in Data Matrix modules, around the symbol (the minimum the standard asks for). */
    private const QUIET = 1;

    /** @var int[] Rows and columns of the Data Matrix symbol. */
    private const SYMBOL = [8, 32];

    /**
     * Check number that stops a code from being forged for another user or another question.
     *
     * @param int $userid user id.
     * @param int $attemptid attempt id.
     * @param int|null $slot the question; null for the older layouts, which did not sign it.
     * @param int $digits how many digits to keep.
     * @return string
     */
    private static function check(int $userid, int $attemptid, ?int $slot, int $digits): string {
        $text = $slot === null ? "{$userid}|{$attemptid}" : "{$userid}|{$attemptid}|{$slot}";
        $hash = hash_hmac('sha256', $text, get_site_identifier());
        return str_pad((string) (hexdec(substr($hash, 0, 8)) % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Digits encoded in the symbol: user, attempt, question and check number.
     *
     * @param int $userid user id.
     * @param int $attemptid attempt id (0 when the page has no attempt).
     * @param int $slot the question of the attempt (1 to 999), 0 for a code that is not about one question.
     * @return string the digits (18, or 20 for very large ids), or an empty string if they do not fit.
     */
    public static function make_payload(int $userid, int $attemptid, int $slot = 0): string {
        if ($userid < 0 || $attemptid < 0) {
            return '';
        }
        if ($slot < 0 || $slot > self::MAX_SLOT) {
            $slot = 0;
        }
        foreach (self::FORMATS as [$userdigits, $attemptdigits, $slotdigits, $checkdigits]) {
            if ($userid < 10 ** $userdigits && $attemptid < 10 ** $attemptdigits) {
                return str_pad((string) $userid, $userdigits, '0', STR_PAD_LEFT) .
                    str_pad((string) $attemptid, $attemptdigits, '0', STR_PAD_LEFT) .
                    str_pad((string) $slot, $slotdigits, '0', STR_PAD_LEFT) .
                    self::check($userid, $attemptid, $slot, $checkdigits);
            }
        }
        return '';
    }

    /**
     * Check a code and extract the ids it carries.
     *
     * Accepts the digits of any layout (spaces allowed), the codes issued before the question was added and the
     * older AS<user>.<attempt>.<signature> format of the QR code.
     *
     * @param string $code the text read from a screenshot.
     * @return array|null ['userid' => int, 'attemptid' => int, 'slot' => int|null], or null if the code is not valid.
     *     The slot is 0 for a code that is not about one question, and null for the codes that never had one.
     */
    public static function parse(string $code): ?array {
        $code = trim($code);

        $digits = preg_replace('/[\s-]+/', '', $code);
        $layouts = [];
        foreach (self::FORMATS as $format) {
            $layouts[] = $format;
        }
        foreach (self::LEGACY_FORMATS as [$userdigits, $attemptdigits, $checkdigits]) {
            $layouts[] = [$userdigits, $attemptdigits, null, $checkdigits];
        }
        foreach ($layouts as [$userdigits, $attemptdigits, $slotdigits, $checkdigits]) {
            if (!preg_match('/^\d{' . ($userdigits + $attemptdigits + (int) $slotdigits + $checkdigits) . '}$/', $digits)) {
                continue;
            }
            $userid = (int) substr($digits, 0, $userdigits);
            $attemptid = (int) substr($digits, $userdigits, $attemptdigits);
            $slot = $slotdigits === null ? null : (int) substr($digits, $userdigits + $attemptdigits, $slotdigits);
            $check = substr($digits, $userdigits + $attemptdigits + (int) $slotdigits);
            // Two layouts have 20 digits: the one that does not match is skipped, not rejected.
            if (hash_equals(self::check($userid, $attemptid, $slot, $checkdigits), $check)) {
                return ['userid' => $userid, 'attemptid' => $attemptid, 'slot' => $slot];
            }
        }

        if (preg_match('/^' . self::LEGACY_PREFIX . '(\d+)\.(\d+)\.([0-9a-f]{8})$/', $code, $m)) {
            $userid = (int) $m[1];
            $attemptid = (int) $m[2];
            $hash = hash_hmac('sha256', "{$userid}|{$attemptid}", get_site_identifier());
            if (!hash_equals(substr($hash, 0, 8), $m[3])) {
                return null;
            }
            return ['userid' => $userid, 'attemptid' => $attemptid, 'slot' => null];
        }

        return null;
    }

    /**
     * The 8 x 32 Data Matrix of a payload, as rows of 0/1 modules.
     *
     * TCPDF has the rectangular symbols in its table but never picks them and lists the 8 x 32 with its regions
     * the wrong way round (1 across and 2 down, which would make a 16 x 16 square). This asks for that one
     * symbol, with the regions the right way, and leaves the encoding, the padding and the error correction to
     * TCPDF.
     *
     * @param string $payload the digits to encode.
     * @return int[][]|null the modules, or null if TCPDF did not produce an 8 x 32 symbol.
     */
    private static function make_modules(string $payload): ?array {
        global $CFG;

        require_once($CFG->libdir . '/tcpdf/include/barcodes/datamatrix.php');

        $symbol = new class ($payload) extends \Datamatrix {
            /**
             * Only the 8 x 32 symbol: size, data region, regions across and down, codewords and blocks.
             *
             * @param string $code the digits to encode.
             */
            public function __construct($code) {
                $this->symbattr = [[8, 32, 6, 28, 8, 16, 6, 14, 2, 1, 2, 10, 11, 1, 10, 11]];
                parent::__construct($code);
            }

            /**
             * TCPDF numbers the pad codewords from 0 and the standard (ISO/IEC 16022, 5.2.3) from 1. Readers stop
             * at the first pad, so both decode, but only this one is the same symbol any other encoder makes.
             *
             * @param int $cwpad pad codeword.
             * @param int $cwpos position of the codeword, counting from 0.
             * @return int the randomised pad codeword.
             */
            protected function get253statecodeword($cwpad, $cwpos) {
                return parent::get253StateCodeword($cwpad, $cwpos + 1);
            }

            /**
             * The placement of the bits of this symbol never changes, and TCPDF works it out again for every code
             * (about 130 microseconds, and a page of a question bank needs hundreds of codes).
             *
             * @param int $nrow rows of the data area.
             * @param int $ncol columns of the data area.
             * @return array the placement map.
             */
            protected function getplacementmap($nrow, $ncol) {
                static $maps = [];
                return $maps["{$nrow}x{$ncol}"] ??= parent::getPlacementMap($nrow, $ncol);
            }

            /**
             * Reed-Solomon error correction of one block, as in TCPDF but with the field tables and the generator
             * polynomial kept between calls instead of being built again (about 120 microseconds each time).
             * Other layouts than the one block of this symbol are left to TCPDF.
             *
             * @param array $wd the data codewords (padded to the capacity of the symbol).
             * @param int $nb number of blocks.
             * @param int $nd data codewords per block.
             * @param int $nc error codewords per block.
             * @param int $gf size of the field.
             * @param int $pp prime modulus polynomial.
             * @return array the data codewords followed by the error codewords.
             */
            protected function geterrorcorrection($wd, $nb, $nd, $nc, $gf = 256, $pp = 301) {
                if ($nb !== 1 || $gf !== 256 || $pp !== 301) {
                    return parent::getErrorCorrection($wd, $nb, $nd, $nc, $gf, $pp);
                }

                static $tables = [];
                if (!isset($tables[$nc])) {
                    $log = [0 => 0];
                    $alog = [1];
                    for ($i = 1; $i < 256; ++$i) {
                        $alog[$i] = $alog[$i - 1] * 2;
                        if ($alog[$i] >= 256) {
                            $alog[$i] ^= 301;
                        }
                        $log[$alog[$i]] = $i;
                    }
                    $product = fn($a, $b) => ($a == 0 || $b == 0) ? 0 : $alog[($log[$a] + $log[$b]) % 255];
                    $c = array_fill(0, $nc + 1, 0);
                    $c[0] = 1;
                    for ($i = 1; $i <= $nc; ++$i) {
                        $c[$i] = $c[$i - 1];
                        for ($j = $i - 1; $j >= 1; --$j) {
                            $c[$j] = $c[$j - 1] ^ $product($c[$j], $alog[$i]);
                        }
                        $c[0] = $product($c[0], $alog[$i]);
                    }
                    $tables[$nc] = [$log, $alog, $c];
                }
                [$log, $alog, $c] = $tables[$nc];

                $we = array_fill(0, $nc + 1, 0);
                for ($i = 0; $i < $nd; ++$i) {
                    $k = $we[0] ^ $wd[$i];
                    for ($j = 0; $j < $nc; ++$j) {
                        $coef = $c[$nc - $j - 1];
                        $we[$j] = $we[$j + 1] ^ (($k == 0 || $coef == 0) ? 0 : $alog[($log[$k] + $log[$coef]) % 255]);
                    }
                }
                for ($j = 0; $j < $nc; ++$j) {
                    $wd[$nd + $j] = $we[$j];
                }
                ksort($wd);
                return $wd;
            }
        };

        $array = $symbol->getBarcodeArray();
        $numrows = $array['num_rows'] ?? 0;
        $numcols = $array['num_cols'] ?? 0;
        $bcodecount = count($array['bcode'] ?? []);
        if ($numrows !== self::SYMBOL[0] || $numcols !== self::SYMBOL[1] || $bcodecount !== self::SYMBOL[0]) {
            return null;
        }
        return $array['bcode'];
    }

    /**
     * Small SVG with the Data Matrix of the page as a whole (no question). The digits are not written next to it.
     *
     * The questions of an attempt get their own code in the browser, from {@see make_slot_codes()}; this one is what
     * a question shows when its number is not known.
     *
     * @param int $userid user id.
     * @param int $attemptid attempt id.
     * @return string the SVG markup, or an empty string if no code can be made.
     */
    public static function make_svg(int $userid, int $attemptid): string {
        $payload = self::make_payload($userid, $attemptid, 0);
        if ($payload === '') {
            debugging('quizaccess_antiscraper: user or attempt id too large for the identity code', DEBUG_DEVELOPER);
            return '';
        }

        $modules = self::make_modules($payload);
        if ($modules === null) {
            debugging('quizaccess_antiscraper: the Data Matrix of the identity code could not be made', DEBUG_DEVELOPER);
            return '';
        }

        // One unit is one module; the quiet zone around the symbol is what readers need to find it.
        $width = self::SYMBOL[1] + 2 * self::QUIET;
        $height = self::SYMBOL[0] + 2 * self::QUIET;

        // One rectangle per run of dark modules in a row.
        $path = '';
        foreach ($modules as $y => $row) {
            $x = 0;
            while ($x < self::SYMBOL[1]) {
                if (empty($row[$x])) {
                    $x++;
                    continue;
                }
                $start = $x;
                while ($x < self::SYMBOL[1] && !empty($row[$x])) {
                    $x++;
                }
                $dx = $x - $start;
                $path .= 'M' . ($start + self::QUIET) . ' ' . ($y + self::QUIET) . 'h' . $dx . 'v1h-' . $dx . 'z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '">' .
            '<rect width="' . $width . '" height="' . $height . '" rx="1" fill="#fff" fill-opacity="0.92"/>' .
            '<path shape-rendering="crispEdges" d="' . $path . '" fill="#111"/></svg>';
    }

    /**
     * The modules of the Data Matrix of one question, packed for the browser: 8 rows of 32 bits, most significant
     * bit first, 32 bytes in base64. The browser draws the same image as {@see make_svg()} from it.
     *
     * @param int $userid user id.
     * @param int $attemptid attempt id.
     * @param int $slot the question of the attempt.
     * @return string the packed modules, or an empty string if no code can be made.
     */
    public static function make_code_data(int $userid, int $attemptid, int $slot): string {
        $payload = self::make_payload($userid, $attemptid, $slot);
        $modules = $payload === '' ? null : self::make_modules($payload);
        if ($modules === null) {
            return '';
        }
        $bytes = array_fill(0, 32, 0);
        foreach ($modules as $row => $values) {
            for ($col = 0; $col < self::SYMBOL[1]; $col++) {
                if (!empty($values[$col])) {
                    $bytes[$row * 4 + ($col >> 3)] |= 0x80 >> ($col & 7);
                }
            }
        }
        return base64_encode(pack('C*', ...$bytes));
    }

    /**
     * The code of every question of an attempt, so that each question of the page shows a different one.
     *
     * The slots come from the layout stored with the attempt (one query by primary key). Every slot is included, not
     * only the ones of the page: review pages show more than one page of questions and the cost is small (about
     * 44 characters a question).
     *
     * @param int $userid user id of the viewer.
     * @param int $attemptid attempt id, 0 when the page has no attempt.
     * @return string[] question (slot) => packed modules; empty if the attempt is unknown.
     */
    public static function make_slot_codes(int $userid, int $attemptid): array {
        global $DB;

        $layout = $attemptid > 0 ? $DB->get_field('quiz_attempts', 'layout', ['id' => $attemptid]) : false;
        if (!$layout) {
            return [];
        }

        $codes = [];
        foreach (explode(',', (string) $layout) as $value) {
            $slot = (int) $value;
            if ($slot < 1 || $slot > self::MAX_SLOT || isset($codes[$slot])) {
                continue;
            }
            $data = self::make_code_data($userid, $attemptid, $slot);
            if ($data !== '') {
                $codes[$slot] = $data;
            }
        }
        return $codes;
    }
}
