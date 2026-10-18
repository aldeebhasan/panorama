<?php

namespace Aldeebhasan\Panorama\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A notifiable model, for the queued runs that tell someone they are done.
 *
 * @property int $id
 * @property string $email
 * @property list<string> $abilities
 */
class User extends Authenticatable
{
    use Notifiable;

    public $timestamps = false;
    protected $guarded = [];

    /**
     * @return array<string,string>
     */
    protected function casts(): array
    {
        return ['abilities' => 'array'];
    }
}
