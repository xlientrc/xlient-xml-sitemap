<?php
namespace Xlient\Xml\Sitemap;

use RuntimeException;

/**
 * An error thrown when reading an xml sitemap produces an error.
 */
class InvalidSitemapException extends RuntimeException
{
    /**
     * @return string A user-friendly name of this exception.
     */
    public function getName(): string
    {
        return 'Invalid Sitemap';
    }
}

// ✝
