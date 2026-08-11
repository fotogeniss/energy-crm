<?php

/**
 * Turning a contract into the sheets of paper it has to become.
 *
 * An interface for one implementation, which usually means a wrong one. It is
 * here for a specific reason: rendering is where this system talks to two
 * legacy static classes and a PDF library, and none of that can be asked what
 * it was given. The defect that made this necessary is a good example — the
 * stored provider form was rendered without the customer's signature for as
 * long as the feature existed, and no test could see it, because the evidence
 * ended up inside a compressed PDF stream.
 *
 * With a collaborator, the question "was it handed the signature?" has an
 * answer. Without one it has only a PDF to squint at.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

interface SheetRenderer
{
    /**
     * Every sheet the application consists of, first one first.
     *
     * The first sheet is the contract; anything after it rides with it. An
     * empty list means nothing could be rendered at all — the caller then
     * leaves whatever is already stored alone, rather than replacing a working
     * document with nothing.
     *
     * @param array<string, mixed> $contract      Joined contract row, decrypted.
     * @param string|null          $signaturePath Absolute path to the signature
     *                                            image, when one was collected.
     *
     * @return list<array{key: string, bytes: string}>
     */
    public function render(array $contract, ?string $signaturePath): array;
}
