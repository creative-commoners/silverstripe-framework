<?php

namespace SilverStripe\Control\SessionHandler;

use Psr\Log\LoggerInterface;
use SensitiveParameter;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\Connect\Query;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\Queries\SQLDelete;
use SilverStripe\ORM\Queries\SQLInsert;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\ORM\Queries\SQLUpdate;

/**
 * Session save handler that stores session data in the database.
 */
class DatabaseSessionHandler extends AbstractSessionHandler
{
    use Configurable;

    private static string $table_name = '_sessions';

    public function open(string $path, string $name): bool
    {
        // No action is required to open the session.
        return true;
    }

    public function close(): bool
    {
        // No action is required to close the session.
        return true;
    }

    /**
     * @inheritDoc
     * Clears the cache entry that represents this session ID.
     */
    public function destroy(#[SensitiveParameter] string $id): bool
    {
        if (!$this->isDatabaseReady()) {
            $this->logError('Could not remove session - database is not ready');
            return false;
        }

        SQLDelete::create(
            Convert::symbol2sql(static::config()->get('table_name')),
            [Convert::symbol2sql('ID') => $id]
        )->execute();
        return true;
    }

    /**
     * @inheritDoc
     * Clears all session cache which have a last modified datetime older than the session max lifetime.
     * Note that we use our own calculated session lifetime rather than the passed in lifetime which doesn't
     * take Silverstripe CMS configuration values into account.
     */
    public function gc(int $max_lifetime): int|false
    {
        if (!$this->isDatabaseReady()) {
            $this->logError('Could not perform session garbage collecttion - database is not ready');
            return false;
        }

        SQLDelete::create(
            Convert::symbol2sql(static::config()->get('table_name')),
            [Convert::symbol2sql('Expiry') . ' < ?' => DBDatetime::now()->getTimestamp()]
        )->execute();
        return DB::affected_rows();
    }

    /**
     * @inheritDoc
     * Returns data of a pre-existing session, or an empty string for a new session.
     */
    public function read(#[SensitiveParameter] string $id): string|false
    {
        if (!$this->isDatabaseReady()) {
            $this->logError('Could not read session - database is not ready');
            return false;
        }

        /** @var Query $rows */
        $rows = DB::withPrimary(fn() => $this->getSqlSelect($id)->execute());
        if ($rows->numRecords() === 0) {
            return '';
        }
        return $rows->record()['Data'];
    }

    /**
     * @inheritDoc
     * Writes session data to a cache entry.
     */
    public function write(#[SensitiveParameter] string $id, string $data): bool
    {
        if (!$this->isDatabaseReady()) {
            $this->logError('Could not write session - database is not ready');
            return false;
        }

        $table = Convert::symbol2sql(static::config()->get('table_name'));
        $where = [Convert::symbol2sql('ID') => $id];
        if ($this->sessionExists($id, true)) {
            $query = SQLUpdate::create($table, where: $where);
        } else {
            $query = SQLInsert::create($table, $where);
        }
        $query->addAssignments([
            Convert::symbol2sql('Data') => $data,
            Convert::symbol2sql('Expiry') => DBDatetime::now()->getTimestamp() + $this->getLifetime(),
        ]);
        $query->execute();
        return true;
    }

    /**
     * @inheritDoc
     * A session ID is valid if an entry for that session ID already exists and has not expired.
     */
    public function validateId(#[SensitiveParameter] string $id): bool
    {
        if (!$this->isDatabaseReady()) {
            $this->logError('Could not validate session ID - database is not ready');
            return false;
        }
        return $this->sessionExists($id);
    }

    /**
     * @inheritDoc
     * Called instead of write if session.lazy_write is enabled and no data has changed for this session.
     */
    public function updateTimestamp(#[SensitiveParameter] string $id, string $data): bool
    {
        // The logic for updating the timestamp ends up being effectively identical to just
        // writing the session - there's no optimisation to be made by using separate logic.
        return $this->write($id, $data);
    }

    /**
     * Add the database table. This is called by an extension when building the db.
     * Note that we don't just use a DataObject because:
     * 1. We don't want things like versioning, fluent, etc to ever be able to affect sessions
     * 2. We don't want developers to be affecting db operations via hooks (interact with sessions with the Session class)
     * 3. We don't want sessions to be used in any other ways that DataObjects are often
     * 4. We only want to build the table if this is the configured save handler
     */
    public function requireTable(): void
    {
        $fields = [
            // ID will automatically be the primary key because of its name
            'ID' => 'Varchar(64)',
            'Expiry' => 'Int',
            'Data' => 'Text',
        ];
        $indexes = [
            'Expiry' => [
                'type' => 'index',
                'columns' => ['Expiry'],
            ],
        ];
        DB::get_schema()->schemaUpdate(function () use ($fields, $indexes) {
            DB::require_table(
                static::config()->get('table_name'),
                $fields,
                $indexes,
                true,
                DataObject::config()->get('create_table_options')
            );
        });
    }

    /**
     * Get an SQLSelect for selecting the data for the given session ID.
     * If $allowExpired is false, expired sessions are explicitly excluded.
     */
    private function getSqlSelect(#[SensitiveParameter] string $id, bool $allowExpired = false): SQLSelect
    {
        $select = SQLSelect::create(
            Convert::symbol2sql('Data'),
            Convert::symbol2sql(static::config()->get('table_name')),
            [Convert::symbol2sql('ID') => $id],
        );
        if (!$allowExpired) {
            $select->addWhere([Convert::symbol2sql('Expiry') . ' >= ?' => DBDatetime::now()->getTimestamp()]);
        }
        return $select;
    }

    /**
     * Check if a session with this ID exists.
     * If $allowExpired is false, returns false for expired sessions.
     */
    private function sessionExists(#[SensitiveParameter] string $id, bool $allowExpired = false): bool
    {
        // Note this is the same logic used in DataQuery::exists()
        $row = DB::withPrimary(function () use ($id, $allowExpired) {
            $select = $this->getSqlSelect($id, $allowExpired);
            $subQuerySql = $select->sql($params);
            $selectExists = SQLSelect::create('1')->addWhere(['EXISTS (' . $subQuerySql . ')' => $params])->execute();
            return $selectExists->record();
        });
        if ($row) {
            $result = reset($row);
        } else {
            $result = false;
        }
        return $result === true || $result === 1 || $result === '1';
    }

    private function isDatabaseReady(): bool
    {
        if (!DB::is_active()) {
            return false;
        }
        return DB::get_schema()->hasTable(static::config()->get('table_name'));
    }

    private function logError(string $message): void
    {
        Injector::inst()->get(LoggerInterface::class)->error($message);
    }
}
