<?php
declare(strict_types=1);

namespace App\Shared\Support;

use RuntimeException;

/**
 * CSV reader with BOM handling + delimiter auto-detection.
 *
 * - Strips UTF-8 BOM (\xEF\xBB\xBF) by fseek-ing past the first 3 bytes of
 *   the file before parsing (Phase 47 fix). The previous approach only
 *   stripped the BOM *after* fgetcsv() had parsed the row — but once the
 *   BOM sits in front of an opening double-quote, fgetcsv can no longer
 *   recognise the quote character, so the first cell becomes the literal
 *   `\"national_code\"` (with quotes) instead of `national_code`. That
 *   made BulkImportController::upload() reject v3-template CSVs (which
 *   are quoted + BOM-prefixed) with "ستون‌های الزامی وجود ندارند:
 *   national_code".
 * - Auto-detects the delimiter (comma vs semicolon) by counting
 *   occurrences of each in the first raw line and picking the more
 *   frequent one. Excel on Persian/European locales exports CSV with
 *   `;` as the delimiter by default, so the previous comma-only parser
 *   would collapse the entire header row into a single field and fail
 *   the column-detection check.
 * - Reads rows as associative arrays using the header row.
 * - Streams large files line-by-line (no full in-memory load).
 * - Honors PHP 8.4+ deprecation of str_getcsv by using SplFileObject::fgetcsv
 *   (the gateway used str_getcsv which is deprecated on PHP 8.4+; this is
 *   the forward-looking replacement — see docs/00-MASTER.md §20.3).
 *
 * Inherited from gateway's CsvReader, hardened for v3 + Phase 47.
 */
final class CsvReader
{
    /** UTF-8 BOM (3 bytes). */
    private const BOM_UTF8 = "\xEF\xBB\xBF";

    /**
     * Read a CSV file and yield rows as associative arrays using the header row.
     *
     * @return iterable<int, array<string,string>>
     */
    public function read(string $path): iterable
    {
        if (!is_file($path)) {
            throw new RuntimeException("CSV file not found: $path");
        }

        // Detect the delimiter (comma vs semicolon) from the first raw
        // line. Excel on Persian/European locales exports with `;` as the
        // delimiter by default — without this detection, the header row
        // parses as one giant field and BulkImportController::upload()
        // rejects the file with "ستون‌های الزامی وجود ندارند".
        $delimiter = $this->detectDelimiter($path);

        // Open the main SplFileObject and strip a leading UTF-8 BOM by
        // seeking past its 3 bytes BEFORE the flags are set. Doing the
        // seek before parsing (rather than stripping the BOM out of the
        // parsed first cell) is critical: once the BOM sits in front of
        // an opening double-quote, fgetcsv treats the quote as a literal
        // character and the first cell becomes the literal
        // `"national_code"` (with quotes) — the subsequent
        // array_diff(REQUIRED_COLUMNS, headers) then reports
        // `national_code` as missing. (Phase 47 fix.)
        //
        // The BOM check MUST happen before setFlags(READ_CSV | …): on
        // PHP 8.4+, fread()/fgets() on a READ_CSV-flagged SplFileObject
        // interact oddly with the SKIP_EMPTY + DROP_NEW_LINE flags when
        // the file starts with a BOM — they skip the BOM+header line
        // entirely and yield the second line instead.
        $file = new \SplFileObject($path, 'r');
        $this->seekPastBom($file);
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::READ_AHEAD | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
        // PHP 8.4: SplFileObject::fgetcsv() emits an E_DEPRECATED when the
        // $escape parameter is not explicitly set (the default is changing
        // from "\\" to "" in PHP 9). The front controller's error handler
        // converts E_DEPRECATED into ErrorException, so without this
        // explicit setCsvControl() the read() throws on the first row
        // and downstream callers (e.g. BulkImportController::upload)
        // catch it as a hard error. Set the control once with the
        // legacy default escape ("\\") so existing CSVs parse identically.
        $file->setCsvControl($delimiter, '"', '\\');

        // Read header row. fgetcsv() returns null, false, or [null]
        // (depending on PHP version + flags) for an empty file or a row
        // it can't parse. Treat all of those as "no header" so an empty
        // CSV yields zero rows instead of a TypeError.
        $header = $file->fgetcsv();
        if ($header === null || $header === false || $header === [null] || $header === []) {
            return;
        }
        // Safety net: in case the BOM somehow survived (e.g. it was
        // present mid-stream rather than at offset 0, or the file uses a
        // BOM we don't strip at the byte level), strip it from the first
        // cell here too.
        $header = $this->stripBomFromRow($header);
        if ($header === []) {
            return;
        }

        $lineNo = 1;
        while (!$file->eof()) {
            $row = $file->fgetcsv();
            if ($row === null || $row === false || $row === [null] || $row === []) {
                continue;
            }
            $lineNo++;
            // Strip BOM if it somehow survived into a body row.
            $row = $this->stripBomFromRow($row);
            if (count($row) !== count($header)) {
                // Skip ragged rows; caller can inspect via line counter.
                continue;
            }
            yield $lineNo => array_combine($header, $row);
        }
    }

    /**
     * Detect the CSV delimiter by reading the first raw line and counting
     * occurrences of `,` vs `;`. Returns whichever is more frequent; ties
     * and the "no delimiter found" case default to `,` (the canonical v3
     * contract per docs/00-MASTER.md §11 — see BulkImportController::REQUIRED_COLUMNS).
     *
     * A separate, plain SplFileObject (without the READ_CSV flag) is used
     * to read the first raw line, because fgets() on a READ_CSV-flagged
     * SplFileObject can interact oddly with the SKIP_EMPTY + DROP_NEW_LINE
     * flags when the file starts with a BOM (it may yield the second line
     * instead of the header line — observed on PHP 8.4.23).
     */
    private function detectDelimiter(string $path): string
    {
        try {
            $probe = new \SplFileObject($path, 'r');
            $firstLine = $probe->fgets();
            // Release the file handle promptly.
            $probe = null;
        } catch (\Throwable) {
            return ',';
        }
        if ($firstLine === false || $firstLine === '') {
            return ',';
        }

        // Strip UTF-8 BOM from the raw first line before counting
        // delimiters, so a leading BOM doesn't interfere with the
        // detection (it shouldn't, but be defensive).
        if (strlen($firstLine) >= 3 && substr($firstLine, 0, 3) === self::BOM_UTF8) {
            $firstLine = substr($firstLine, 3);
        }

        $commas = substr_count($firstLine, ',');
        $semis  = substr_count($firstLine, ';');

        // Semicolon wins only if it's strictly more frequent. Ties (e.g.
        // a header row that contains neither delimiter, or a header with
        // equal counts) default to comma — the canonical contract.
        return $semis > $commas ? ';' : ',';
    }

    /**
     * If the file starts with a UTF-8 BOM (3 bytes), seek the SplFileObject
     * past those bytes so the subsequent fgetcsv() reads the header row
     * cleanly without the BOM prefix. (Phase 47 fix — see class doc.)
     */
    private function seekPastBom(\SplFileObject $file): void
    {
        $file->rewind();
        try {
            $peek = $file->fread(3);
        } catch (\Throwable) {
            $peek = false;
        }
        if ($peek !== false && $peek === self::BOM_UTF8) {
            $file->fseek(3);
            return;
        }
        // No BOM (or read failed) — rewind to start so fgetcsv reads from
        // the very first byte.
        $file->rewind();
    }

    /**
     * @param array<int,?string> $row
     * @return array<int,string>
     */
    private function stripBomFromRow(array $row): array
    {
        if ($row === []) {
            return [];
        }
        $first = $row[0] ?? '';
        if ($first !== '' && strlen($first) >= 3 && substr($first, 0, 3) === self::BOM_UTF8) {
            $row[0] = substr($first, 3);
        }
        // Coerce nulls to empty strings.
        return array_map(static fn ($v) => $v ?? '', $row);
    }

    /**
     * Read the entire CSV into memory as an array of associative rows.
     *
     * @return array<int, array<string,string>>
     */
    public function readAll(string $path): array
    {
        return iterator_to_array($this->read($path), false);
    }
}
