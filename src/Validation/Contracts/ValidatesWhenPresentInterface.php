<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Contracts;

/**
 * Interface ValidatesWhenPresentInterface
 *
 * Marker interface for rules that only run when the value is present.
 * Rules implementing this interface will be skipped if value is null or empty string.
 *
 * This is the default behavior - rules WITHOUT this interface follow standard skip-empty semantics.
 * This interface exists for explicit marking and documentation purposes.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Validation\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface ValidatesWhenPresentInterface extends RuleInterface
{
    // Marker interface - no additional methods required
    // Rules implementing this interface run only when value is present (not null/empty)
}
