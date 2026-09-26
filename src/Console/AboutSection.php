<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Console;

use Illuminate\Contracts\Config\Repository as Config;
use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\CloudClient;
use LIVCK\Cloud\Laravel\CloudManager;

/**
 * The "LIVCK Cloud" section of `php artisan about`: the versions, the default connection and,
 * per connection, the base URI and the beginning of the token. Never the token itself.
 *
 * Reads the configuration only, so it shows a connection even when it could not be used.
 *
 * @internal
 */
final readonly class AboutSection
{
    public function __construct(private Config $config) {}

    /**
     * @return array<string, string>
     */
    public function __invoke(): array
    {
        $default = $this->config->get('livck-cloud.default');
        $connections = $this->config->get('livck-cloud.connections');

        $section = [
            'Version' => CloudManager::VERSION,
            'SDK version' => CloudClient::VERSION,
            'Default connection' => is_string($default) ? $default : '-',
        ];

        foreach (is_array($connections) ? $connections : [] as $name => $settings) {
            $section[sprintf('Connection [%s]', $name)] = is_array($settings) ? $this->describe($settings) : 'not configured';
        }

        return $section;
    }

    /**
     * @param array<array-key, mixed> $settings
     */
    private function describe(array $settings): string
    {
        $baseUri = $settings['base_uri'] ?? null;
        $token = $settings['token'] ?? null;

        return sprintf(
            '%s, %s',
            is_string($baseUri) && trim($baseUri) !== '' ? trim($baseUri) : ClientOptions::DEFAULT_BASE_URI,
            is_string($token) && trim($token) !== '' ? 'token ' . $this->prefix(trim($token)) : 'no token',
        );
    }

    /**
     * What LIVCK lists a token by: `lvk_` and the next eight characters, enough to tell tokens
     * apart and far too little to use one. A token too short for that shows four characters.
     */
    private function prefix(string $token): string
    {
        return (strlen($token) >= 24 ? substr($token, 0, 12) : substr($token, 0, 4)) . '…';
    }
}
