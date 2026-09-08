<?php

namespace App\Casts;

use App\Support\Fmcsa;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts an FMCSA yes/no column to a real boolean.
 *
 * These columns are varchar and the Motus load writes the literal strings
 * 'true' / 'false'. Laravel's built-in `boolean` cast does `(bool) $value`,
 * which makes 'false' come back true — every flag on the record read as set.
 */
class FmcsaFlag implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?bool
    {
        return $value === null ? null : Fmcsa::flag($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : (Fmcsa::flag($value) ? 'true' : 'false');
    }
}
