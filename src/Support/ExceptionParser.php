<?php

declare(strict_types=1);

namespace StepDispatcher\Support;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Str;
use ReflectionException;
use ReflectionMethod;
use Throwable;

final class ExceptionParser
{
    private string $filename;

    private int $line;

    private string $classname;

    private string $originalMessage;

    private string $stackTrace;

    private ?int $httpStatusCode = null;

    private int|string|null $errorCode = null;

    private ?string $errorMsg = null;

    public function __construct(Throwable $e)
    {
        $basePath = base_path();

        $this->classname = class_basename($e);
        $this->originalMessage = $e->getMessage();
        $this->line = $e->getLine();
        $this->filename = str_replace(search: $basePath.DIRECTORY_SEPARATOR, replace: '', subject: $e->getFile());
        $this->stackTrace = $e->getTraceAsString();

        foreach ($e->getTrace() as $frame) {
            $class = $frame['class'] ?? null;
            $function = $frame['function'] ?? null;

            if ($class && $function && method_exists($class, $function)) {
                try {
                    $method = new ReflectionMethod($class, $function);
                    $file = $method->getFileName();

                    if ($file &&
                        str_starts_with(haystack: $file, needle: $basePath) &&
                        ! str_contains(haystack: $file, needle: DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)
                    ) {
                        $this->filename = str_replace(search: $basePath.DIRECTORY_SEPARATOR, replace: '', subject: $file);
                        break;
                    }
                } catch (ReflectionException) {
                    continue;
                }
            }
        }

        if ($e instanceof RequestException && $e->hasResponse()) {
            $this->httpStatusCode = $e->getResponse()->getStatusCode();

            $body = (string) $e->getResponse()->getBody();
            $json = json_decode($body, associative: true);

            if (is_array($json)) {
                $code = $json['code'] ?? null;
                $this->errorCode = is_int($code) || is_string($code) ? $code : null;
                $this->errorMsg = isset($json['msg']) && is_string($json['msg']) ? $json['msg'] : null;
            }
        }
    }

    public static function with(Throwable $e): self
    {
        return new self($e);
    }

    public function friendlyMessage(): string
    {
        $base = "{$this->classname}: ".($this->errorMsg ?? $this->originalMessage);

        if ($this->errorCode !== null) {
            $base .= " (code {$this->errorCode})";
        }

        return Str::limit(sprintf('%s in %s on line %d', $base, $this->filename, $this->line), 16000, '');
    }

    public function errorMessage(): string
    {
        return $this->originalMessage;
    }

    public function className(): string
    {
        return $this->classname;
    }

    public function lineNumber(): int
    {
        return $this->line;
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function stackTrace(): string
    {
        return $this->stackTrace;
    }

    public function httpStatusCode(): ?int
    {
        return $this->httpStatusCode;
    }

    public function errorCode(): int|string|null
    {
        return $this->errorCode;
    }

    public function errorMsg(): ?string
    {
        return $this->errorMsg;
    }
}
