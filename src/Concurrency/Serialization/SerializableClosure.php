<?php

declare(strict_types=1);

namespace Toporia\Framework\Concurrency\Serialization;

use Closure;
use ReflectionFunction;
use Throwable;

/**
 * Serializable Closure
 *
 * Custom implementation for serializing PHP closures.
 * Captures closure code, use variables, and binding context.
 *
 * How it works:
 * 1. Extract closure code using ReflectionFunction
 * 2. Capture used variables from closure scope
 * 3. Serialize as a bundle of code + variables
 * 4. On unserialize, recreate closure using eval (with security checks)
 *
 * Limitations:
 * - Cannot serialize closures that use $this (bound closures)
 * - Cannot serialize closures with unserializable use variables
 * - Requires the same class/function context on unserialize
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class SerializableClosure
{
    /**
     * The original closure (not serialized).
     */
    private ?Closure $closure = null;

    /**
     * Serialized closure data.
     *
     * @var array{
     *     code: string,
     *     variables: array<string, mixed>,
     *     binding: string|null,
     *     scope: string|null
     * }|null
     */
    private ?array $data = null;

    /**
     * Secret key for signature verification.
     */
    private static ?string $secretKey = null;

    /**
     * Create a new SerializableClosure instance.
     */
    public function __construct(Closure $closure)
    {
        $this->closure = $closure;
    }

    /**
     * Set the secret key for closure signing.
     */
    public static function setSecretKey(?string $key): void
    {
        self::$secretKey = $key;
    }

    /**
     * Get the underlying closure.
     */
    public function getClosure(): Closure
    {
        if ($this->closure !== null) {
            return $this->closure;
        }

        if ($this->data === null) {
            throw new \RuntimeException('Closure has not been initialized');
        }

        return $this->recreateClosure();
    }

    /**
     * Invoke the closure.
     *
     * @param mixed ...$args Arguments to pass to the closure
     * @return mixed
     */
    public function __invoke(mixed ...$args): mixed
    {
        return ($this->getClosure())(...$args);
    }

    /**
     * Serialize the closure.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        if ($this->closure === null) {
            throw new \RuntimeException('No closure to serialize');
        }

        $reflection = new ReflectionFunction($this->closure);

        // Check for $this binding - try to unbind if possible
        $binding = $reflection->getClosureThis();
        if ($binding !== null) {
            // Try to unbind the closure from $this
            $unbound = $this->closure->bindTo(null);
            if ($unbound !== null) {
                $this->closure = $unbound;
                $reflection = new ReflectionFunction($this->closure);
            } else {
                // Closure actually uses $this internally
                throw new \RuntimeException(
                    'Cannot serialize closures that use $this. Use static closures instead.'
                );
            }
        }

        // Extract closure code
        $code = $this->extractClosureCode($reflection);

        // Get used variables
        $variables = $reflection->getStaticVariables();

        // Get scope class
        $scopeClass = $reflection->getClosureScopeClass();
        $scope = $scopeClass?->getName();

        // Extract namespace and use statements from source file
        $filename = $reflection->getFileName();
        $useStatements = $filename ? $this->extractUseStatements($filename) : [];

        $data = [
            'code' => $code,
            'variables' => $this->serializeVariables($variables),
            'binding' => null,
            'scope' => $scope,
            'use_statements' => $useStatements,
        ];

        // Sign the data if secret key is set
        if (self::$secretKey !== null) {
            $data['signature'] = $this->sign($data);
        }

        return $data;
    }

    /**
     * Unserialize the closure.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        // Verify signature if secret key is set
        if (self::$secretKey !== null) {
            if (!isset($data['signature'])) {
                throw new \RuntimeException('Closure signature is missing');
            }

            $signature = $data['signature'];
            unset($data['signature']);

            if (!$this->verify($data, $signature)) {
                throw new \RuntimeException('Closure signature verification failed');
            }
        }

        $this->data = [
            'code' => $data['code'],
            'variables' => $data['variables'],
            'binding' => $data['binding'] ?? null,
            'scope' => $data['scope'] ?? null,
            'use_statements' => $data['use_statements'] ?? [],
        ];

        $this->closure = null;
    }

    /**
     * Extract closure code from reflection.
     */
    private function extractClosureCode(ReflectionFunction $reflection): string
    {
        $filename = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        if ($filename === false || $startLine === false || $endLine === false) {
            throw new \RuntimeException('Cannot extract closure code: source not available');
        }

        $lines = file($filename);
        if ($lines === false) {
            throw new \RuntimeException('Cannot read source file: ' . $filename);
        }

        // Extract the relevant lines
        $codeLines = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);
        $code = implode('', $codeLines);

        // Extract just the closure part
        $code = $this->extractClosureFromCode($code, $reflection);

        return $code;
    }

    /**
     * Extract the closure definition from code.
     */
    private function extractClosureFromCode(string $code, ReflectionFunction $reflection): string
    {
        // Try to find the closure pattern using balanced brace matching for function closures
        // Handle: fn() =>, function(), static fn(), static function()

        // First, try to match arrow functions (simpler pattern)
        $arrowPatterns = [
            '/static\s+fn\s*\([^)]*\)\s*(?::\s*\S+\s*)?=>\s*[^;,\]]+/s',
            '/fn\s*\([^)]*\)\s*(?::\s*\S+\s*)?=>\s*[^;,\]]+/s',
        ];

        foreach ($arrowPatterns as $pattern) {
            if (preg_match($pattern, $code, $matches)) {
                return $this->normalizeClosureCode($matches[0]);
            }
        }

        // For function closures, use balanced brace matching
        $functionPattern = '/(static\s+)?function\s*\([^)]*\)\s*(?:use\s*\([^)]*\)\s*)?(?::\s*\S+\s*)?\{/s';
        if (preg_match($functionPattern, $code, $matches, PREG_OFFSET_CAPTURE)) {
            $start = (int) $matches[0][1];
            $braceStart = strpos($code, '{', $start);

            if ($braceStart !== false) {
                $closureCode = $this->extractBalancedBraces($code, $braceStart);
                if ($closureCode !== null) {
                    return $this->normalizeClosureCode(substr($code, $start, strlen($closureCode) + $braceStart - $start + 1));
                }
            }
        }

        // Fallback: return code as-is
        return trim($code);
    }

    /**
     * Extract code with balanced braces starting from given position.
     */
    private function extractBalancedBraces(string $code, int $start): ?string
    {
        $length = strlen($code);
        $depth = 0;
        $inString = false;
        $stringChar = '';
        $escaped = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $code[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if (!$inString) {
                if ($char === '"' || $char === "'") {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return substr($code, $start, $i - $start + 1);
                    }
                }
            } else {
                if ($char === $stringChar) {
                    $inString = false;
                }
            }
        }

        return null;
    }

    /**
     * Normalize closure code.
     */
    private function normalizeClosureCode(string $code): string
    {
        // Remove trailing semicolons and clean up
        $code = rtrim(trim($code), ';,');
        return $code;
    }

    /**
     * Serialize variables for storage.
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function serializeVariables(array $variables): array
    {
        $serialized = [];

        foreach ($variables as $name => $value) {
            if ($value instanceof Closure) {
                // Recursively serialize closures
                $serialized[$name] = ['__closure__' => serialize(new self($value))];
            } else {
                try {
                    serialize($value); // Test if serializable
                    $serialized[$name] = $value;
                } catch (Throwable $e) {
                    throw new \RuntimeException(
                        "Cannot serialize variable '\${$name}': " . $e->getMessage()
                    );
                }
            }
        }

        return $serialized;
    }

    /**
     * Recreate closure from serialized data.
     */
    private function recreateClosure(): Closure
    {
        if ($this->data === null) {
            throw new \RuntimeException('No data to recreate closure from');
        }

        $code = $this->data['code'];
        $variables = $this->unserializeVariables($this->data['variables']);
        $useStatements = $this->data['use_statements'] ?? [];

        // Build variable extraction code
        $varExtractions = [];
        foreach ($variables as $name => $value) {
            $varExtractions[] = "\${$name} = \$__variables__['{$name}'];";
        }
        $varCode = implode("\n", $varExtractions);

        // Build class alias code from use statements
        $aliasCode = $this->buildClassAliasCode($useStatements);

        // Build the eval code
        $evalCode = <<<PHP
return (function() use (\$__variables__) {
    {$aliasCode}
    {$varCode}
    return {$code};
})();
PHP;

        try {
            $__variables__ = $variables;
            $closure = eval($evalCode);

            if (!$closure instanceof Closure) {
                throw new \RuntimeException('Failed to recreate closure: result is not a Closure');
            }

            $this->closure = $closure;
            return $closure;
        } catch (Throwable $e) {
            throw new \RuntimeException(
                'Failed to recreate closure: ' . $e->getMessage()
            );
        }
    }

    /**
     * Unserialize variables.
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function unserializeVariables(array $variables): array
    {
        $unserialized = [];

        foreach ($variables as $name => $value) {
            if (is_array($value) && isset($value['__closure__'])) {
                // Recursively unserialize closures
                $wrapper = unserialize($value['__closure__']);
                $unserialized[$name] = $wrapper->getClosure();
            } else {
                $unserialized[$name] = $value;
            }
        }

        return $unserialized;
    }

    /**
     * Extract use statements from source file.
     *
     * @param string $filename Source file path
     * @return array<string, string> Map of alias => fully qualified class name
     */
    private function extractUseStatements(string $filename): array
    {
        $content = file_get_contents($filename);
        if ($content === false) {
            return [];
        }

        $useStatements = [];

        // Match use statements: use Foo\Bar\Baz; or use Foo\Bar\Baz as Alias;
        $pattern = '/^use\s+([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*)(?:\s+as\s+([a-zA-Z_][a-zA-Z0-9_]*))?\s*;/m';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fqcn = $match[1];
                // If there's an alias, use it; otherwise use the short class name
                $alias = $match[2] ?? $this->getShortClassName($fqcn);
                $useStatements[$alias] = $fqcn;
            }
        }

        return $useStatements;
    }

    /**
     * Get short class name from fully qualified class name.
     *
     * @param string $fqcn Fully qualified class name
     * @return string Short class name
     */
    private function getShortClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts) ?: $fqcn;
    }

    /**
     * Build class alias code from use statements.
     *
     * Creates class_alias calls to make short class names available.
     *
     * @param array<string, string> $useStatements Map of alias => FQCN
     * @return string PHP code for class aliases
     */
    private function buildClassAliasCode(array $useStatements): string
    {
        if (empty($useStatements)) {
            return '';
        }

        $aliasLines = [];
        foreach ($useStatements as $alias => $fqcn) {
            // Only create alias if class exists and alias doesn't already exist
            $aliasLines[] = sprintf(
                'if (class_exists(\'%s\') && !class_exists(\'%s\', false)) { class_alias(\'%s\', \'%s\'); }',
                addslashes($fqcn),
                addslashes($alias),
                addslashes($fqcn),
                addslashes($alias)
            );
        }

        return implode("\n    ", $aliasLines);
    }

    /**
     * Sign data with secret key.
     *
     * @param array<string, mixed> $data
     */
    private function sign(array $data): string
    {
        return hash_hmac('sha256', serialize($data), self::$secretKey ?? '');
    }

    /**
     * Verify signature.
     *
     * @param array<string, mixed> $data
     */
    private function verify(array $data, string $signature): bool
    {
        $expected = $this->sign($data);
        return hash_equals($expected, $signature);
    }
}
