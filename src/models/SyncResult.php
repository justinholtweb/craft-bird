<?php

namespace justinholtweb\bird\models;

use craft\base\Model;

/**
 * What came of trying to push one order.
 *
 * Three outcomes, not two: an order that was deliberately not pushed (already booked, zero total,
 * not yet paid) is not a failure, and reporting it as one trains merchants to ignore the log.
 */
class SyncResult extends Model
{
    public bool $success = false;
    public bool $skipped = false;
    public string $message = '';
    public ?Document $document = null;

    /**
     * Whether another attempt could plausibly work. False for a validation error in the
     * merchant's own mapping — retrying that just wedges the queue.
     */
    public bool $retryable = false;

    public static function ok(string $message, ?Document $document = null): self
    {
        return new self(['success' => true, 'message' => $message, 'document' => $document]);
    }

    public static function skip(string $message, ?Document $document = null): self
    {
        return new self(['success' => true, 'skipped' => true, 'message' => $message, 'document' => $document]);
    }

    public static function fail(string $message, bool $retryable = false, ?Document $document = null): self
    {
        return new self([
            'success' => false,
            'message' => $message,
            'retryable' => $retryable,
            'document' => $document,
        ]);
    }
}
