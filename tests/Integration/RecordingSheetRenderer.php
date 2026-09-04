<?php

/**
 * A renderer that draws nothing and remembers everything.
 *
 * The two defects this suite was written around — a row handed over still
 * encrypted, and a signature never handed over at all — are both invisible in
 * the finished PDF, because the evidence ends up inside a compressed stream.
 * Both are obvious the moment the renderer can be asked what it was given.
 *
 * That is the whole reason SheetRenderer is an interface.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Infrastructure\SheetRenderer;

final class RecordingSheetRenderer implements SheetRenderer
{
    /**
     * @var list<array{
     *   contract: array<string, mixed>,
     *   signaturePath: string|null,
     *   signatureRoles: array<string, string>
     * }>
     */
    public array $calls = [];

    /**
     * Returns one sheet by default so the caller runs to completion: a
     * renderer that returns nothing makes store() give up early, and then the
     * test is measuring the wrong thing.
     */
    public function render(array $contract, ?string $signaturePath, array $signatureRoles = []): array
    {
        // Ο χάρτης ρόλων καταγράφεται μαζί με τα υπόλοιπα: το ερώτημα «πήρε ο
        // renderer τη ΣΩΣΤΗ υπογραφή για κάθε γραμμή;» πρέπει να έχει απάντηση
        // εδώ, όχι μέσα σε συμπιεσμένο PDF stream.
        $this->calls[] = [
            'contract'       => $contract,
            'signaturePath'  => $signaturePath,
            'signatureRoles' => $signatureRoles,
        ];

        return [['key' => 'contract', 'bytes' => '%PDF-1.4 not really a document']];
    }
}
