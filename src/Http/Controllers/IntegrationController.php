<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Controllers;

use Emeq\HubSdk\Contracts\ResolvesAccountDisplayName;
use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Exceptions\HubException;
use Emeq\HubSdk\Exceptions\MissingConfigurationException;
use Emeq\HubSdk\Facades\Hub;
use Emeq\HubSdk\Support\OAuthReturnUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Thin Hub BFF: status list + hosted connect handoff.
 *
 * Connect / disconnect live on Hub's `/connect/{account}` page — consumers
 * mint a session URL and send the user there (single source of truth).
 * Account id is always server-side via ResolvesAccountId — never from the request.
 *
 * @internal Package HTTP surface — prefer Facades\Hub / Resources from app code.
 */
class IntegrationController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            // Guard only: the catalog is account-optional, but this endpoint is
            // not usable at all without a bound resolver.
            $this->assertAccountResolverBound();

            return response()->json(Hub::integrations()->list());
        } catch (HubException $e) {
            return $this->hubError($e);
        }
    }

    /**
     * Mint Hub's hosted connect handoff page URL.
     */
    public function connectSession(Request $request): JsonResponse
    {
        try {
            $externalId = $this->accountId();
            $returnUrl = OAuthReturnUrl::fromConfigPath(
                $request,
                (string) config('hub.oauth.return_path', ''),
            );
            $displayName = app()->bound(ResolvesAccountDisplayName::class)
                ? app(ResolvesAccountDisplayName::class)->displayName()
                : null;

            $session = Hub::connectSessions()->create(
                accountExternalId: $externalId,
                displayName: $displayName,
                returnUrl: $returnUrl,
            );

            return response()->json([
                'url' => $session['url'] ?? null,
                'expires_at' => $session['expires_at'] ?? null,
            ]);
        } catch (HubException $e) {
            return $this->hubError($e);
        }
    }

    private function accountId(): string
    {
        $this->assertAccountResolverBound();

        return app(ResolvesAccountId::class)->accountId();
    }

    private function assertAccountResolverBound(): void
    {
        if (! app()->bound(ResolvesAccountId::class)) {
            throw MissingConfigurationException::missingAccountResolver();
        }
    }

    private function hubError(HubException $e): JsonResponse
    {
        Log::warning('Hub API error', [
            'request_id' => $e->requestId,
            'error' => $e->error,
            'status' => $e->status,
            'message' => $e->getMessage(),
        ]);

        return response()->json([
            'message' => $e->getMessage(),
            'error' => $e->error,
            'request_id' => $e->requestId,
        ], $e->status ?? 502);
    }
}
