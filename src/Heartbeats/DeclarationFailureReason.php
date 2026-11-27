<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Heartbeats;

/**
 * Why the heartbeats could not be declared to LIVCK.
 */
enum DeclarationFailureReason: string
{
    /** No answer came back: DNS, connection, timeout. */
    case Unreachable = 'unreachable';

    /** LIVCK answered with a 5xx. */
    case ServerError = 'server_error';

    /** Too many requests (429). */
    case RateLimited = 'rate_limited';

    /** Another declaration of the same heartbeats ran at the same moment (409). */
    case Conflict = 'conflict';

    /** The API token is unknown, revoked or expired (401). */
    case InvalidToken = 'invalid_token';

    /** The token lacks `heartbeats.declare`, or its organization is not available (403). */
    case NotPermitted = 'not_permitted';

    /**
     * The token sees only part of the organization: its view is limited by access areas (403).
     * Declared heartbeats belong to the whole organization; a token of a member who sees all of
     * it is needed.
     */
    case RestrictedView = 'restricted_view';

    /** The organization's plan does not include API access (403). */
    case PlanWithoutApi = 'plan_without_api';

    /** LIVCK refused the list itself (422). */
    case Invalid = 'invalid';

    /** No API token is configured for the connection. */
    case NoToken = 'no_token';

    /** Anything else. */
    case Unexpected = 'unexpected';

    /** How the reason reads in `livck-cloud:heartbeats`. */
    public function describe(): string
    {
        return match ($this) {
            self::Unreachable => 'LIVCK could not be reached',
            self::ServerError => 'LIVCK answered with an error',
            self::RateLimited => 'too many requests',
            self::Conflict => 'another declaration ran at the same moment',
            self::InvalidToken => 'the API token is not accepted',
            self::NotPermitted => 'the token lacks heartbeats.declare',
            self::RestrictedView => 'the token sees only part of the organization',
            self::PlanWithoutApi => 'the plan does not include API access',
            self::Invalid => 'LIVCK refused the list',
            self::NoToken => 'no API token is configured',
            self::Unexpected => 'an unexpected error',
        };
    }
}
