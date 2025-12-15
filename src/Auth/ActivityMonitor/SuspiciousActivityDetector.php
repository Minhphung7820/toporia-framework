<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\ActivityMonitor;

use Toporia\Framework\Cache\Contracts\CacheInterface;

/**
 * Class SuspiciousActivityDetector
 *
 * Detects suspicious authentication activity based on:
 * - Location changes (IP geolocation)
 * - Device fingerprint changes
 * - Unusual time patterns
 * - Multiple failed attempts from different IPs
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\ActivityMonitor
 * @since       2025-01-15
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class SuspiciousActivityDetector
{
    /**
     * Activity retention in seconds (30 days).
     */
    private const RETENTION_PERIOD = 2592000;

    /**
     * Threshold for location change alert (km).
     */
    private const LOCATION_THRESHOLD_KM = 1000;

    /**
     * Time window for impossible travel (seconds).
     */
    private const IMPOSSIBLE_TRAVEL_WINDOW = 3600; // 1 hour

    public function __construct(
        private CacheInterface $cache
    ) {}

    /**
     * Record login activity.
     *
     * @param string $userId User identifier
     * @param array $context Login context (ip, user_agent, location, etc.)
     * @return void
     */
    public function recordActivity(string $userId, array $context): void
    {
        $key = $this->getActivityKey($userId);
        $activities = $this->getActivities($userId);

        // Add new activity
        $activities[] = [
            'timestamp' => time(),
            'ip' => $context['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $context['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null,
            'country' => $context['country'] ?? null,
            'city' => $context['city'] ?? null,
            'latitude' => $context['latitude'] ?? null,
            'longitude' => $context['longitude'] ?? null,
            'device_fingerprint' => $context['device_fingerprint'] ?? null,
            'success' => $context['success'] ?? true,
        ];

        // Keep only last 100 activities
        $activities = array_slice($activities, -100);

        $this->cache->put($key, $activities, self::RETENTION_PERIOD);
    }

    /**
     * Analyze activity and detect suspicious patterns.
     *
     * @param string $userId User identifier
     * @param array $currentContext Current login context
     * @return array{suspicious: bool, reasons: array<string>, risk_score: int}
     */
    public function analyze(string $userId, array $currentContext): array
    {
        $activities = $this->getActivities($userId);
        $reasons = [];
        $riskScore = 0;

        if (empty($activities)) {
            // First-time login - low risk
            return ['suspicious' => false, 'reasons' => [], 'risk_score' => 0];
        }

        $lastActivity = end($activities);

        // Check location change
        if ($this->isLocationChange($lastActivity, $currentContext)) {
            $reasons[] = 'Location change detected';
            $riskScore += 30;

            // Check impossible travel
            if ($this->isImpossibleTravel($lastActivity, $currentContext)) {
                $reasons[] = 'Impossible travel detected';
                $riskScore += 50;
            }
        }

        // Check device change
        if ($this->isDeviceChange($lastActivity, $currentContext)) {
            $reasons[] = 'New device detected';
            $riskScore += 20;
        }

        // Check IP change
        if ($this->isIpChange($lastActivity, $currentContext)) {
            $reasons[] = 'IP address change detected';
            $riskScore += 10;
        }

        // Check multiple failed attempts
        $recentFailures = $this->countRecentFailures($activities, 3600); // Last hour
        if ($recentFailures >= 3) {
            $reasons[] = "Multiple failed attempts ({$recentFailures})";
            $riskScore += $recentFailures * 15;
        }

        // Check login from multiple IPs
        $uniqueIps = $this->countUniqueIps($activities, 3600);
        if ($uniqueIps >= 3) {
            $reasons[] = "Login attempts from multiple IPs ({$uniqueIps})";
            $riskScore += $uniqueIps * 10;
        }

        // Check unusual time pattern
        if ($this->isUnusualTime($activities, $currentContext)) {
            $reasons[] = 'Login at unusual time';
            $riskScore += 15;
        }

        $suspicious = $riskScore >= 50; // Threshold for suspicious

        return [
            'suspicious' => $suspicious,
            'reasons' => $reasons,
            'risk_score' => min($riskScore, 100), // Cap at 100
        ];
    }

    /**
     * Check if location has changed significantly.
     *
     * @param array $lastActivity
     * @param array $currentContext
     * @return bool
     */
    private function isLocationChange(array $lastActivity, array $currentContext): bool
    {
        $lastLat = $lastActivity['latitude'] ?? null;
        $lastLon = $lastActivity['longitude'] ?? null;
        $currentLat = $currentContext['latitude'] ?? null;
        $currentLon = $currentContext['longitude'] ?? null;

        if ($lastLat === null || $lastLon === null || $currentLat === null || $currentLon === null) {
            return false; // Cannot determine without coordinates
        }

        $distance = $this->calculateDistance($lastLat, $lastLon, $currentLat, $currentLon);

        return $distance > self::LOCATION_THRESHOLD_KM;
    }

    /**
     * Check if travel between locations is impossible.
     *
     * @param array $lastActivity
     * @param array $currentContext
     * @return bool
     */
    private function isImpossibleTravel(array $lastActivity, array $currentContext): bool
    {
        $lastLat = $lastActivity['latitude'] ?? null;
        $lastLon = $lastActivity['longitude'] ?? null;
        $lastTime = $lastActivity['timestamp'] ?? null;
        $currentLat = $currentContext['latitude'] ?? null;
        $currentLon = $currentContext['longitude'] ?? null;
        $currentTime = time();

        if ($lastLat === null || $lastLon === null || $lastTime === null || $currentLat === null || $currentLon === null) {
            return false;
        }

        $timeDiff = $currentTime - $lastTime;
        if ($timeDiff > self::IMPOSSIBLE_TRAVEL_WINDOW) {
            return false; // Enough time has passed
        }

        $distance = $this->calculateDistance($lastLat, $lastLon, $currentLat, $currentLon);

        // Maximum speed: 900 km/h (average plane speed)
        $maxDistance = ($timeDiff / 3600) * 900;

        return $distance > $maxDistance;
    }

    /**
     * Check if device has changed.
     *
     * @param array $lastActivity
     * @param array $currentContext
     * @return bool
     */
    private function isDeviceChange(array $lastActivity, array $currentContext): bool
    {
        $lastFingerprint = $lastActivity['device_fingerprint'] ?? null;
        $currentFingerprint = $currentContext['device_fingerprint'] ?? null;

        if ($lastFingerprint === null || $currentFingerprint === null) {
            // Fallback to user agent comparison
            $lastUa = $lastActivity['user_agent'] ?? null;
            $currentUa = $currentContext['user_agent'] ?? null;

            if ($lastUa === null || $currentUa === null) {
                return false;
            }

            return $lastUa !== $currentUa;
        }

        return $lastFingerprint !== $currentFingerprint;
    }

    /**
     * Check if IP has changed.
     *
     * @param array $lastActivity
     * @param array $currentContext
     * @return bool
     */
    private function isIpChange(array $lastActivity, array $currentContext): bool
    {
        $lastIp = $lastActivity['ip'] ?? null;
        $currentIp = $currentContext['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? null;

        if ($lastIp === null || $currentIp === null) {
            return false;
        }

        return $lastIp !== $currentIp;
    }

    /**
     * Count recent failed login attempts.
     *
     * @param array $activities
     * @param int $window Time window in seconds
     * @return int
     */
    private function countRecentFailures(array $activities, int $window): int
    {
        $cutoff = time() - $window;
        $failures = 0;

        foreach ($activities as $activity) {
            if ($activity['timestamp'] >= $cutoff && !($activity['success'] ?? true)) {
                $failures++;
            }
        }

        return $failures;
    }

    /**
     * Count unique IPs in recent activities.
     *
     * @param array $activities
     * @param int $window Time window in seconds
     * @return int
     */
    private function countUniqueIps(array $activities, int $window): int
    {
        $cutoff = time() - $window;
        $ips = [];

        foreach ($activities as $activity) {
            if ($activity['timestamp'] >= $cutoff && isset($activity['ip'])) {
                $ips[$activity['ip']] = true;
            }
        }

        return count($ips);
    }

    /**
     * Check if login time is unusual based on historical pattern.
     *
     * @param array $activities
     * @param array $currentContext
     * @return bool
     */
    private function isUnusualTime(array $activities, array $currentContext): bool
    {
        // Simple heuristic: Check if current hour is unusual
        // In production, you'd want more sophisticated pattern analysis

        $currentHour = (int) date('G'); // 0-23

        // Count activities by hour
        $hourCounts = array_fill(0, 24, 0);

        foreach ($activities as $activity) {
            if ($activity['success'] ?? true) {
                $hour = (int) date('G', $activity['timestamp']);
                $hourCounts[$hour]++;
            }
        }

        // If no historical data, not unusual
        $totalSuccessful = array_sum($hourCounts);
        if ($totalSuccessful < 5) {
            return false;
        }

        // Check if current hour has very low historical activity
        $currentHourCount = $hourCounts[$currentHour];
        $averageCount = $totalSuccessful / 24;

        // Unusual if less than 10% of average
        return $currentHourCount < ($averageCount * 0.1);
    }

    /**
     * Calculate distance between two coordinates (Haversine formula).
     *
     * @param float $lat1 Latitude 1
     * @param float $lon1 Longitude 1
     * @param float $lat2 Latitude 2
     * @param float $lon2 Longitude 2
     * @return float Distance in kilometers
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // km

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Get activity history for user.
     *
     * @param string $userId
     * @return array
     */
    private function getActivities(string $userId): array
    {
        $key = $this->getActivityKey($userId);
        $activities = $this->cache->get($key, []);

        return is_array($activities) ? $activities : [];
    }

    /**
     * Get cache key for activities.
     *
     * @param string $userId
     * @return string
     */
    private function getActivityKey(string $userId): string
    {
        return 'suspicious_activity:' . hash('sha256', $userId);
    }
}
