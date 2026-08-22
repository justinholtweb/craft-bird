<?php

namespace justinholtweb\bird\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\Address;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\bird\db\Table;
use justinholtweb\bird\exceptions\ApiException;
use justinholtweb\bird\helpers\Eu;
use justinholtweb\bird\models\LogEntry;
use justinholtweb\bird\Plugin;

/**
 * Commerce customers as Moneybird contacts.
 *
 * The hard part is not creating a contact, it is *not* creating the fifth copy of one. Moneybird's
 * `customer_id` is the hook Bird hangs identity on: it is written on create, indexed by Moneybird,
 * and looked up directly (`GET /contacts/customer_id/{id}`) on every later order. The local
 * mapping table in front of it means a returning customer normally costs zero API calls at all.
 */
class Contacts extends Component
{
    /**
     * The Moneybird contact id to invoice this order to, creating or updating the contact as
     * needed.
     *
     * @throws ApiException
     */
    public function contactIdForOrder(Order $order): ?string
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->syncContacts) {
            return null;
        }

        $attributes = $this->buildAttributes($order);
        $customerId = $this->customerIdForOrder($order);
        $fingerprint = $this->fingerprint($attributes);

        $mapping = $this->findMapping($customerId, $order);

        if ($mapping !== null) {
            $contactId = (string)$mapping['moneybirdContactId'];

            if ($settings->updateExistingContacts && ($mapping['fingerprint'] ?? null) !== $fingerprint) {
                try {
                    Plugin::getInstance()->getApi()->updateContact($contactId, $attributes);
                } catch (ApiException $e) {
                    // A contact that has been deleted in Moneybird since Bird last saw it must not
                    // wedge the invoice: drop the stale mapping and fall through to a fresh look-up.
                    if ($e->statusCode !== 404) {
                        throw $e;
                    }

                    $this->deleteMapping($contactId);

                    return $this->contactIdForOrder($order);
                }

                $this->saveMapping($contactId, $customerId, $order, $attributes, $fingerprint);
            }

            return $contactId;
        }

        $remote = $this->findRemote($customerId, $order);

        if ($remote !== null) {
            $contactId = (string)$remote['id'];
            $this->saveMapping($contactId, $customerId, $order, $attributes, $fingerprint);

            Plugin::getInstance()->getLog()->write('contact', [
                'level' => LogEntry::LEVEL_INFO,
                'orderId' => $order->id,
                'summary' => Craft::t('bird', 'Matched existing Moneybird contact {id}', ['id' => $contactId]),
            ]);

            return $contactId;
        }

        $created = Plugin::getInstance()->getApi()->createContact($attributes);
        $contactId = (string)($created['id'] ?? '');

        if ($contactId === '') {
            throw new ApiException(Craft::t('bird', 'Moneybird created a contact but returned no id.'));
        }

        $this->saveMapping($contactId, $customerId, $order, $attributes, $fingerprint);

        Plugin::getInstance()->getLog()->write('contact', [
            'level' => LogEntry::LEVEL_INFO,
            'orderId' => $order->id,
            'summary' => Craft::t('bird', 'Created Moneybird contact {id}', ['id' => $contactId]),
        ]);

        return $contactId;
    }

    /**
     * The Moneybird contact payload for an order.
     *
     * @return array<string, mixed>
     */
    public function buildAttributes(Order $order): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $address = $this->addressForOrder($order);
        $email = (string)($order->getEmail() ?? '');

        $attributes = [
            'company_name' => $address?->organization ?: null,
            'firstname' => $address?->firstName ?: null,
            'lastname' => $address?->lastName ?: null,
            'address1' => $address?->addressLine1 ?: null,
            'address2' => trim(implode(' ', array_filter([$address?->addressLine2, $address?->addressLine3]))) ?: null,
            'zipcode' => $address?->postalCode ?: null,
            'city' => $address?->locality ?: null,
            'country' => $address?->countryCode ? strtoupper($address->countryCode) : null,
            'email' => $email ?: null,
        ];

        // A company with no contact person still needs a name on the invoice; a private customer
        // whose address carries only a full name still needs it split.
        if (!$attributes['firstname'] && !$attributes['lastname'] && $address?->fullName) {
            [$first, $last] = $this->splitName($address->fullName);
            $attributes['firstname'] = $first;
            $attributes['lastname'] = $last;
        }

        $customerId = $this->customerIdForOrder($order);

        if ($customerId !== null) {
            $attributes['customer_id'] = $customerId;
        }

        $vatNumber = Plugin::getInstance()->getVat()->vatNumberForOrder($order);

        if ($vatNumber !== null) {
            // Moneybird runs the VIES check itself and reports back on the contact, so the number
            // goes over exactly as the customer typed it minus the punctuation.
            $attributes['tax_number'] = $vatNumber;
        }

        if ($settings->documentType === $settings::DOCUMENT_SALES_INVOICE && $email !== '') {
            $attributes['send_invoices_to_email'] = $email;
        }

        return array_filter($attributes, static fn($value) => $value !== null && $value !== '');
    }

    /**
     * What goes in Moneybird's `customer_id`.
     */
    public function customerIdForOrder(Order $order): ?string
    {
        $source = Plugin::getInstance()->getSettings()->contactCustomerIdSource;

        $value = match ($source) {
            'userId' => $order->getCustomer()?->id ? 'craft-' . $order->getCustomer()->id : ($order->getEmail() ?: null),
            'email' => $order->getEmail() ?: null,
            'orderNumber' => $order->reference ?: $order->number,
            default => null,
        };

        if ($value === null || $value === '') {
            return null;
        }

        // Moneybird's customer_id is a short free-text field and it ends up on the invoice.
        return mb_substr((string)$value, 0, 128);
    }

    /**
     * The local mapping row for a Moneybird contact id, if Bird knows it.
     *
     * @return array<string, mixed>|null
     */
    public function getMappingByContactId(string $contactId): ?array
    {
        return (new Query())
            ->from([Table::CONTACTS])
            ->where(['moneybirdContactId' => $contactId])
            ->one() ?: null;
    }

    public function countMappings(): int
    {
        return (int)(new Query())->from([Table::CONTACTS])->count();
    }

    /**
     * Forget every local mapping. The contacts themselves stay in Moneybird — this only makes
     * Bird look them up again.
     */
    public function forgetAll(): int
    {
        return (int)Craft::$app->getDb()->createCommand()->delete(Table::CONTACTS)->execute();
    }

    // Private
    // =========================================================================

    /**
     * @return array<string, mixed>|null
     */
    private function findMapping(?string $customerId, Order $order): ?array
    {
        $query = (new Query())->from([Table::CONTACTS]);

        if ($customerId !== null) {
            $row = (clone $query)->where(['customerId' => $customerId])->one();

            if ($row) {
                return $row;
            }
        }

        $userId = $order->getCustomer()?->id;

        if ($userId) {
            $row = (clone $query)->where(['userId' => $userId])->one();

            if ($row) {
                return $row;
            }
        }

        $email = $order->getEmail();

        if ($email) {
            $row = (clone $query)->where(['email' => $email])->orderBy(['id' => SORT_DESC])->one();

            if ($row) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Ask Moneybird whether it already has this customer, so a shop that has been invoicing by
     * hand for years does not end up with two of everybody.
     *
     * @return array<string, mixed>|null
     * @throws ApiException
     */
    private function findRemote(?string $customerId, Order $order): ?array
    {
        $api = Plugin::getInstance()->getApi();

        if ($customerId !== null) {
            $contact = $api->findContactByCustomerId($customerId);

            if ($contact !== null && isset($contact['id'])) {
                return $contact;
            }
        }

        $email = $order->getEmail();

        if (!$email) {
            return null;
        }

        foreach ($api->searchContacts($email) as $candidate) {
            // The free-text search matches on more than the email, so the hit has to be confirmed
            // before an invoice is addressed to it.
            $candidateEmail = strtolower((string)($candidate['email'] ?? ''));
            $sendTo = strtolower((string)($candidate['send_invoices_to_email'] ?? ''));

            if ($candidateEmail === strtolower($email) || $sendTo === strtolower($email)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function saveMapping(string $contactId, ?string $customerId, Order $order, array $attributes, string $fingerprint): void
    {
        $now = Db::prepareDateForDb(new DateTime());

        $values = [
            'customerId' => $customerId,
            'userId' => $order->getCustomer()?->id,
            'email' => $order->getEmail() ?: null,
            'fingerprint' => $fingerprint,
            'countryCode' => $attributes['country'] ?? null,
            'vatNumber' => isset($attributes['tax_number']) ? Eu::normalizeVatNumber((string)$attributes['tax_number']) : null,
            'dateUpdated' => $now,
        ];

        $db = Craft::$app->getDb();
        $existing = $this->getMappingByContactId($contactId);

        if ($existing !== null) {
            $db->createCommand()->update(Table::CONTACTS, $values, ['id' => $existing['id']])->execute();

            return;
        }

        $db->createCommand()->insert(Table::CONTACTS, $values + [
            'moneybirdContactId' => $contactId,
            'dateCreated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    private function deleteMapping(string $contactId): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete(Table::CONTACTS, ['moneybirdContactId' => $contactId])
            ->execute();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function fingerprint(array $attributes): string
    {
        ksort($attributes);

        return md5(Json::encode($attributes));
    }

    private function addressForOrder(Order $order): ?Address
    {
        $prefersBilling = Plugin::getInstance()->getSettings()->contactAddressSource !== 'shipping';

        if ($prefersBilling) {
            return $order->getBillingAddress() ?? $order->getShippingAddress();
        }

        return $order->getShippingAddress() ?? $order->getBillingAddress();
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];

        if (count($parts) < 2) {
            return [null, $fullName ?: null];
        }

        $first = array_shift($parts);

        return [$first, implode(' ', $parts)];
    }
}
