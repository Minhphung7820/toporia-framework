<?php

declare(strict_types=1);

namespace Toporia\Framework\Webhook\Models;

use Toporia\Framework\Database\ORM\Model;

/**
 * Class WebhookDelivery
 *
 * Tracks webhook delivery attempts and results.
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
final class WebhookDelivery extends Model
{
    protected $table = 'webhook_deliveries';

    protected $fillable = [
        'endpoint_id',
        'event',
        'payload',
        'status_code',
        'response_body',
        'attempts',
        'succeeded_at',
        'failed_at',
        'error_message',
    ];

    protected $casts = [
        'payload' => 'array',
        'status_code' => 'integer',
        'response_body' => 'string',
        'attempts' => 'integer',
        'succeeded_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /**
     * Relationship to webhook endpoint.
     *
     * @return \Toporia\Framework\Database\ORM\Relations\BelongsTo
     */
    public function endpoint()
    {
        return $this->belongsTo(WebhookEndpoint::class, 'endpoint_id');
    }

    /**
     * Mark delivery as succeeded.
     *
     * @param int $statusCode HTTP status code
     * @param string|null $responseBody Response body
     * @return bool
     */
    public function markSucceeded(int $statusCode, ?string $responseBody = null): bool
    {
        $this->fill([
            'status_code' => $statusCode,
            'response_body' => $responseBody,
            'succeeded_at' => now()->toDateTimeString(),
            'failed_at' => null,
            'error_message' => null,
        ]);

        return $this->save();
    }

    /**
     * Mark delivery as failed.
     *
     * @param string $errorMessage Error message
     * @return bool
     */
    public function markFailed(string $errorMessage): bool
    {
        // Increment attempts
        $currentAttempts = $this->getAttribute('attempts') ?? 0;

        $this->fill([
            'attempts' => $currentAttempts + 1,
            'failed_at' => now()->toDateTimeString(),
            'error_message' => $errorMessage,
        ]);

        return $this->save();
    }
}

