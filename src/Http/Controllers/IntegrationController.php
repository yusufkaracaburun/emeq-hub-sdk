<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Controllers;

use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Contracts\ResolvesConnectSessionContext;
use Emeq\HubSdk\Exceptions\HubException;
use Emeq\HubSdk\Exceptions\MissingConfigurationException;
use Emeq\HubSdk\Exceptions\RateLimitException;
use Emeq\HubSdk\Hub;
use Emeq\HubSdk\Support\OAuthReturnUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class IntegrationController extends Controller
{
    public function __construct(
        private readonly Hub $hub,
        private readonly ?ResolvesAccountId $accountIdResolver = null,
        private readonly ?ResolvesConnectSessionContext $connectSessionContext = null,
    ) {}

    /**
     * List the integrations this account can connect.
     *
     * Answers `list<array<string, mixed>>`, the same shape `Integrations::list()`
     * declares. Item keys stay untyped on purpose: Hub's discovery payload is
     * data-driven per provider, and narrowing keys here would mean hard-coding
     * a schema ADR-0001 deliberately keeps out of the SDK.
     */
    public function index(): JsonResponse
    {
        try {
            $this->assertAccountResolverBound();

            return response()->json($this->hub->integrations()->list());
        } catch (HubException $e) {
            return $this->hubError($e);
        }
    }

    /**
     * Mint Hub's hosted connect handoff page URL.
     *
     * Deliberately reads no input from the request body or query: the account
     * comes from ResolvesAccountId, the return path from config, and categories,
     * mode and actor from ResolvesConnectSessionContext when bound. The request
     * is here for the app's own scheme + host and the authenticated user, nothing
     * else — which is why there is no FormRequest.
     */
    public function connectSession(Request $request): JsonResponse
    {
        try {
            $externalId = $this->accountId();
            $returnUrl = OAuthReturnUrl::fromConfigPath(
                $request->getSchemeAndHttpHost(),
                $this->returnPath(),
            );
            $context = $this->connectSessionContext?->context($request->user()) ?? [];
            $session = $this->hub->connectSessions()->create(
                accountExternalId: $externalId,
                displayName: $this->accountIdResolver->displayName(),
                returnUrl: $returnUrl,
                categories: $context['categories'] ?? null,
                mode: $context['mode'] ?? null,
                actor: $context['actor'] ?? null,
            );

            return response()->json($this->connectSessionResponse($session));
        } catch (HubException $e) {
            return $this->hubError($e);
        }
    }

    /**
     * Hub's response is untrusted JSON, narrowed the same way `returnPath()`
     * narrows config — a non-string value reads as absent rather than
     * widening the generated OpenAPI schema (and consumer TypeScript) to `any`.
     *
     * @param  array<string, mixed>  $session
     * @return array{url: string|null, expires_at: string|null}
     */
    private function connectSessionResponse(array $session): array
    {
        return [
            'url' => is_string($session['url'] ?? null) ? $session['url'] : null,
            'expires_at' => is_string($session['expires_at'] ?? null) ? $session['expires_at'] : null,
        ];
    }

    private function returnPath(): string
    {
        $path = Config::get('hub.oauth.return_path', '');

        return is_string($path) ? $path : '';
    }

    /** @phpstan-assert !null $this->accountIdResolver */
    private function accountId(): string
    {
        $this->assertAccountResolverBound();

        return $this->accountIdResolver->accountId();
    }

    /** @phpstan-assert !null $this->accountIdResolver */
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

        $body = [
            'message' => $e->getMessage(),
            'error' => $e->error,
            'request_id' => $e->requestId,
        ];

        if ($e instanceof MissingConfigurationException) {
            return response()->json($body, $e->status ?? 503);
        }

        $body['hub_status'] = $e->status;

        if ($e instanceof RateLimitException) {
            return response()->json(
                $body,
                503,
                $e->retryAfter === null ? [] : ['Retry-After' => (string) $e->retryAfter],
            );
        }

        return response()->json($body, 502);
    }
}
