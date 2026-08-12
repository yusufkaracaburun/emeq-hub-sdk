<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Support;

use Emeq\HubSdk\Exceptions\MissingConfigurationException;
use Illuminate\Http\Request;

/**
 * Builds Hub OAuth return_url from a consumer-configured relative path.
 * Never accepts absolute or protocol-relative URLs from config.
 *
 * A bad value is a deployment mistake, not caller input — it raises
 * MissingConfigurationException (503), never a 422 the API caller cannot act on.
 */
final class OAuthReturnUrl
{
    public static function fromConfigPath(Request $request, string $returnPath): ?string
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

        if (str_starts_with($returnPath, '//')) {
            throw MissingConfigurationException::invalidOAuthReturnPath();
        }

        return $request->getSchemeAndHttpHost().$returnPath;
    }
}
