<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Security;

/**
 * IP Whitelist
 *
 * Manages IP whitelist/blacklist for connection filtering.
 *
 * Features:
 * - CIDR notation support (e.g., 192.168.0.0/24)
 * - Individual IP addresses
 * - Wildcard patterns
 * - Fast lookup with caching
 *
 * Performance: <0.1ms per check
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Security
 * @since       2025-12-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class IpWhitelist
{
    /**
     * Whitelist entries (CIDR notation or individual IPs).
     *
     * @var array<string>
     */
    private array $whitelist = [];

    /**
     * Blacklist entries (CIDR notation or individual IPs).
     *
     * @var array<string>
     */
    private array $blacklist = [];

    /**
     * Lookup cache for performance.
     *
     * @var array<string, bool>
     */
    private array $cache = [];

    /**
     * @param array<string> $whitelist Whitelist entries
     * @param array<string> $blacklist Blacklist entries
     * @param bool $whitelistMode Enable whitelist mode (default: false)
     */
    public function __construct(
        array $whitelist = [],
        array $blacklist = [],
        private readonly bool $whitelistMode = false
    ) {
        $this->whitelist = $whitelist;
        $this->blacklist = $blacklist;
    }

    /**
     * Check if IP is allowed.
     *
     * @param string $ipAddress IP address
     * @return bool True if allowed
     */
    public function isAllowed(string $ipAddress): bool
    {
        // Check cache first
        if (isset($this->cache[$ipAddress])) {
            return $this->cache[$ipAddress];
        }

        $allowed = $this->checkIp($ipAddress);

        // Cache result
        $this->cache[$ipAddress] = $allowed;

        return $allowed;
    }

    /**
     * Check IP against whitelist/blacklist.
     *
     * @param string $ipAddress IP address
     * @return bool
     */
    private function checkIp(string $ipAddress): bool
    {
        // Check blacklist first (always deny)
        if ($this->isInList($ipAddress, $this->blacklist)) {
            return false;
        }

        // If whitelist mode enabled, IP must be in whitelist
        if ($this->whitelistMode) {
            return $this->isInList($ipAddress, $this->whitelist);
        }

        // Default: allow (unless blacklisted)
        return true;
    }

    /**
     * Check if IP matches any entry in list.
     *
     * @param string $ipAddress IP address
     * @param array<string> $entryList List entries
     * @return bool
     */
    private function isInList(string $ipAddress, array $entryList): bool
    {
        foreach ($entryList as $entry) {
            if ($this->matchesEntry($ipAddress, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP matches entry.
     *
     * Supports:
     * - Exact IP: 192.168.1.100
     * - CIDR notation: 192.168.0.0/24
     * - Wildcard: 192.168.*.*
     *
     * @param string $ipAddress IP address
     * @param string $entry Whitelist/blacklist entry
     * @return bool
     */
    private function matchesEntry(string $ipAddress, string $entry): bool
    {
        // Exact match
        if ($ipAddress === $entry) {
            return true;
        }

        // CIDR notation
        if (str_contains($entry, '/')) {
            return $this->matchesCidr($ipAddress, $entry);
        }

        // Wildcard pattern
        if (str_contains($entry, '*')) {
            return $this->matchesWildcard($ipAddress, $entry);
        }

        return false;
    }

    /**
     * Check if IP matches CIDR notation.
     *
     * @param string $ipAddress IP address
     * @param string $cidr CIDR notation (e.g., 192.168.0.0/24)
     * @return bool
     */
    private function matchesCidr(string $ipAddress, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr);

        // Convert IP and subnet to binary
        $ipBin = ip2long($ipAddress);
        $subnetBin = ip2long($subnet);

        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        // Calculate network mask
        $maskBin = -1 << (32 - (int) $mask);

        // Check if IP is in subnet
        return ($ipBin & $maskBin) === ($subnetBin & $maskBin);
    }

    /**
     * Check if IP matches wildcard pattern.
     *
     * @param string $ipAddress IP address
     * @param string $pattern Wildcard pattern (e.g., 192.168.*.*)
     * @return bool
     */
    private function matchesWildcard(string $ipAddress, string $pattern): bool
    {
        // Convert wildcard pattern to regex
        $regex = '/^' . str_replace(
            ['.', '*'],
            ['\.', '\d+'],
            $pattern
        ) . '$/';

        return preg_match($regex, $ipAddress) === 1;
    }

    /**
     * Add IP to whitelist.
     *
     * @param string $entry IP or CIDR notation
     */
    public function addToWhitelist(string $entry): void
    {
        if (!in_array($entry, $this->whitelist, true)) {
            $this->whitelist[] = $entry;
            $this->clearCache();
        }
    }

    /**
     * Add IP to blacklist.
     *
     * @param string $entry IP or CIDR notation
     */
    public function addToBlacklist(string $entry): void
    {
        if (!in_array($entry, $this->blacklist, true)) {
            $this->blacklist[] = $entry;
            $this->clearCache();
        }
    }

    /**
     * Remove IP from whitelist.
     *
     * @param string $entry IP or CIDR notation
     */
    public function removeFromWhitelist(string $entry): void
    {
        $this->whitelist = array_values(array_filter(
            $this->whitelist,
            fn($item) => $item !== $entry
        ));
        $this->clearCache();
    }

    /**
     * Remove IP from blacklist.
     *
     * @param string $entry IP or CIDR notation
     */
    public function removeFromBlacklist(string $entry): void
    {
        $this->blacklist = array_values(array_filter(
            $this->blacklist,
            fn($item) => $item !== $entry
        ));
        $this->clearCache();
    }

    /**
     * Get whitelist.
     *
     * @return array<string>
     */
    public function getWhitelist(): array
    {
        return $this->whitelist;
    }

    /**
     * Get blacklist.
     *
     * @return array<string>
     */
    public function getBlacklist(): array
    {
        return $this->blacklist;
    }

    /**
     * Clear lookup cache.
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }
}
