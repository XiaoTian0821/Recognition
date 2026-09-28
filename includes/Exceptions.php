<?php
declare(strict_types=1);

/** Thrown when no AI provider is usable (no API key configured). */
class ProviderNotConfiguredException extends RuntimeException
{
}

/**
 * Thrown when one provider request fails.
 * The message is safe to log and inspect: it never contains API keys.
 */
class ProviderException extends RuntimeException
{
}

/**
 * Thrown when recognition failed for every provider in the chain.
 * @var string[] $details safe, human-readable failure reasons (no secrets)
 */
class RecognitionException extends RuntimeException
{
    /** @var string[] */
    public array $details;

    /**
     * @param string[] $details
     */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message);
        $this->details = $details;
    }
}
