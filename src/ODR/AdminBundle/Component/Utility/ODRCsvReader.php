<?php

/**
 * Open Data Repository Data Publisher
 * ODRCsvReader
 *
 * Native (SplFileObject-backed) drop-in replacement for Ddeboer\DataImport\Reader\CsvReader, which
 * was abandoned and pinned psr/log ~1.0 (incompatible with monolog 3 / Symfony 7). This faithfully
 * reproduces the subset of ddeboer's behaviour ODR relies on:
 *   - construct from an \SplFileObject + delimiter (same CSV flags + control)
 *   - setHeaderRowNumber()/getColumnHeaders()
 *   - iteration yields associative rows (header => value), with the header row skipped
 *   - strict parsing: rows whose column count != header count are recorded in getErrors()
 *     (keyed by line number) and skipped from iteration
 * so existing call sites work unchanged (imported `as CsvReader`).
 */

namespace ODR\AdminBundle\Component\Utility;

class ODRCsvReader implements \Iterator, \Countable
{
    /** @var \SplFileObject */
    private $file;

    /** @var int|null */
    private $headerRowNumber = null;

    /** @var array */
    private $columnHeaders = [];

    /** @var int */
    private $headersCount = 0;

    /** @var array rows with a column-count mismatch, keyed by line number */
    private $errors = [];

    public function __construct(\SplFileObject $file, $delimiter = ',', $enclosure = '"', $escape = '\\')
    {
        $this->file = $file;
        $this->file->setFlags(
            \SplFileObject::READ_CSV |
            \SplFileObject::SKIP_EMPTY |
            \SplFileObject::READ_AHEAD |
            \SplFileObject::DROP_NEW_LINE
        );
        $this->file->setCsvControl($delimiter, $enclosure, $escape);
    }

    /**
     * @param int $rowNumber
     */
    public function setHeaderRowNumber($rowNumber)
    {
        $this->headerRowNumber = $rowNumber;
        $this->file->seek($rowNumber);
        $headers = $this->file->current();
        $this->columnHeaders = is_array($headers) ? $headers : [];
        $this->headersCount = count($this->columnHeaders);
    }

    /**
     * @return array
     */
    public function getColumnHeaders()
    {
        return $this->columnHeaders;
    }

    /**
     * Rows that had an invalid number of columns (keyed by line number). Forces a full pass if the
     * reader hasn't been iterated yet, mirroring ddeboer.
     *
     * @return array
     */
    public function getErrors()
    {
        $key = $this->file->key();
        if (0 === $key || null === $key) {
            foreach ($this as $row) { /* noop: drive the iterator so $errors fills in */ }
        }

        return $this->errors;
    }

    #[\ReturnTypeWillChange]
    public function rewind()
    {
        $this->file->rewind();
        if (null !== $this->headerRowNumber) {
            $this->file->seek($this->headerRowNumber + 1);
        }
    }

    #[\ReturnTypeWillChange]
    public function current()
    {
        // No header set -> return the raw line
        if (empty($this->columnHeaders)) {
            return $this->file->current();
        }

        do {
            $line = $this->file->current();

            if (is_array($line) && count($this->columnHeaders) === count($line)) {
                return array_combine($this->columnHeaders, $line);
            }

            // Column-count mismatch: record it and skip to the next line.
            if ($this->file->valid()) {
                $this->errors[$this->file->key()] = $line;
                $this->file->next();
            }
        } while ($this->file->valid());

        return null;
    }

    #[\ReturnTypeWillChange]
    public function next()
    {
        $this->file->next();
    }

    #[\ReturnTypeWillChange]
    public function valid()
    {
        return $this->file->valid();
    }

    #[\ReturnTypeWillChange]
    public function key()
    {
        return $this->file->key();
    }

    public function count(): int
    {
        $position = $this->file->key();
        $count = iterator_count($this);
        $this->file->seek($position);

        return $count;
    }
}
