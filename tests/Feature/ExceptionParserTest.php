<?php

declare(strict_types=1);

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use StepDispatcher\Support\ExceptionParser;

/*
|--------------------------------------------------------------------------
| ExceptionParser
|--------------------------------------------------------------------------
|
| Every Failed step's error_message and error_stack_trace come from here.
| For plain throwables it formats "Class: message in file on line N"; for
| Guzzle RequestExceptions it digs the exchange's JSON {code,msg} out of
| the response body so operators see the API's own error, not a generic
| HTTP failure.
|
*/

it('formats a plain exception with class, message, and line', function (): void {
    $parser = ExceptionParser::with(new RuntimeException('something broke'));

    expect($parser->className())->toBe('RuntimeException')
        ->and($parser->errorMessage())->toBe('something broke')
        ->and($parser->stackTrace())->toBeString()
        ->and($parser->friendlyMessage())
        ->toContain('RuntimeException')
        ->toContain('something broke')
        ->toContain('on line');
});

it('extracts the JSON code and msg from a Guzzle RequestException body', function (): void {
    $response = new Response(429, [], json_encode(['code' => -1003, 'msg' => 'Too many requests']));
    $exception = new RequestException(
        'Client error',
        new Request('GET', 'https://api.example/test'),
        $response
    );

    $parser = ExceptionParser::with($exception);

    expect($parser->httpStatusCode())->toBe(429)
        ->and($parser->errorCode())->toBe(-1003)
        ->and($parser->errorMsg())->toBe('Too many requests')
        ->and($parser->friendlyMessage())
        ->toContain('Too many requests')
        ->toContain('code -1003');
});

it('leaves HTTP fields null for a non-HTTP exception', function (): void {
    $parser = ExceptionParser::with(new RuntimeException('plain'));

    expect($parser->httpStatusCode())->toBeNull()
        ->and($parser->errorCode())->toBeNull()
        ->and($parser->errorMsg())->toBeNull();
});
