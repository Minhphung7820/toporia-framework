<?php

declare(strict_types=1);

namespace Toporia\Framework\Http;

use Toporia\Framework\Validation\Validator;
use Toporia\Framework\Validation\Contracts\ValidatorInterface;
use Toporia\Framework\Container\Contracts\ContainerInterface;


/**
 * Trait ValidatesRequests
 *
 * Trait providing reusable functionality for ValidatesRequests in the HTTP
 * request and response handling layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
trait ValidatesRequests
{
    /**
     * Validate the given request with the given rules.
     *
     * Performance: O(N*R) where N = fields, R = rules
     *
     * @param Request $request
     * @param array $rules
     * @param array $messages
     * @return array Validated data
     * @throws ValidationException
     */
    protected function validate(Request $request, array $rules, array $messages = []): array
    {
        $validator = $this->getValidator();

        $passes = $validator->validate($request->all(), $rules, $messages);

        if (!$passes) {
            throw new ValidationException($validator->errors());
        }

        return $validator->validated();
    }

    /**
     * Validate the given request with the given rules (returns on failure).
     *
     * Performance: O(N*R) where N = fields, R = rules
     *
     * @param Request $request
     * @param array $rules
     * @param array $messages
     * @return array Validated data
     */
    protected function validateOrFail(Request $request, array $rules, array $messages = []): array
    {
        return $this->validate($request, $rules, $messages);
    }

    /**
     * Get validator instance.
     *
     * Performance: O(1) - Creates or reuses validator
     *
     * @return ValidatorInterface
     */
    protected function getValidator(): ValidatorInterface
    {
        // Try to get from container first
        if (property_exists($this, 'container') && $this->container instanceof ContainerInterface) {
            if ($this->container->has(ValidatorInterface::class)) {
                return $this->container->get(ValidatorInterface::class);
            }
        }

        // Fallback to new instance
        return new Validator();
    }
}
