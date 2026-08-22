<?php

namespace justinholtweb\bird\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\bird\db\Table;
use justinholtweb\bird\models\Document;

/**
 * The documents Bird has booked, as rows.
 *
 * Nothing here talks to Moneybird. It exists so that exactly one component owns the table whose
 * unique index — `(orderId, kind, sourceKey)` — is the plugin's idempotency guarantee.
 */
class Documents extends Component
{
    private const COLUMNS = [
        'id', 'orderId', 'kind', 'sourceKey', 'documentType', 'moneybirdId', 'invoiceNumber',
        'reference', 'state', 'currency', 'total', 'totalPaid', 'administrationId', 'taxTreatment',
        'publicUrl', 'dateSent', 'datePaid', 'dateSynced', 'attempts', 'lastError', 'dateCreated',
        'dateUpdated', 'uid',
    ];

    public function getDocumentForOrder(int $orderId, string $kind = Document::KIND_INVOICE, string $sourceKey = ''): ?Document
    {
        $row = $this->baseQuery()
            ->where(['orderId' => $orderId, 'kind' => $kind, 'sourceKey' => $sourceKey])
            ->one();

        return $row ? new Document($row) : null;
    }

    /**
     * @return Document[]
     */
    public function getDocumentsForOrder(int $orderId): array
    {
        $rows = $this->baseQuery()
            ->where(['orderId' => $orderId])
            ->orderBy(['kind' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return array_map(static fn(array $row) => new Document($row), $rows);
    }

    public function getDocumentById(int $id): ?Document
    {
        $row = $this->baseQuery()->where(['id' => $id])->one();

        return $row ? new Document($row) : null;
    }

    public function getDocumentByMoneybirdId(string $moneybirdId): ?Document
    {
        $row = $this->baseQuery()->where(['moneybirdId' => $moneybirdId])->one();

        return $row ? new Document($row) : null;
    }

    /**
     * @param array{state?: string, kind?: string, search?: string} $criteria
     * @return Document[]
     */
    public function find(array $criteria = [], int $limit = 200): array
    {
        $query = $this->baseQuery()
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit);

        if (!empty($criteria['state'])) {
            $query->andWhere(['state' => $criteria['state']]);
        }

        if (!empty($criteria['kind'])) {
            $query->andWhere(['kind' => $criteria['kind']]);
        }

        if (!empty($criteria['search'])) {
            $term = '%' . str_replace(['%', '_'], ['\%', '\_'], (string)$criteria['search']) . '%';
            $query->andWhere(['or',
                ['like', 'reference', $term, false],
                ['like', 'invoiceNumber', $term, false],
                ['like', 'moneybirdId', $term, false],
            ]);
        }

        return array_map(static fn(array $row) => new Document($row), $query->all());
    }

    /**
     * @return array<string, int>
     */
    public function countsByState(): array
    {
        $rows = (new Query())
            ->select(['state', 'total' => 'COUNT(*)'])
            ->from([Table::DOCUMENTS])
            ->groupBy(['state'])
            ->all();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string)$row['state']] = (int)$row['total'];
        }

        return $counts;
    }

    public function count(): int
    {
        return (int)(new Query())->from([Table::DOCUMENTS])->count();
    }

    /**
     * Insert or update by the unique triple.
     *
     * The upsert is the point: two workers racing on the same order both land here, and the
     * loser updates the row the winner inserted rather than creating a second document.
     */
    public function save(Document $document): Document
    {
        $now = Db::prepareDateForDb(new DateTime());

        $values = [
            'documentType' => $document->documentType,
            'moneybirdId' => $document->moneybirdId,
            'invoiceNumber' => $document->invoiceNumber,
            'reference' => $document->reference,
            'state' => $document->state,
            'currency' => $document->currency,
            'total' => $document->total,
            'totalPaid' => $document->totalPaid,
            'administrationId' => $document->administrationId,
            'taxTreatment' => $document->taxTreatment,
            'publicUrl' => $document->publicUrl,
            'dateSent' => $document->dateSent ? Db::prepareDateForDb($document->dateSent) : null,
            'datePaid' => $document->datePaid ? Db::prepareDateForDb($document->datePaid) : null,
            'dateSynced' => $document->dateSynced ? Db::prepareDateForDb($document->dateSynced) : null,
            'attempts' => $document->attempts,
            'lastError' => $document->lastError,
            'dateUpdated' => $now,
        ];

        $db = Craft::$app->getDb();

        $existingId = (new Query())
            ->select(['id'])
            ->from([Table::DOCUMENTS])
            ->where([
                'orderId' => $document->orderId,
                'kind' => $document->kind,
                'sourceKey' => $document->sourceKey,
            ])
            ->scalar();

        if ($existingId) {
            $db->createCommand()->update(Table::DOCUMENTS, $values, ['id' => $existingId])->execute();
            $document->id = (int)$existingId;

            return $document;
        }

        $db->createCommand()->insert(Table::DOCUMENTS, $values + [
            'orderId' => $document->orderId,
            'kind' => $document->kind,
            'sourceKey' => $document->sourceKey,
            'dateCreated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        $document->id = (int)$db->getLastInsertID(Craft::$app->getDb()->getSchema()->getRawTableName(Table::DOCUMENTS));

        return $document;
    }

    /**
     * Forget a document locally. What is in Moneybird stays in Moneybird — deleting a booked
     * invoice from a shop's accounts is not something a CP button should be able to do.
     */
    public function deleteById(int $id): bool
    {
        return (bool)Craft::$app->getDb()->createCommand()
            ->delete(Table::DOCUMENTS, ['id' => $id])
            ->execute();
    }

    /**
     * Orders that failed and are still worth another attempt.
     *
     * @return Document[]
     */
    public function getRetryable(int $maxAttempts, int $limit = 50): array
    {
        $rows = $this->baseQuery()
            ->where(['state' => [Document::STATE_FAILED, Document::STATE_PENDING]])
            ->andWhere(['<', 'attempts', $maxAttempts])
            ->orderBy(['dateUpdated' => SORT_ASC])
            ->limit($limit)
            ->all();

        return array_map(static fn(array $row) => new Document($row), $rows);
    }

    private function baseQuery(): Query
    {
        return (new Query())->select(self::COLUMNS)->from([Table::DOCUMENTS]);
    }
}
