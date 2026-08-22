<?php

namespace justinholtweb\bird\exceptions;

use yii\base\Exception;

/**
 * A Moneybird API call that did not come back 2xx.
 *
 * Moneybird answers a 422 with a field-keyed error object rather than a message, so the raw body
 * is kept alongside the flattened message: the log screen shows the body, the merchant sees the
 * sentence.
 */
class ApiException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $body = null,
        public readonly ?string $path = null,
    ) {
        parent::__construct($message, $statusCode ?? 0);
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Moneybird API error';
    }

    /**
     * Whether retrying the same call could plausibly succeed.
     *
     * A 422 is the merchant's data being wrong — a ledger account that does not exist, a tax rate
     * from another administration. Retrying that forever just fills the queue with a stuck job.
     */
    public function isRetryable(): bool
    {
        if ($this->statusCode === null) {
            // No response at all: a timeout or a DNS failure. Worth another go.
            return true;
        }

        return $this->statusCode === 429 || $this->statusCode >= 500;
    }
}
