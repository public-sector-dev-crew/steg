<?php

// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 public-sector-dev-crew

declare(strict_types=1);

namespace Steg\Client;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Steg\Exception\ConnectionException;
use Steg\Exception\InferenceException;
use Steg\Model\CompletionOptions;
use Steg\Model\CompletionResponse;

/**
 * Transport-retry decorator over any {@see InferenceClientInterface} (steg v1.1).
 *
 * Retries only *transport-level* failures with bounded exponential backoff + full jitter:
 * connection errors/timeouts ({@see ConnectionException}) and HTTP 5xx / 429
 * ({@see InferenceException}). Client errors (other 4xx, model-not-found) and malformed
 * responses are NOT retried — a retry cannot fix them.
 *
 * Composition, not inheritance: the policy stays explicit and inspectable, the resilience
 * package (anker) can later wrap steg from the outside, and it is testable without a real
 * server (inject $sleeper). It is deliberately distinct from fender's semantic re-prompt — a
 * 200-with-wrong-body is not a transport failure and never reaches this decorator.
 *
 * HTTP 429 is retried with backoff; honouring a `Retry-After` header is deferred (the client
 * does not currently surface response headers).
 *
 * Streaming is only retryable *before the first chunk*: once bytes have been yielded there is
 * no partial-stream replay.
 */
final class RetryingInferenceClient implements InferenceClientInterface
{
    /** @var \Closure(int): void receives microseconds */
    private readonly \Closure $sleeper;

    /** @var \Closure(int): int receives max milliseconds, returns 0..max */
    private readonly \Closure $jitter;

    /**
     * @param int                        $maxAttempts total attempts including the first (>= 1; 1 disables retrying)
     * @param int                        $baseDelayMs base backoff in milliseconds (doubled each attempt, capped at $maxDelayMs)
     * @param (callable(int): void)|null $sleeper     injectable sleep (microseconds); defaults to usleep
     * @param (callable(int): int)|null  $jitter      injectable jitter source (0..max ms); defaults to random_int
     */
    public function __construct(
        private readonly InferenceClientInterface $inner,
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 200,
        private readonly int $maxDelayMs = 5000,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?callable $sleeper = null,
        ?callable $jitter = null,
    ) {
        $this->sleeper = null !== $sleeper
            ? \Closure::fromCallable($sleeper)
            : static function (int $microseconds): void { usleep($microseconds); };
        $this->jitter = null !== $jitter
            ? \Closure::fromCallable($jitter)
            : static fn (int $maxMillis): int => random_int(0, max(0, $maxMillis));
    }

    public function complete(array $messages, ?CompletionOptions $options = null): CompletionResponse
    {
        return $this->withRetry(fn () => $this->inner->complete($messages, $options));
    }

    public function stream(array $messages, ?CompletionOptions $options = null): \Generator
    {
        for ($attempt = 1;; ++$attempt) {
            $generator = $this->inner->stream($messages, $options);

            try {
                // Priming runs the inner generator up to its first yield — where the HTTP request and
                // status check live — so connection/5xx errors surface here, before any chunk is emitted.
                $generator->rewind();
            } catch (\Throwable $e) {
                if ($this->shouldRetry($e, $attempt)) {
                    $this->backoff($attempt);

                    continue;
                }

                throw $e;
            }

            // From the first chunk on we cannot replay — hand the stream through without retrying.
            while ($generator->valid()) {
                yield $generator->current();
                $generator->next();
            }

            return;
        }
    }

    public function listModels(): array
    {
        return $this->withRetry(fn () => $this->inner->listModels());
    }

    public function isHealthy(): bool
    {
        // isHealthy() never throws (returns false on failure) — nothing to retry.
        return $this->inner->isHealthy();
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function withRetry(callable $operation): mixed
    {
        for ($attempt = 1;; ++$attempt) {
            try {
                return $operation();
            } catch (\Throwable $e) {
                if (!$this->shouldRetry($e, $attempt)) {
                    throw $e;
                }

                $this->backoff($attempt);
            }
        }
    }

    private function shouldRetry(\Throwable $e, int $attempt): bool
    {
        return $attempt < $this->maxAttempts && $this->isRetryable($e);
    }

    private function isRetryable(\Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof InferenceException) {
            $status = $e->getHttpStatusCode();

            return $status >= 500 || 429 === $status;
        }

        return false;
    }

    private function backoff(int $attempt): void
    {
        $exponential = $this->baseDelayMs * 2 ** ($attempt - 1);
        $capped = (int) min($exponential, $this->maxDelayMs);
        // Full jitter (0..capped) spreads retries and avoids thundering-herd synchronisation.
        $delayMs = ($this->jitter)($capped);

        $this->logger->warning('steg: transport error, retrying', [
            'attempt' => $attempt,
            'delay_ms' => $delayMs,
        ]);

        ($this->sleeper)($delayMs * 1000);
    }
}
