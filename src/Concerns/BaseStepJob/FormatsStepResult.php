<?php

declare(strict_types=1);

namespace StepDispatcher\Concerns\BaseStepJob;

use Psr\Http\Message\ResponseInterface;

/**
 * Trait FormatsStepResult
 *
 * Provides utilities for formatting and extracting job results
 * before storage in the Step model.
 */
trait FormatsStepResult
{
    /**
     * Format the compute() result before saving to step->response.
     *
     * Override this method in your job to transform results.
     * Examples: filtering sensitive data, normalizing structure, etc.
     */
    protected function formatResultForStorage($result): mixed
    {
        return $result;
    }

    /**
     * Extract and decode JSON from PSR-7 HTTP response.
     *
     * Safely handles seekable streams and falls back to raw string
     * if JSON decoding fails.
     */
    protected function extractBodyFromResponse(ResponseInterface $response): mixed
    {
        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        $content = (string) $body;

        return json_decode($content, associative: true) ?? $content;
    }
}
