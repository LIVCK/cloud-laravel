<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use LIVCK\Cloud\Laravel\Facades\LivckCloud;
use LIVCK\Cloud\Testing\MockResponse;
use Monolog\Handler\NullHandler;
use Psr\Log\NullLogger;

/**
 * Every log line written while the callback runs.
 *
 * @param Closure(): mixed $callback
 * @return list<MessageLogged>
 */
function logged(Closure $callback): array
{
    $messages = new ArrayObject();
    Event::listen(MessageLogged::class, static function (MessageLogged $event) use ($messages): void {
        $messages->append($event);
    });

    $callback();

    /** @var list<MessageLogged> */
    return array_values($messages->getArrayCopy());
}

it('writes one debug line per attempt to the configured channel', function (): void {
    config()->set('logging.channels.livck-cloud', ['driver' => 'monolog', 'handler' => NullHandler::class, 'level' => 'debug']);
    config()->set('livck-cloud.log_channel', 'livck-cloud');
    useConnection('default', ['token' => TEST_TOKEN]);
    LivckCloud::fake([MockResponse::error('Service Unavailable', 503), MockResponse::json(mePayload())]);

    $messages = logged(fn(): mixed => LivckCloud::me());

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->level)->toBe('debug')
        ->and($messages[0]->message)->toBe('{method} {uri} -> {status} in {duration_ms} ms (attempt {attempt}/{max_attempts})')
        ->and($messages[0]->context)->toMatchArray(['method' => 'GET', 'uri' => 'https://api.livck.cloud/v1/me', 'status' => 503, 'attempt' => 1, 'max_attempts' => 3])
        ->and($messages[1]->context)->toMatchArray(['status' => 200, 'attempt' => 2]);

    $written = json_encode(array_map(static fn(MessageLogged $message): array => [$message->message, $message->context], $messages), JSON_THROW_ON_ERROR);

    expect($written)->not->toContain('lvk_')
        ->and($written)->not->toContain('Example Hosting');
});

it('logs nothing without a channel', function (): void {
    LivckCloud::fake([MockResponse::json(mePayload())]);

    $messages = logged(fn(): mixed => LivckCloud::me());

    expect($messages)->toBe([])
        ->and(LivckCloud::options()->logger)->toBeInstanceOf(NullLogger::class);
});
