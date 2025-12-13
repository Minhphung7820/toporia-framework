<?php

declare(strict_types=1);

namespace Toporia\Framework\Http;

use Toporia\Framework\Http\Contracts\StreamedResponseInterface;
use Toporia\Framework\Support\Macroable;

/**
 * Class StreamedResponse
 *
 * Response that streams content using a callback function.
 * Useful for large files, real-time data, or memory-efficient responses.
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
 */
final class StreamedResponse extends Response implements StreamedResponseInterface
{
    use Macroable;

    /**
     * @var callable Stream callback function
     */
    private $callback;

    /**
     * @var bool Whether the response has been streamed
     */
    private bool $streamed = false;

    /**
     * Create a new streamed response.
     *
     * @param callable $callback Stream callback
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers
     */
    public function __construct(callable $callback, int $status = 200, array $headers = [])
    {
        parent::__construct('', $status, $headers);

        $this->setCallback($callback);
    }

    /**
     * {@inheritdoc}
     */
    public function setCallback(callable $callback): static
    {
        $this->callback = $callback;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getCallback(): callable
    {
        return $this->callback;
    }

    /**
     * {@inheritdoc}
     */
    public function sendContent(): void
    {
        if ($this->streamed) {
            return;
        }

        $this->streamed = true;

        if (is_callable($this->callback)) {
            call_user_func($this->callback);
        }
    }

    /**
     * Get response content (not applicable for streamed responses).
     *
     * @return string
     * @throws \LogicException
     */
    public function getContent(): string
    {
        throw new \LogicException('Cannot get content from a StreamedResponse');
    }

    /**
     * Set response content (not applicable for streamed responses).
     *
     * @param string $content
     * @return $this
     * @throws \LogicException
     */
    public function setContent(string $content): static
    {
        throw new \LogicException('Cannot set content on a StreamedResponse');
    }
}
