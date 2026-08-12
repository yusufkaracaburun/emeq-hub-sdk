<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Controllers;

use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Exceptions\HubException;
use Emeq\HubSdk\Exceptions\MissingConfigurationException;
use Emeq\HubSdk\Hub;
use Emeq\HubSdk\Support\OAuthReturnUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Config;
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
    /**
     * The resolver is a consumer binding and may be absent; the container
     * injects null for an unbound interface that has a default.
     */
    public function __construct(
        private readonly Hub $hub,
        private readonly ?ResolvesAccountId $accountIdResolver = null,
    ) {}

    public function index(): JsonResponse
    {
        try {
            // Guard only: the catalog is account-optional, but this endpoint is
            // not usable at all without a bound resolver.
            $this->assertAccountResolverBound();

            return response()->json($this->hub->integrations()->list());
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
                $this->returnPath(),
            );
            $session = $this->hub->connectSessions()->create(
                accountExternalId: $externalId,
                displayName: $this->accountIdResolver->displayName(),
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

    private function returnPath(): string
    {
        $path = Config::get('hub.oauth.return_path', '');

        return is_string($path) ? $path : '';
    }

    /**
     * @phpstan-assert !null $this->accountIdResolver
     */
    private function accountId(): string
    {
        $this->assertAccountResolverBound();

        return $this->accountIdResolver->accountId();
    }

    /**
     * @phpstan-assert !null $this->accountIdResolver
     */
    private function assertAccountResolverBound(): void
    {
        if ($this->accountIdResolver === null) {
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
