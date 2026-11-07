<?php

namespace App\Services;

use App\Exceptions\MailDeliveryException;

class MailTransportPolicy
{
    public function canDeliver(): bool
    {
        $defaultMailer = (string) config('mail.default');

        return ! $this->containsNonDeliveryTransport($defaultMailer, []);
    }

    public function assertCanDeliver(): void
    {
        if (! $this->canDeliver()) {
            throw new MailDeliveryException('The configured mail transport does not deliver messages.');
        }
    }

    private function containsNonDeliveryTransport(string $mailer, array $visited): bool
    {
        if (in_array($mailer, $visited, true)) {
            return false;
        }
        $visited[] = $mailer;
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);

        if (in_array($transport, ['log', 'array'], true)) {
            return true;
        }
        if (! in_array($transport, ['failover', 'roundrobin'], true)) {
            return false;
        }

        $mailers = config("mail.mailers.{$mailer}.mailers", []);
        foreach (is_array($mailers) ? $mailers : [] as $childMailer) {
            if ($this->containsNonDeliveryTransport((string) $childMailer, $visited)) {
                return true;
            }
        }

        return false;
    }
}
