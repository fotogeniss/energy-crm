<?php

/**
 * Service locator — a temporary bridge, not the destination.
 *
 * The legacy ECRM_* classes are static and take no constructor arguments, so
 * they cannot receive dependencies the honest way. Until they migrate, they
 * reach their collaborators through here.
 *
 * New code under `src/` must NOT use this: take what you need in your
 * constructor. Every remaining call site here is a to-do item, and when the
 * legacy classes are gone this file goes with them.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM;

use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Access\WordPressScopeResolver;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerRepository;

final class Services
{
    private static ?ScopeResolver $scopeResolver = null;

    private static ?ContractRepository $contracts = null;

    private static ?CustomerRepository $customers = null;

    private function __construct()
    {
    }

    public static function scopeResolver(): ScopeResolver
    {
        return self::$scopeResolver ??= new WordPressScopeResolver();
    }

    public static function contracts(): ContractRepository
    {
        return self::$contracts ??= new ContractRepository();
    }

    public static function customers(): CustomerRepository
    {
        return self::$customers ??= new CustomerRepository();
    }

    /** Test seam: swap implementations, then reset(). */
    public static function swap(
        ?ScopeResolver $scopeResolver = null,
        ?ContractRepository $contracts = null,
        ?CustomerRepository $customers = null,
    ): void {
        self::$scopeResolver = $scopeResolver ?? self::$scopeResolver;
        self::$contracts     = $contracts ?? self::$contracts;
        self::$customers     = $customers ?? self::$customers;
    }

    public static function reset(): void
    {
        self::$scopeResolver = null;
        self::$contracts     = null;
        self::$customers     = null;
    }
}
