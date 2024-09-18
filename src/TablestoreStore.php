<?php

declare(strict_types=1);

namespace Dew\TablestoreDriver;

use Dew\Acs\Tablestore\Attribute;
use Dew\Acs\Tablestore\InstanceException;
use Dew\Acs\Tablestore\Messages\ComparatorType;
use Dew\Acs\Tablestore\Messages\Filter;
use Dew\Acs\Tablestore\Messages\FilterType;
use Dew\Acs\Tablestore\Messages\SingleColumnValueFilter;
use Dew\Acs\Tablestore\Plainbuf;
use Dew\Acs\Tablestore\PlainBufferWriter;
use Dew\Acs\Tablestore\PrimaryKey;
use Dew\Acs\Tablestore\TablestoreInstance;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\InteractsWithTime;
use InvalidArgumentException;
use RuntimeException;

final class TablestoreStore implements LockProvider, Store
{
    use InteractsWithTime;

    /**
     * The length of the prefix.
     */
    private int $prefixLength;

    /**
     * Create a Tablestore cache store.
     */
    public function __construct(
        protected TablestoreInstance $tablestore,
        protected string $table,
        protected string $keyAttribute = 'key',
        protected string $valueAttribute = 'value',
        protected string $expirationAttribute = 'expires_at',
        protected string $prefix = ''
    ) {
        $this->setPrefix($prefix);
    }

    /**
     * Retrieve an item from the cache by key.
     *
     * @param  string  $key
     * @return mixed
     */
    public function get($key)
    {
        $buffer = $this->tablestore->table($this->table)
            ->whereKey($this->keyAttribute, $this->prefix.$key)
            ->where($this->expirationAttribute, '>', Carbon::now()->getTimestamp())
            ->get()
            ->getRow();

        if ($buffer === '') {
            return;
        }

        $item = Plainbuf::decode($buffer);

        /** @var \Dew\Acs\Tablestore\Cells\HasValue[] */
        $values = $item[$this->valueAttribute] ?? [];

        return isset($values[0]) ? $this->unserialize($values[0]->value()) : null;
    }

    /**
     * Retrieve multiple items from the cache by key.
     *
     * Items not found in the cache will have a null value.
     *
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys)
    {
        if ($keys === []) {
            return [];
        }

        $response = $this->tablestore->batch(function ($query) use ($keys) {
            $query->table($this->table)->where(
                $this->expirationAttribute, '>', Carbon::now()->getTimestamp()
            );

            foreach ($keys as $key) {
                $query->table($this->table)
                    ->whereKey($this->keyAttribute, $this->prefix.$key)
                    ->get();
            }
        });

        $result = array_fill_keys($keys, null);

        /** @var \Dew\Acs\Tablestore\Messages\TableInBatchGetRowResponse[] */
        $tables = $response->getTables();

        /** @var \Dew\Acs\Tablestore\Messages\RowInBatchGetRowResponse[] */
        $rows = $tables[0]->getRows();

        foreach ($rows as $row) {
            $buffer = $row->getRow();

            if ($buffer === '') {
                continue;
            }

            $item = Plainbuf::decode($buffer);

            /** @var \Dew\Acs\Tablestore\Cells\StringPrimaryKey */
            $key = $item[$this->keyAttribute];

            /** @var \Dew\Acs\Tablestore\Cells\HasValue[] */
            $values = $item[$this->valueAttribute] ?? [];

            if (isset($values[0])) {
                $result[$this->pure($key->value())] = $this->unserialize(
                    $values[0]->value()
                );
            }
        }

        return $result;
    }

    /**
     * Store an item in the cache for a given number of seconds.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  int  $seconds
     * @return bool
     */
    public function put($key, $value, $seconds)
    {
        $this->tablestore->table($this->table)->insert([
            PrimaryKey::string($this->keyAttribute, $this->prefix.$key),
            Attribute::createFromValue($this->valueAttribute, $this->serialize($value)),
            Attribute::integer($this->expirationAttribute, $this->toTimestamp($seconds)),
        ]);

        return true;
    }

    /**
     * Store multiple items in the cache for a given number of seconds.
     *
     * @param  array<string, mixed>  $values
     * @param  int  $seconds
     * @return bool
     */
    public function putMany(array $values, $seconds)
    {
        if ($values === []) {
            return true;
        }

        $expiration = $this->toTimestamp($seconds);

        $this->tablestore->batch(function ($query) use ($values, $expiration) {
            foreach ($values as $key => $value) {
                $query->table($this->table)->insert([
                    PrimaryKey::string($this->keyAttribute, $this->prefix.$key),
                    Attribute::createFromValue($this->valueAttribute, $this->serialize($value)),
                    Attribute::integer($this->expirationAttribute, $expiration),
                ]);
            }
        });

        return true;
    }

    /**
     * Store an item in the cache if the key doesn't exist.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  int  $seconds
     * @return bool
     */
    public function add($key, $value, $seconds)
    {
        try {
            Attribute::integer($this->expirationAttribute, Carbon::now()->getTimestamp())
                ->toFormattedValue($now = new PlainBufferWriter);

            // Include only items that do not exist or that have expired
            // expression: expiration <= now
            $filter = new Filter;
            $filter->setType(FilterType::FT_SINGLE_COLUMN_VALUE);
            $filter->setFilter((new SingleColumnValueFilter)
                ->setColumnName($this->expirationAttribute)
                ->setComparator(ComparatorType::CT_LESS_EQUAL)
                ->setColumnValue($now->getBuffer())
                ->setFilterIfMissing(false) // allow missing
                ->setLatestVersionOnly(true)
                ->serializeToString());

            $this->tablestore->table($this->table)
                ->ignoreExistence()
                ->where($filter)
                ->insert([
                    PrimaryKey::string($this->keyAttribute, $this->prefix.$key),
                    Attribute::createFromValue($this->valueAttribute, $this->serialize($value)),
                    Attribute::integer($this->expirationAttribute, $this->toTimestamp($seconds)),
                ]);
        } catch (InstanceException $e) {
            if ($e->getError()?->getCode() === 'OTSConditionCheckFail') {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /**
     * Increment the value of an item in the cache.
     *
     * @param  string  $key
     * @param  int  $value
     * @return int|bool
     */
    public function increment($key, $value = 1)
    {
        try {
            $this->tablestore->table($this->table)
                ->whereKey($this->keyAttribute, $this->prefix.$key)
                ->expectExists()
                ->where($this->expirationAttribute, '>', Carbon::now()->getTimestamp())
                ->update([
                    Attribute::increment($this->valueAttribute, $value),
                ]);

            return true;
        } catch (InstanceException $e) {
            if ($e->getError()?->getCode() === 'OTSConditionCheckFail') {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Decrement the value of an item in the cache.
     *
     * @param  string  $key
     * @param  int  $value
     * @return int|bool
     */
    public function decrement($key, $value = 1)
    {
        try {
            $this->tablestore->table($this->table)
                ->whereKey($this->keyAttribute, $this->prefix.$key)
                ->expectExists()
                ->where($this->expirationAttribute, '>', Carbon::now()->getTimestamp())
                ->update([
                    Attribute::decrement($this->valueAttribute, $value),
                ]);

            return true;
        } catch (InstanceException $e) {
            if ($e->getError()?->getCode() === 'OTSConditionCheckFail') {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Store an item in the cache indefinitely.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return bool
     */
    public function forever($key, $value)
    {
        return $this->put($key, $value, Carbon::now()->addYears(5)->getTimestamp());
    }

    /**
     * Get a lock instance.
     *
     * @param  string  $name
     * @param  int  $seconds
     * @param  string|null  $owner
     * @return \Illuminate\Contracts\Cache\Lock
     */
    public function lock($name, $seconds = 0, $owner = null)
    {
        return new TablestoreLock($this, $this->prefix.$name, $seconds, $owner);
    }

    /**
     * Restore a lock instance using the owner identifier.
     *
     * @param  string  $name
     * @param  string  $owner
     * @return \Illuminate\Contracts\Cache\Lock
     */
    public function restoreLock($name, $owner)
    {
        return $this->lock($name, 0, $owner);
    }

    /**
     * Remove an item from the cache.
     *
     * @param  string  $key
     * @return bool
     */
    public function forget($key)
    {
        $this->tablestore->table($this->table)
            ->whereKey($this->keyAttribute, $this->prefix.$key)
            ->delete();

        return true;
    }

    /**
     * Remove all items from the cache.
     *
     * @return bool
     */
    public function flush()
    {
        throw new RuntimeException('Tablestore does not support flushing an entire table. Please create a new table.');
    }

    /**
     * Set the cache key prefix.
     */
    private function setPrefix(string $prefix): void
    {
        $this->prefix = $prefix === '' ? '' : $prefix.':';
        $this->prefixLength = strlen($this->prefix);
    }

    /**
     * Get the cache key prefix.
     *
     * @return string
     */
    public function getPrefix()
    {
        return $this->prefix;
    }

    /**
     * Generate a storable representation of a value.
     */
    private function serialize(mixed $value): int|float|bool|string
    {
        return match (gettype($value)) {
            'integer', 'double', 'boolean' => $value,
            default => serialize($value),
        };
    }

    /**
     * Create a PHP value from a stored representation.
     */
    private function unserialize(mixed $value): mixed
    {
        return match (gettype($value)) {
            'integer', 'double', 'boolean' => $value,
            'string' => unserialize($value),
            default => throw new InvalidArgumentException(sprintf(
                'Unexpected type [%s] occurred.', gettype($value)
            )),
        };
    }

    /**
     * Get the key without the prefix.
     */
    private function pure(string $key): string
    {
        return substr($key, $this->prefixLength);
    }

    /**
     * Get the UNIX timestamp for the given number of seconds.
     */
    private function toTimestamp(int $seconds): int
    {
        return $seconds > 0
            ? $this->availableAt($seconds)
            : Carbon::now()->getTimestamp();
    }

    /**
     * The underlying Tablestore client.
     */
    public function getClient(): TablestoreInstance
    {
        return $this->tablestore;
    }
}
