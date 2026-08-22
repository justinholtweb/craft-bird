<?php

namespace justinholtweb\bird\exceptions;

use yii\base\Exception;

/**
 * Bird cannot describe this order to Moneybird under the settings it has been given.
 *
 * Always the merchant's configuration, never Moneybird being down: an unmapped VAT percentage, a
 * missing ledger account, an order whose lines do not add up to what was paid. Retrying changes
 * nothing, so a job that hits one stops instead of grinding.
 */
class MappingException extends Exception
{
    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Bird mapping error';
    }
}
