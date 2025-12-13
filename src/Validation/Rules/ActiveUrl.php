<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class ActiveUrl
 *
 * Validates that the URL has a valid DNS record (A or AAAA).
 *
 * Performance: O(1) but involves DNS lookup (network I/O)
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Validation\Rules
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ActiveUrl implements RuleInterface
{
    /**
     * Determine if the validation rule passes.
     *
     * @param string $attribute The attribute name being validated
     * @param mixed $value The value being validated
     * @return bool
     */
    public function passes(string $attribute, mixed $value): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        // First validate URL format
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        // Extract host from URL
        $host = parse_url($value, PHP_URL_HOST);

        if (empty($host)) {
            return false;
        }

        // Check DNS records
        return $this->checkDnsRecord($host);
    }

    /**
     * Check if the host has valid DNS records.
     *
     * @param string $host Hostname to check
     * @return bool
     */
    private function checkDnsRecord(string $host): bool
    {
        // Try A record first (IPv4)
        $records = @dns_get_record($host, DNS_A);
        if ($records !== false && count($records) > 0) {
            return true;
        }

        // Try AAAA record (IPv6)
        $records = @dns_get_record($host, DNS_AAAA);
        if ($records !== false && count($records) > 0) {
            return true;
        }

        return false;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return "The :attribute must be a valid, active URL.";
    }
}
