<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A reseller's customer with an API token of its own, encrypted at rest.
 *
 * @property int $id
 * @property string $livck_token
 */
final class Customer extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['livck_token' => 'encrypted'];
    }
}
