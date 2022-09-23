# Xlient XML Sitemap Iterator
An XML sitemap iterator that lets you traverse all urls in a sitemap written in PHP using XmlReader.  Supports sitemap indexes and url sets.

## Example

```php
$sitemapIterator = new \Xlient\Xml\Sitemap\SitemapIterator();
$sitemapIterator->open(
    __DIR__ . '/sitemap.xml',
    [
        // Skip over urls that have not been modified since this date.
        'modified_date_time' => null, // DateTime|string
        // Skip over urls that have a priority lower than this value.
        'minimum_priority' => null, // float
        // Specify the encoding of the sitemap.
        'encoding' => null, // String
    ]
);

foreach ($sitemapIterator as $url => $data) {
    ...
}

$sitemapIterator->close();
```
