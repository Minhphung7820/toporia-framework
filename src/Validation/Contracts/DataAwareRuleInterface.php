<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Contracts;

use Toporia\Framework\Validation\ValidationData;


/**
 * Interface DataAwareRuleInterface
 *
 * Contract defining the interface for DataAwareRuleInterface
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
interface DataAwareRuleInterface extends RuleInterface
{
    /**
     * Set validation data for this rule.
     *
     * Called once before validation starts.
     * Rule can cache/precompute values from data here.
     *
     * @param ValidationData $data All validation data
     * @return void
     */
    public function setData(ValidationData $data): void;
}

