<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Support;

use Emeq\HubSdk\Exceptions\MissingConfigurationException;

final class OAuthReturnUrl
{
    /** @param  string  $origin  scheme + host of the consumer app, e.g. `Request::getSchemeAndHttpHost()` */
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

        if (! str_starts_with($returnPath, '/')) {
            $returnPath = '/'.$returnPath;
        }

        return $origin.$returnPath;
    }
}
