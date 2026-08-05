<?php

/**
 * Signature tokens and the images collected against them.
 *
 * The only table reachable without a logged-in user, so the rules are stricter
 * rather than looser: a token is the entire credential, it identifies exactly
 * one contract, and it stops working the moment it has been used.
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class SignatureRepository
{
    private string $table;

    public function __construct(?string $table = null)
    {
        $this->table = $table ?? Tables::name(Tables::SIGNATURES);
    }

    /**
     * What the signing page may show: enough to recognise the contract, and
     * nothing a stranger with a guessed token could exploit.
     *
     * @return array<string, mixed>|null
     */
    public function summaryFor(string $token): ?array
    {
        global $wpdb;

        if ($token === '') {
            return null;
        }

        /** @var array<string, mixed>|null $row */
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT s.signed_at, c.code, p.name AS provider,
                        cu.first_name, cu.last_name, cu.company_name
                 FROM %i s
                 LEFT JOIN %i c  ON c.id  = s.contract_id
                 LEFT JOIN %i cu ON cu.id = c.customer_id
                 LEFT JOIN %i p  ON p.id  = c.provider_id
                 WHERE s.token = %s',
                $this->table,
                Tables::name(Tables::CONTRACTS),
                Tables::name(Tables::CUSTOMERS),
                Tables::name(Tables::PROVIDERS),
                $token
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findByToken(string $token): ?array
    {
        global $wpdb;

        if ($token === '') {
            return null;
        }

        /** @var array<string, mixed>|null $row */
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE token = %s', $this->table, $token),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Record the signature, but only against a token not yet used.
     *
     * "Already signed" is part of the WHERE clause rather than a check before
     * it: two submissions arriving together must not both succeed, and on a
     * public endpoint that is not a theoretical concern.
     */
    public function sign(string $token, string $signerName, string $image, string $ip): bool
    {
        global $wpdb;

        $affected = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET signer_name = %s, image = %s, signed_at = %s, ip = %s
                 WHERE token = %s AND signed_at IS NULL',
                $this->table,
                $signerName,
                $image,
                current_time('mysql', true),
                substr($ip, 0, 60),
                $token
            )
        );

        return $affected !== false && $affected > 0;
    }
}
