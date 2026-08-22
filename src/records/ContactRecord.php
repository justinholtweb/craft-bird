<?php

namespace justinholtweb\bird\records;

use craft\db\ActiveRecord;
use justinholtweb\bird\db\Table;

/**
 * @property int $id
 * @property string $moneybirdContactId
 * @property string|null $customerId
 * @property int|null $userId
 * @property string|null $email
 * @property string|null $fingerprint
 * @property string|null $countryCode
 * @property string|null $vatNumber
 */
class ContactRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::CONTACTS;
    }
}
