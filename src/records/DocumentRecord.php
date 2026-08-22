<?php

namespace justinholtweb\bird\records;

use craft\db\ActiveRecord;
use justinholtweb\bird\db\Table;

/**
 * @property int $id
 * @property int $orderId
 * @property string $kind
 * @property string $sourceKey
 * @property string $documentType
 * @property string|null $moneybirdId
 * @property string|null $invoiceNumber
 * @property string|null $reference
 * @property string $state
 * @property string|null $currency
 * @property string $total
 * @property string $totalPaid
 * @property string|null $administrationId
 * @property string|null $taxTreatment
 * @property string|null $publicUrl
 * @property int $attempts
 * @property string|null $lastError
 */
class DocumentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::DOCUMENTS;
    }
}
