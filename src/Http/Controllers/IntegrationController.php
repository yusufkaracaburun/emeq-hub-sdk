<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Controllers;

use Emeq\HubSdk\Contracts\ResolvesAccountId;
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
    ) {}

    public function index(): JsonResponse
    {
        try {
            $this->assertAccountResolverBound();

            return response()->json($this->hub->integrations()->list());
        } catch (HubException $e) {
            return $this->hubError($e);
        }
    }

    public function connectSession(Request $request): JsonResponse
    {
        try {
            $externalId = $this->accountId();
            $returnUrl = OAuthReturnUrl::fromConfigPath(
                $request->getSchemeAndHttpHost(),
                $this->returnPath(),
            );
            $session = $this->hub->connectSessions()->create(
                accountExternalId: $externalId,
                displayName: $this->accountIdResolver->displayName(),
                returnUrl: $returnUrl,
            );

            return response()->json($this->connectSessionResponse($session));
        } catch (HubException $e) {
            return $this->hubError($e);
        }
    }

    /**
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
