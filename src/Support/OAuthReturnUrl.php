<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Support;

use Emeq\HubSdk\Exceptions\MissingConfigurationException;

/**
 * Builds Hub OAuth return_url from a consumer-configured relative path.
 * Never accepts absolute or protocol-relative URLs from config.
 *
 * A bad value is a deployment mistake, not caller input — it raises
 * MissingConfigurationException (503), never a 422 the API caller cannot act on.
 */
final class OAuthReturnUrl
{
    /**
     * @param  string  $origin  scheme + host of the consumer app, e.g. `Request::getSchemeAndHttpHost()`
     */
    public static function fromConfigPath(string $origin, string $returnPath): ?string
    {
        $returnPath = trim($returnPath);

        if ($returnPath === '') {
            return null;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $returnPath) === 1
            || str_starts_with($returnPath, '//')
            || str_contains($returnPath, '\\')
        ) {
            throw MissingConfigurationException::invalidOAuthReturnPath();
        }

        // Safe to prepend: a value starting with `//` already threw above, and
        // one not starting with `/` cannot become `//` by gaining a single slash.
        if (! str_starts_with($returnPath, '/')) {
            $returnPath = '/'.$returnPath;
        }

        return $origin.$returnPath;
    }
}
