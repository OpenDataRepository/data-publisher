<?php

/**
 * Open Data Repository Data Publisher
 * ODRCsvWriter
 *
 * Native (fputcsv-backed) drop-in replacement for Ddeboer\DataImport\Writer\CsvWriter (abandoned;
 * see ODRCsvReader). Reproduces the subset ODR uses: construct with delimiter/enclosure, point it
 * at a stream via setStream(), and writeItem() a row. Imported `as CsvWriter` so call sites are
 * unchanged.
 */

namespace ODR\AdminBundle\Component\Utility;

class ODRCsvWriter
{
    /** @var resource|null */
    private $stream;

    /** @var string */
    private $delimiter;

    /** @var string */
    private $enclosure;

    public function __construct($delimiter = ',', $enclosure = '"', $stream = null)
    {
        $this->delimiter = $delimiter;
        $this->enclosure = $enclosure;
        $this->stream = $stream;
    }

    /**
     * @param resource $stream
     * @return self
     */
    public function setStream($stream)
    {
        $this->stream = $stream;
        return $this;
    }

    /**
     * @return resource|null
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * @param array $item
     */
    public function writeItem(array $item)
    {
        fputcsv($this->stream, $item, $this->delimiter, $this->enclosure);
    }
}
