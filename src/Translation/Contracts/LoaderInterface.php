<?php

declare(strict_types=1);

namespace Toporia\Framework\Translation\Contracts;


/**
 * Interface LoaderInterface
 *
 * Contract defining the interface for LoaderInterface implementations in
 * the Multi-language support layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Translation\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface LoaderInterface
{
    /**
     * Load translations for a given locale and namespace.
     *
     * @param string $locale Locale code (e.g., 'en', 'vi')
     * @param string $namespace Namespace/group (e.g., 'messages', 'validation')
     * @return array<string, mixed> Translation array (nested arrays supported)
     */
    public function load(string $locale, string $namespace): array;

    /**
     * Add a namespace path for translations.
     *
     * @param string $namespace Namespace name
     * @param string $path Path to translation files
     * @return void
     */
    public function addNamespace(string $namespace, string $path): void;

    /**
     * Get all namespaces.
     *
     * @return array<string, string> Namespace => path mapping
     */
    public function getNamespaces(): array;
}
