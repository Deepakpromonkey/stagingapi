<?php

namespace App\Services\Drayage\Parsers;

use App\Exceptions\DrayageException;

/**
 * The least a file must carry to be importable: a company name, and either
 * the LoadMatch ID or the directory metros - one of the two is what makes a
 * carrier identifiable across re-imports and placeable on the map.
 */
class RequiredColumns
{
    public static function assert(array $keys): void
    {
        $missing = [];

        if (! in_array('company_name', $keys, true)) {
            $missing[] = 'Company';
        }

        if (! in_array('loadmatch_id', $keys, true) && ! in_array('metros', $keys, true)) {
            $missing[] = 'LoadMatch ID or Directory metros';
        }

        if ($missing !== []) {
            throw DrayageException::unreadable('The file is missing required columns: '.implode(', ', $missing).'.');
        }
    }
}
