<?php

declare(strict_types=1);

namespace Toporia\Framework\Concurrency\Console;

use Toporia\Framework\Concurrency\Contracts\ClosureSerializerInterface;
use Toporia\Framework\Concurrency\Drivers\ProcessConcurrencyDriver;
use Toporia\Framework\Console\Command;
use Throwable;

/**
 * Invoke Serialized Closure Command
 *
 * Console command that executes a serialized closure passed via environment variable.
 * This is the bridge between ProcessConcurrencyDriver and actual closure execution.
 *
 * How it works:
 * 1. Reads serialized closure from TOPORIA_INVOKABLE_CLOSURE env var
 * 2. Decodes (base64) and deserializes the closure
 * 3. Executes the closure
 * 4. Serializes the result and writes to stdout
 *
 * This command should NOT be called directly by users.
 * It's invoked by ProcessConcurrencyDriver internally.
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class InvokeSerializedClosureCommand extends Command
{
    /**
     * Command signature.
     */
    protected string $signature = 'concurrency:invoke';

    /**
     * Command description.
     */
    protected string $description = 'Execute a serialized closure (internal use only)';

    /**
     * Hide from command list.
     */
    protected bool $hidden = true;

    /**
     * Closure serializer.
     */
    private ClosureSerializerInterface $serializer;

    /**
     * Set the serializer (called by service provider).
     */
    public function setSerializer(ClosureSerializerInterface $serializer): void
    {
        $this->serializer = $serializer;
    }

    /**
     * Execute the command.
     */
    public function handle(): int
    {
        // Read encoded closure from environment
        $encodedPayload = getenv(ProcessConcurrencyDriver::ENV_CLOSURE);

        if ($encodedPayload === false || $encodedPayload === '') {
            $this->writeError('Missing ' . ProcessConcurrencyDriver::ENV_CLOSURE . ' environment variable.');
            return self::FAILURE;
        }

        try {
            // Decode the base64 payload
            $serializedClosure = $this->serializer->decode($encodedPayload);

            // Deserialize the closure
            $closure = $this->serializer->unserializeClosure($serializedClosure);

            // Execute the closure
            $result = $closure();

            // Serialize and output the result
            $serializedResult = $this->serializer->serializeResult($result);

            // Write directly to stdout (no formatting)
            fwrite(STDOUT, $serializedResult);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->writeError('Error invoking closure: ' . $e->getMessage());
            $this->writeError('Stack trace: ' . $e->getTraceAsString());

            return self::FAILURE;
        }
    }

    /**
     * Write error message to stderr.
     */
    private function writeError(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }
}
