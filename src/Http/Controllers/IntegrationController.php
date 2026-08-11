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
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Thin Hub BFF: list / connect / disconnect. Account id is always
 * server-side via ResolvesAccountId — never from the request.
 */
class IntegrationController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $this->accountId();

            return response()->json(Hub::integrations()->list());
        } catch (HubException $e) {
            return $this->hubError($e);
        }
    }

    public function connect(Request $request, string $provider): JsonResponse
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

            try {
                Hub::accounts()->create($externalId, $displayName);
            } catch (HubException $e) {
                if ($e->status !== 409) {
                    throw $e;
                }
            }

            $init = Hub::oauth()->init($provider, returnUrl: $returnUrl);

            return response()->json([
                'connection_id' => $init['connection_id'] ?? null,
                'redirect_url' => $init['redirect_url'] ?? null,
            ]);
        } catch (HubException $e) {
            return $this->hubError($e);
        }
    }

    public function destroy(string $connection): JsonResponse|Response
    {
        try {
            $this->accountId();

            $owned = false;
            foreach (Hub::integrations()->list() as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $id = $item['connection_id'] ?? null;
                if ($id !== null && (string) $id === (string) $connection) {
                    $owned = true;
                    break;
                }
            }

            if (! $owned) {
                return response()->json([
                    'message' => 'Connection not found for this account.',
                    'error' => 'connection_not_found',
                ], 404);
            }

            Hub::connections()->delete($connection);

            return response()->noContent();
        } catch (HubException $e) {
            return $this->hubError($e);
        }
    }

    private function accountId(): string
    {
        if (! app()->bound(ResolvesAccountId::class)) {
            throw MissingConfigurationException::missingAccountResolver();
        }

        return app(ResolvesAccountId::class)->accountId();
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
