<?php

declare(strict_types=1);

namespace Zhineng\Tablestore;

use Dew\Tablestore\Attribute;
use Dew\Tablestore\Exceptions\TablestoreException;
use Dew\Tablestore\PlainbufferWriter;
use Dew\Tablestore\PrimaryKey;
use Dew\Tablestore\Responses\RowDecodableResponse;
use Dew\Tablestore\Tablestore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\InteractsWithTime;
use InvalidArgumentException;
use Protos\ComparatorType;
use Protos\Filter;
use Protos\FilterType;
use Protos\SingleColumnValueFilter;
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
        protected Tablestore $tablestore,
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
        $item = $this->tablestore->table($this->table)
            ->whereKey($this->keyAttribute, $this->prefix.$key)
            ->where($this->expirationAttribute, '>', Carbon::now()->getTimestampMs())
            ->get()->getDecodedRow();

        if ($item === null) {
            return;
        }

        /** @var \Dew\Tablestore\Contracts\HasValue[] */
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
        $now = Carbon::now()->getTimestampMs();

        $response = $this->tablestore->batch(function ($query) use ($keys, $now) {
            foreach ($keys as $key) {
                $query->table($this->table)
                    ->whereKey($this->keyAttribute, $this->prefix.$key)
                    ->where($this->expirationAttribute, '>', $now)
                    ->get();
            }
        });

        $result = array_fill_keys($keys, null);

        /** @var \Protos\TableInBatchGetRowResponse[] */
        $tables = $response->getTables();

        /** @var \Protos\RowInBatchGetRowResponse[] */
        $rows = $tables[0]->getRows();

        foreach ($rows as $row) {
            $decoded = (new RowDecodableResponse($row))->getDecodedRow();

            if ($decoded === null) {
                continue;
            }

            /** @var \Dew\Tablestore\Cells\StringPrimaryKey */
            $key = $decoded[$this->keyAttribute];

            /** @var \Dew\Tablestore\Contracts\HasValue[] */
            $values = $decoded[$this->valueAttribute] ?? [];

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
            Attribute::integer($this->expirationAttribute, Carbon::now()->getTimestampMs())
                ->toFormattedValue($now = new PlainbufferWriter);

            // Include only items that do not exist or that have expired
            // expression: expiration <= now
            $filter = new Filter;
            $filter->setType(FilterType::FT_SINGLE_COLUMN_VALUE);
            $filter->setFilter((new SingleColumnValueFilter)
                ->setColumnName($this->expirationAttribute)
                ->setComparator(ComparatorType::CT_LESS_THAN)
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
        } catch (TablestoreException $e) {
            if ($e->getError()->getCode() === 'OTSConditionCheckFail') {
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
                ->update([
                    Attribute::increment($this->valueAttribute, $value),
                ]);

            return true;
        } catch (TablestoreException $e) {
            if ($e->getError()->getCode() === 'OTSConditionCheckFail') {
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
                ->update([
                    Attribute::decrement($this->valueAttribute, $value),
                ]);

            return true;
        } catch (TablestoreException $e) {
            if ($e->getError()->getCode() === 'ConditionalCheckFailed') {
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
        return $this->put($key, $value, Carbon::now()->addYears(5)->getTimestampMs());
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
     * Get the UNIX timestamp in milliseconds for the given number of seconds.
     */
    private function toTimestamp(int $seconds): int
    {
        $timestamp = $seconds > 0
            ? $this->availableAt($seconds)
            : Carbon::now()->getTimestamp();

        return $timestamp * 1000;
    }

    /**
     * The underlying Tablestore client.
     */
    public function getClient(): Tablestore
    {
        return $this->tablestore;
    }
}
