<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Support;

use Emeq\HubSdk\Exceptions\ValidationException;
use Illuminate\Http\Request;

/**
 * Builds Hub OAuth return_url from a consumer-configured relative path.
 * Never accepts absolute or protocol-relative URLs from config.
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
            throw new ValidationException(
                'hub.oauth.return_path must be a relative path starting with / (no scheme or //).',
                error: 'invalid_return_path',
                category: 'VALIDATION_ERROR',
                status: 422,
            );
        }

        if (! str_starts_with($returnPath, '/')) {
            $returnPath = '/'.$returnPath;
        }

        if (str_starts_with($returnPath, '//')) {
            throw new ValidationException(
                'hub.oauth.return_path must be a relative path starting with / (no scheme or //).',
                error: 'invalid_return_path',
                category: 'VALIDATION_ERROR',
                status: 422,
            );
        }

        return $request->getSchemeAndHttpHost().$returnPath;
    }
}
