<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Contracts;


/**
 * Interface SeederInterface
 *
 * Contract defining the interface for SeederInterface implementations in
 * the Database query building and ORM layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface SeederInterface
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void;

    /**
     * Get seeder dependencies (other seeders that must run first).
     *
     * @return array<string> Array of seeder class names
     */
    public function dependencies(): array;

    /**
     * Whether to run this seeder inside a transaction.
     *
     * @return bool
     */
    public function useTransaction(): bool;
}

