<?php

namespace justinholtweb\bird\models;

use Craft;
use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;
use justinholtweb\bird\Plugin;

/**
 * A Moneybird document Bird created for an order.
 *
 * One row per (order, kind, sourceKey): the invoice itself has an empty source key, and each
 * credit note carries the id of the Commerce refund transaction that caused it. That triple is
 * unique in the database, and that index *is* the guarantee that a retried job, a double-clicked
 * button and a webhook racing the queue cannot book the same revenue twice.
 */
class Document extends Model
{
    public const KIND_INVOICE = 'invoice';
    public const KIND_CREDIT = 'credit';

    public const STATE_PENDING = 'pending';
    public const STATE_FAILED = 'failed';

    public ?int $id = null;
    public ?int $orderId = null;
    public string $kind = self::KIND_INVOICE;

    /**
     * Empty for the order's own invoice; the Commerce transaction hash for a credit note.
     */
    public string $sourceKey = '';

    /**
     * `sales_invoice` or `external_sales_invoice`.
     */
    public string $documentType = Settings::DOCUMENT_SALES_INVOICE;

    /**
     * Moneybird's id for the document. Null while a push is still pending or has failed.
     */
    public ?string $moneybirdId = null;

    /**
     * Moneybird's own invoice number. Null while the invoice is a draft — Moneybird only assigns
     * one when the invoice is sent.
     */
    public ?string $invoiceNumber = null;

    /**
     * The reference Bird sent, i.e. the Commerce order number.
     */
    public ?string $reference = null;

    /**
     * Moneybird's state (`draft`, `open`, `paid`, …), or Bird's own `pending`/`failed` while the
     * document does not exist on the other end yet.
     */
    public string $state = self::STATE_PENDING;

    public ?string $currency = null;
    public float $total = 0.0;
    public float $totalPaid = 0.0;
    public ?string $administrationId = null;
    public ?string $taxTreatment = null;

    /**
     * The public payment URL Moneybird generates, safe to show a customer.
     */
    public ?string $publicUrl = null;

    public ?DateTime $dateSent = null;
    public ?DateTime $datePaid = null;
    public ?DateTime $dateSynced = null;

    public int $attempts = 0;
    public ?string $lastError = null;

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        foreach (['dateSent', 'datePaid', 'dateSynced', 'dateCreated', 'dateUpdated'] as $attribute) {
            $value = $this->$attribute;

            if ($value !== null && !$value instanceof DateTime) {
                $this->$attribute = DateTimeHelper::toDateTime($value) ?: null;
            }
        }

        $this->total = (float)$this->total;
        $this->totalPaid = (float)$this->totalPaid;
    }

    public function getIsCredit(): bool
    {
        return $this->kind === self::KIND_CREDIT;
    }

    public function getIsBooked(): bool
    {
        return $this->moneybirdId !== null && $this->moneybirdId !== '';
    }

    public function getIsPaid(): bool
    {
        return $this->state === 'paid';
    }

    /**
     * The document inside Moneybird's web app, for a merchant clicking through from the CP.
     *
     * Deliberately not the `url` Moneybird returns on the resource: that one is the *public*
     * link, handed out to customers, and it is the wrong thing to put behind a CP button.
     */
    public function getMoneybirdUrl(): ?string
    {
        if (!$this->getIsBooked()) {
            return null;
        }

        $administrationId = $this->administrationId
            ?: Plugin::getInstance()->getSettings()->getParsedAdministrationId();

        if ($administrationId === '') {
            return null;
        }

        $resource = $this->documentType === Settings::DOCUMENT_EXTERNAL_SALES_INVOICE
            ? 'external_sales_invoices'
            : 'sales_invoices';

        return "https://moneybird.com/$administrationId/$resource/{$this->moneybirdId}";
    }

    /**
     * What to call this document on screen.
     */
    public function getLabel(): string
    {
        if ($this->invoiceNumber) {
            return $this->invoiceNumber;
        }

        if ($this->reference) {
            return $this->reference;
        }

        return $this->moneybirdId ?? Craft::t('bird', 'Not booked');
    }

    public function getStateLabel(): string
    {
        return match ($this->state) {
            self::STATE_PENDING => Craft::t('bird', 'Pending'),
            self::STATE_FAILED => Craft::t('bird', 'Failed'),
            'draft' => Craft::t('bird', 'Draft'),
            'scheduled' => Craft::t('bird', 'Scheduled'),
            'open' => Craft::t('bird', 'Open'),
            'pending_payment' => Craft::t('bird', 'Pending payment'),
            'reminded' => Craft::t('bird', 'Reminded'),
            'late' => Craft::t('bird', 'Late'),
            'paid' => Craft::t('bird', 'Paid'),
            'uncollectible' => Craft::t('bird', 'Uncollectible'),
            'new' => Craft::t('bird', 'Booked'),
            default => $this->state,
        };
    }

    /**
     * Green, orange or red for the CP status dot.
     */
    public function getStatusColor(): string
    {
        return match ($this->state) {
            'paid' => 'green',
            self::STATE_FAILED => 'red',
            'late', 'reminded', 'uncollectible' => 'orange',
            self::STATE_PENDING, 'draft' => 'orange',
            default => 'blue',
        };
    }
}
