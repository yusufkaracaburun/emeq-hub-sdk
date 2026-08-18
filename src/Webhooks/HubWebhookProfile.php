<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Webhooks;

use Emeq\HubSdk\Contracts\ResolvesWebhookAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Spatie\WebhookClient\WebhookProfile\WebhookProfile;

class HubWebhookProfile implements WebhookProfile
{
    public function __construct(
        private ResolvesWebhookAccount $accounts,
    ) {}

    public function shouldProcess(Request $request): bool
    {
        $envelope = HubWebhookEnvelope::tryFromRaw($request->getContent());

        if ($envelope === null) {
            $payload = json_decode($request->getContent(), true);
            $reason = is_array($payload) ? 'missing_account_id' : 'invalid_json';

            Log::info('hub.webhook.skipped', [
                'reason' => $reason,
            ]);

            return false;
        }

        return $this->accounts->prepare($envelope->accountId);
    }
}
