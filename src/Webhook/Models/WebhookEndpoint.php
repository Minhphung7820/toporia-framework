<?php

declare(strict_types=1);

namespace Toporia\Framework\Webhook\Models;

use Toporia\Framework\Database\ORM\Model;

/**
 * Class WebhookEndpoint
 *
 * Represents a webhook endpoint configuration.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Webhook\Models
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class WebhookEndpoint extends Model
{
    protected $table = 'webhook_endpoints';

    protected $fillable = [
        'name',
        'url',
        'secret',
        'events',
        'active',
        'timeout',
        'retry_count',
        'retry_delay',
        'headers',
        'metadata',
    ];

    protected $casts = [
        'events' => 'array',
        'active' => 'boolean',
        'timeout' => 'integer',
        'retry_count' => 'integer',
        'retry_delay' => 'integer',
        'headers' => 'array',
        'metadata' => 'array',
    ];

    /**
     * Check if endpoint should receive event.
     *
     * @param string $event Event name
     * @return bool
     */
    public function shouldReceive(string $event): bool
    {
        if (!$this->active) {
            return false;
        }

        $events = $this->events ?? [];

        // Empty events array means all events
        if (empty($events)) {
            return true;
        }

        // Check exact match
        if (in_array($event, $events, true)) {
            return true;
        }

        // Check wildcard patterns (e.g., 'user.*')
        foreach ($events as $pattern) {
            if (fnmatch($pattern, $event)) {
                return true;
            }
        }

        return false;
    }
}

