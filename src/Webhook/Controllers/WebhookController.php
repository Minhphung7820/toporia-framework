<?php

declare(strict_types=1);

namespace Toporia\Framework\Webhook\Controllers;

use Toporia\Framework\Http\{Request, JsonResponse};
use Toporia\Framework\Webhook\Contracts\WebhookReceiverInterface;
use Toporia\Framework\Webhook\WebhookManager;
use Toporia\Framework\Container\Contracts\ContainerInterface;

/**
 * Class WebhookController
 *
 * Handles incoming webhook requests.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Webhook\Controllers
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class WebhookController
{
    /**
     * @param WebhookReceiverInterface $receiver Webhook receiver
     * @param WebhookManager $manager Webhook manager
     * @param ContainerInterface $container Dependency injection container
     */
    public function __construct(
        private WebhookReceiverInterface $receiver,
        private WebhookManager $manager,
        private ContainerInterface $container
    ) {}

    /**
     * Handle incoming webhook.
     *
     * @param Request $request HTTP request
     * @param string|null $provider Optional provider name
     * @return JsonResponse
     */
    public function handle(Request $request, ?string $provider = null): JsonResponse
    {
        try {
            // Get secret from config
            $secret = '';
            if ($this->container->has('config')) {
                $config = $this->container->get('config');
                $secret = $config->get('webhook.secret', '');
            }

            if (empty($secret)) {
                throw new \RuntimeException('Webhook secret not configured');
            }

            $data = $this->receiver->process($request, $secret, function ($event, $payload, $request) {
                // Custom handler can be registered via events
                // For now, just log
            });

            return new JsonResponse([
                'success' => true,
                'message' => 'Webhook processed successfully',
                'event' => $data['event'],
            ], 200);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Webhook processing failed',
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}

