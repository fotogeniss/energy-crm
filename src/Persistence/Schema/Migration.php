<?php

/**
 * One irreversible, ordered change to the database.
 *
 * A migration runs at most once per site. It must be safe against a schema it
 * did not create — ask SchemaInspector before altering — but it is not a
 * "make the schema look like this" script. That distinction is what dbDelta
 * gets wrong and what makes its behaviour impossible to reason about.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema;

interface Migration
{
    /**
     * Stable identifier, recorded once applied. Never change it after release:
     * the id is the only thing telling a live site this ran already.
     */
    public function id(): string;

    /** Human-readable summary, shown in logs and the admin screen. */
    public function description(): string;

    public function apply(SchemaInspector $schema): void;
}
