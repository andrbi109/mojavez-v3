<?php
declare(strict_types=1);

namespace App\Shared\Support;

use RuntimeException;

/**
 * CSV reader with BOM handling.
 *
 * - Strips UTF-8 BOM (\xEF\xBB\xBF) from the start of the file or any row.
 * - Reads rows as associative arrays using the header row.
 * - Streams large files line-by-line (no full in-memory load).
 * - Honors PHP 8.4+ deprecation of str_getcsv by using SplFileObject::fgetcsv
 *   (the gateway used str_getcsv which is deprecated on PHP 8.4+; this is
 *   the forward-looking replacement — see docs/00-MASTER.md §20.3).
 *
 * Inherited from gateway's CsvReader, hardened for v3.
 */
final class CsvReader
{
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
        $file = new \SplFileObject($path, 'r');
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::READ_AHEAD | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);

        // Read & strip BOM from the header row.
        $header = $file->fgetcsv();
        if ($header === null) {
            return;
        }
        $header = $this->stripBomFromRow($header);
        if ($header === []) {
            return;
        }

        $lineNo = 1;
        while (!$file->eof()) {
            $row = $file->fgetcsv();
            if ($row === null || $row === [null] || $row === []) {
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
     * @param array<int,?string> $row
     * @return array<int,string>
     */
    private function stripBomFromRow(array $row): array
    {
        if ($row === []) {
            return [];
        }
        $first = $row[0] ?? '';
        if ($first !== '' && strlen($first) >= 3 && substr($first, 0, 3) === "\xEF\xBB\xBF") {
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
