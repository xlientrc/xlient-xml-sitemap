<?php
namespace Xlient\Xml\Sitemap;

use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Iterator;
use XmlReader;

/**
 * SitemapIterator provides a way to traverse over an xml sitemap's urls
 * in a foreach loop.
 */
class SitemapIterator implements Iterator
{
    /**
     * @var string A uri pointing to a sitemap.
     */
    private string $uri;

    /**
     * @var array An array of options.
     */
    private array $options;

    /**
     * @var XmlReader[] An array of XmlReaders.
     */
    private array $xml;

    /**
     * @var int|null The index of the active XmlReader.
     */
    private ?int $index = null;

    /**
     * @var bool Is the current iterator item valid.
     */
    private bool $valid = false;

    /**
     * @var string|null The current sitemap url.
     */
    private ?string $currentUrl = null;

    /**
     * @var array|null The current sitemap url data.
     */
    private ?array $currentData = null;

    /**
     * @var bool $rewind Is a full rewind required.
     */
    private bool $rewind = false;

    /**
     * Opens the specified url for iteration.
     *
     * The $options array can contain the following keys:
     *
     * - `modified_date_time`   - Skip over urls that have not been modified
     *                            since this date.
     * - `minimum_priority`     - Skip over urls that have a priority lower
     *                            than this value.
     * - `encoding`             - Specify the encoding of the sitemap
     *
     * @param string $uri A uri pointing to a sitemap.
     * @param array $options An array of options.
     * @return bool Returns true on success or false on failure.
     */
    public function open(string $uri, array $options = []): bool
    {
        $this->uri = $uri;

        // Ensure default options are set
        $this->options = array_merge(
            [
                'modified_date_time' => null,
                'minimum_priority' => null,
                'encoding' => null,
            ],
            $options
        );

        // Normalize modified date time to UTC comparable string
        $dateTime = $this->options['modified_date_time'];
        if ($dateTime) {
            if ($dateTime instanceof DateTimeInterface) {
                $dateTime = DateTime::createFromInterface($dateTime);
            } else {
                $dateTime = new DateTime($dateTime);
            }

            $dateTime->setTimezone(new DateTimeZone(DateTimeZone::UTC));
            $dateTime = $dateTime->format('Y-m-d H:i:s');

            $this->options['modified_date_time'] = $dateTime;
        }

        $this->xml = [];

        return $this->openXmlReader($this->uri);
    }

    /**
     * Closes all opened XmlReaders on the stack.
     */
    public function close(): bool
    {
        while ($this->xml) {
            if (!$this->closeXmlReader()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function current(): mixed
    {
        return $this->currentData;
    }

    /**
     * @inheritdoc
     */
    public function key(): mixed
    {
        return $this->currentUrl;
    }

    /**
     * @inheritdoc
     */
    public function next(): void
    {
        $this->readNext();
    }

    /**
     * @inheritdoc
     */
    public function rewind(): void
    {
        if ($this->rewind) {
            $this->close();

            $this->openXmlReader($this->uri);
        } else {
            $this->rewind = true;
        }

        $this->readNext();
    }

    /**
     * @inheritdoc
     */
    public function valid(): bool
    {
        return $this->valid;
    }

    /**
     * Opens the specified uri in an XmlReader and pushes to the stack.
     *
     * @param string $uri The uri of the sitemap to open.
     * @return bool Returns true on success or false on failure.
     */
    private function openXmlReader(string $uri): bool
    {
        $this->valid = true;

        libxml_use_internal_errors(true);

        $xml = XmlReader::open(
            $uri,
            $this->options['encoding'],
            LIBXML_NOEMPTYTAG
        );

        if (!$xml) {
            return false;
        }

        array_push($this->xml, $xml);

        $this->index = count($this->xml) - 1;

        return true;
    }
    /**
     * Closes the last opened XmlReader and pops it off the stack.
     *
     * @return bool Returns true on success or false on failure.
     */
    private function closeXmlReader(): bool
    {
        if (!$this->xml) {
            return false;
        }

        $xml = array_pop($this->xml);

        $result = $xml->close();

        $this->index = count($this->xml) - 1;

        if ($this->index < 0) {
            $this->index = null;
        }

        return $result;
    }

    /**
     *  Reads the xml sitemap until the next url is found or the end of
     *  the sitemap is reached.
     */
    private function readNext(): void
    {
        $xml = $this->xml[$this->index];

        if (!$xml->read()) {
            $this->assertIsValid();

            $this->closeXmlReader();

            if ($this->xml) {
                $this->readNext();
                return;
            }

            $this->valid = false;
            return;
        }

        $this->assertIsValid();

        if ($xml->name === 'sitemapindex') {
            $this->readNext();
            return;
        }

        if ($xml->name === 'sitemap') {
            $this->readSitemap($xml);
            $this->readNext();
            return;
        }

        if ($xml->name === 'urlset') {
            $this->readNext();
            return;
        }

        if ($xml->name === 'url') {
            if (!$this->readUrl($xml)) {
                $this->readNext();
            }

            return;
        }

        $this->readNext();
    }

    /**
     * Reads a sitemap element and opens the loc in a new XmlReader.
     *
     * @param XmlReader $xml The XmlReader to read the sitemap element from.
     */
    private function readSitemap(XmlReader $xml): void
    {
        if ($xml->nodeType === XmlReader::END_ELEMENT) {
            $this->closeXmlReader();
            return;
        }

        $url = null;
        $lastmod = null;

        while (true) {
            $xml->read();

            $this->assertIsValid();

            if ($xml->name === 'sitemap' &&
                $xml->nodeType === XmlReader::END_ELEMENT
            ) {
                break;
            }

            if ($xml->name === 'loc' &&
                $xml->nodeType === XmlReader::ELEMENT
            ) {
                $url = $this->readElementValue($xml);
                continue;
            }

            if ($xml->name === 'lastmod' &&
                $xml->nodeType === XmlReader::ELEMENT
            ) {
                $lastmod = $this->readElementValue($xml);
            }

            // Skip over any other elements contained in the sitemap element
            if ($xml->nodeType === XmlReader::ELEMENT) {
                $xml->next();
            }
        }

        if ($url === null || $this->skipLastmod($lastmod)) {
            return;
        }

        $this->openXmlReader($url);
    }

    /**
     * Reads a url element and sets the current key value pair to its loc and
     * and array of child values.
     *
     * @param XmlReader $xml The XmlReader to read the url element from.
     * @return bool Returns true if the key value pair is updated,
     * otherwise false.
     */
    private function readUrl(XmlReader $xml): bool
    {
        if ($xml->nodeType === XmlReader::END_ELEMENT) {
            return false;
        }

        $url = null;
        $lastmod = null;
        $priority = null;
        $data = [];

        while (true) {
            $xml->read();

            $this->assertIsValid();

            if ($xml->name === 'url' &&
                $xml->nodeType === XmlReader::END_ELEMENT
            ) {
                break;
            }

            if ($xml->name === 'loc' &&
                $xml->nodeType === XmlReader::ELEMENT
            ) {
                $url = $this->readElementValue($xml);
                continue;
            }

            if ($xml->name === 'lastmod' &&
                $xml->nodeType === XmlReader::ELEMENT
            ) {
                $lastmod = $this->readElementValue($xml);
                continue;
            }

            if ($xml->name === 'priority' &&
                $xml->nodeType === XmlReader::ELEMENT
            ) {
                $priority = $this->readElementValue($xml);
                continue;
            }

            if ($xml->nodeType === XmlReader::ELEMENT) {
                $name = $xml->name;
                $value = $this->readElementValue($xml);
                $data[$name] = $value;
            }
        }

        if ($priority !== null) {
            $priority = floatval($priority);
        }

        if ($url === null ||
            $this->skipPriority($lastmod) ||
            $this->skipLastmod($lastmod)
        ) {
            return false;
        }

        $data['priority'] = $priority;

        if ($lastmod !== null) {
            $dateTime = new Datetime($lastmod);
            $dateTime->setTimezone(new DateTimeZone(date_default_timezone_get()));
            $data['lastmod'] = $dateTime;
        } else {
            $data['lastmod'] = null;
        }

        if (!array_key_exists('changefreq', $data)) {
            $data['changefreq'] = null;
        }

        $this->currentUrl = $url;
        $this->currentData = $data;

        return true;
    }

    /**
     * Read an element and its subtrees into an value.
     *
     * @param XmlReader $xml The XmlReader to read the value from.
     * @return string|array|null Returns teh resulting read value.
     */
    private function readElementValue(XmlReader $xml): string|array|null
    {
        $value = null;
        $data = [];

        $depth = $xml->depth;

        while ($xml->read()) {
            $this->assertIsValid();

            if ($xml->nodeType === XmlReader::ELEMENT) {
                $name = $xml->name;
                $value = $this->readElementValue($xml);
                $data[$name] = $value;
                continue;
            }

            // If closing then no value
            if ($xml->nodeType === XmlReader::END_ELEMENT) {
                if ($xml->depth === $depth) {
                    break;
                }

                continue;
            }

            $value = $xml->value;

            if ($value === '') {
                $value = null;
            }
        }

        if ($data) {
            return $data;
        }

        return $value;
    }

    /**
     * Determine whether or not the url with the specified priority should
     * be skipped.
     *
     * @return Returns true if the url should be skipped, otherwise false.
     */
    private function skipPriority($priority): bool
    {
        if ($priority === null) {
            return false;
        }

        if ($this->options['minimum_priority'] === null) {
            return false;
        }

        if ($priority >= $this->options['minimum_priority']) {
            return false;
        }

        return true;
    }
    /**
     * Determines if the element with the specified lastmod should be skipped.
     *
     * @return Returns true if the element should be skipped, otherwise false.
     */
    private function skipLastmod($lastmod): bool
    {
        if ($lastmod === null) {
            return false;
        }

        if ($this->options['modified_date_time'] === null) {
            return false;
        }

        $date = new Datetime($lastmod);
        $date->setTimezone(new DateTimeZone(DateTimeZone::UTC));

        if ($date->format('Y-m-d H:i:s') <= $this->options['modified_date_time']) {
            return false;
        }

        return true;
    }

    /**
     * Asserts that the last read of the XmlReader has not produced an error.
     *
     * @throw InvalidSitemapException
     */
    private function assertIsValid(): void
    {
        $error = libxml_get_last_error();

        if ($error) {
            throw new InvalidSitemapException($error->message, $error->code);
        }
    }
}

// ✝
