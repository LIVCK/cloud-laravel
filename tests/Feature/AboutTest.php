<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

function about(): string
{
    Artisan::call('about', ['--only' => 'livck cloud']);

    return Artisan::output();
}

it('adds a section with versions, connections, base URIs and token prefixes', function (): void {
    useConnection('default', ['token' => TEST_TOKEN]);
    useConnection('customer-b', ['base_uri' => 'https://b.example.test/v1', 'token' => 'lvk_short']);
    useConnection('customer-c', ['base_uri' => null]);

    $output = about();

    expect($output)->toContain('LIVCK Cloud')
        ->toContain('Version')->toContain('1.1.0')
        ->toContain('SDK version')
        ->toContain('Default connection')
        ->toContain('Connection [default]')->toContain('https://api.livck.cloud/v1, token lvk_Secr3tPr…')
        ->toContain('Connection [customer-b]')->toContain('https://b.example.test/v1, token lvk_…')
        ->toContain('Connection [customer-c]')->toContain('https://api.livck.cloud/v1, no token')
        ->and($output)->not->toContain(TEST_TOKEN)
        ->and($output)->not->toContain('efixAndTheRest')
        ->and($output)->not->toContain('lvk_short');
});

it('shows a connection it cannot read instead of failing', function (): void {
    config()->set('livck-cloud.default', null);
    config()->set('livck-cloud.connections.broken', 'lvk_misplaced');

    $output = about();

    expect($output)->toContain('Default connection')->toContain('-')
        ->toContain('Connection [broken]')->toContain('not configured')
        ->and($output)->not->toContain('lvk_misplaced');
});

it('offers the section as JSON', function (): void {
    useConnection('default', ['token' => TEST_TOKEN]);

    Artisan::call('about', ['--only' => 'livck cloud', '--json' => true]);

    /** @var array<string, array<string, string>> $json */
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    // Laravel snake-cases the section name letter by letter for the JSON keys.
    expect($json['l_i_v_c_k_cloud'] ?? null)->toBe([
        'version' => '1.1.0',
        'sdk_version' => '1.0.0',
        'default_connection' => 'default',
        'connection[default]' => 'https://api.livck.cloud/v1, token lvk_Secr3tPr…',
    ]);
});
