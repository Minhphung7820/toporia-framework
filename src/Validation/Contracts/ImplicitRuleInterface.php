<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Contracts;


/**
 * Interface ImplicitRuleInterface
 *
 * Contract defining the interface for ImplicitRuleInterface
 * implementations in the Form and data validation layer of the Toporia
 * Framework.
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
interface ImplicitRuleInterface extends RuleInterface
{
    // Marker interface - no additional methods required
    // The presence of this interface signals to Validator that
    // this rule should run even when value is null/empty
}

