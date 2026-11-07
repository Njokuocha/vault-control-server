<?php

namespace App\Livewire;

use App\Models\ActivityLog;
use App\Models\SecurityLocation;
use App\Services\ActivityLogger;
use App\Services\SettingService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class SecuritySettings extends Component
{
    public int $bruteForceAttempts = 3;

    public int $bruteForceWindowSeconds = 120;

    public string $mapUrl = '';

    public function mount(SettingService $settings): void
    {
        $this->bruteForceAttempts = (int) ($settings->get('auth_brute_force_attempts') ?? config('auth_security.brute_force_attempts', 3));
        $this->bruteForceWindowSeconds = (int) ($settings->get('auth_brute_force_window_seconds') ?? config('auth_security.brute_force_window_seconds', 120));
    }

    public function savePolicy(SettingService $settings): void
    {
        $validated = $this->validate([
            'bruteForceAttempts' => ['required', 'integer', 'between:2,50'],
            'bruteForceWindowSeconds' => ['required', 'integer', 'between:30,3600'],
        ]);

        $settings->set('auth_brute_force_attempts', (string) $validated['bruteForceAttempts']);
        $settings->set('auth_brute_force_window_seconds', (string) $validated['bruteForceWindowSeconds']);
        app(ActivityLogger::class)->record(
            'configuration',
            'security.brute-force-policy-updated',
            'Administrator updated the brute-force detection threshold.',
            ['attempts' => $validated['bruteForceAttempts'], 'window_seconds' => $validated['bruteForceWindowSeconds']],
        );
        $this->dispatch('alert', type: 'success', message: 'Authentication security policy updated.');
    }

    public function saveMap(): void
    {
        $validated = $this->validate([
            'mapUrl' => ['nullable', 'string', 'max:2048'],
        ]);

        $input = trim((string) $validated['mapUrl']);
        if ($input === '') {
            SecurityLocation::query()->whereKey(1)->delete();
            $this->mapUrl = '';
            app(ActivityLogger::class)->record('configuration', 'security.map-removed', 'Administrator removed the corporate location map.');
            $this->dispatch('alert', type: 'success', message: 'The location map was removed.');

            return;
        }

        $location = $this->parseGoogleMapsUrl($input);
        if ($location === null) {
            $this->addError('mapUrl', 'Enter a Google Maps HTTPS link, such as https://www.google.com/maps/... or https://maps.google.com/?q=...');

            return;
        }

        SecurityLocation::query()->updateOrCreate(
            ['id' => 1],
            [
                'map_url' => $location['url'],
                'embed_url' => $location['embed_url'],
                'latitude' => $location['latitude'] ?? 'Unknown',
                'longitude' => $location['longitude'] ?? 'Unknown',
                'updated_at' => now(),
            ],
        );
        app(ActivityLogger::class)->record(
            'configuration',
            'security.map-updated',
            'Administrator updated the corporate location map.',
            ['latitude' => $location['latitude'] ?? 'Unknown', 'longitude' => $location['longitude'] ?? 'Unknown'],
        );
        $this->mapUrl = '';
        $this->dispatch('alert', type: 'success', message: 'The location map was updated.');
    }

    public function render(): View
    {
        return view('livewire.security-settings', [
            'currentLocation' => SecurityLocation::query()->find(1),
        ]);
    }

    private function parseGoogleMapsUrl(string $input): ?array
    {
        $parts = parse_url($input);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $allowedHost = in_array($host, ['google.com', 'www.google.com', 'maps.google.com'], true);
        $allowedMapPath = str_starts_with($path, '/maps')
            || ($host === 'maps.google.com' && in_array($path, ['', '/'], true));
        if (! $allowedHost || ! $allowedMapPath) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);
        $mapQuery = is_string($query['q'] ?? null)
            ? $query['q']
            : (is_string($query['query'] ?? null) ? $query['query'] : '');
        $coordinates = $this->extractCoordinates($input);
        if ($mapQuery === '' && $path !== '') {
            $path = trim((string) preg_replace('#^/maps(?:/place/|/search/)?#', '', $path), '/');
            $mapQuery = urldecode(str_replace('+', ' ', $path));
        }
        $embedQuery = $coordinates === null ? $mapQuery : $coordinates['latitude'].','.$coordinates['longitude'];
        if ($embedQuery === '') {
            return null;
        }

        return [
            'url' => $input,
            'embed_url' => 'https://www.google.com/maps?q='.rawurlencode($embedQuery).'&output=embed',
            ...($coordinates ?? []),
        ];
    }

    private function extractCoordinates(string $url): ?array
    {
        $patterns = [
            '/@(-?\d{1,2}(?:\.\d+)?),\s*(-?\d{1,3}(?:\.\d+)?)/',
            '/!3d(-?\d{1,2}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)/',
            '/(?:[?&](?:q|query|ll|center)=)(-?\d{1,2}(?:\.\d+)?),\s*(-?\d{1,3}(?:\.\d+)?)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, urldecode($url), $matches) !== 1) {
                continue;
            }

            $latitude = (float) $matches[1];
            $longitude = (float) $matches[2];
            if (abs($latitude) > 90 || abs($longitude) > 180) {
                continue;
            }

            return [
                'latitude' => number_format($latitude, 6, '.', ''),
                'longitude' => number_format($longitude, 6, '.', ''),
            ];
        }

        return null;
    }
}
