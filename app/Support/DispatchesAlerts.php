<?php

namespace App\Support;

trait DispatchesAlerts
{
    protected function alert(string $type, string $message): void
    {
        $this->dispatch('alert', type: $type, message: $message);
    }
}
