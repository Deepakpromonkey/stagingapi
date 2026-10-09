<?php

namespace App\Services\Drayage\Parsers;

/**
 * One input format for the drayage importer.
 *
 * A parser only reads: it maps whatever the file calls its columns onto the
 * dictionary keys in DrayageFields and hands back raw values one row at a
 * time. Typing, validation and merging are the normalizer's job, so every
 * format comes out the same once it has been through it.
 *
 * Rows are yielded as they are read - a parser must never load the whole
 * file - each as:
 *
 *   ['row' => int, 'values' => array<key, mixed>, 'extra' => array<label, mixed>, 'error' => null]
 *
 * or, for a row that cannot be read at all (wrong column count, broken
 * encoding), ['row' => int, 'error' => 'reason'], which the importer counts
 * as rejected.
 *
 * A file that cannot be imported at all (empty, unreadable, no Company
 * column) throws DrayageException instead.
 */
interface SourceParser
{
    /**
     * @return \Generator<int, array>
     */
    public function rows(string $path): \Generator;

    /**
     * Column mapping, complete once rows() has been exhausted:
     * ['missing' => list<key>, 'unknown' => list<label>].
     */
    public function headerReport(): array;
}
