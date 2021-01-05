<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace LockmeDep\Symfony\Component\Lock\Store;

use MongoDB\BSON\UTCDateTime;
use LockmeDep\MongoDB\Client;
use LockmeDep\MongoDB\Collection;
use MongoDB\Driver\Exception\WriteException;
use MongoDB\Driver\ReadPreference;
use LockmeDep\MongoDB\Exception\DriverRuntimeException;
use LockmeDep\MongoDB\Exception\InvalidArgumentException as MongoInvalidArgumentException;
use LockmeDep\MongoDB\Exception\UnsupportedException;
use LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException;
use LockmeDep\Symfony\Component\Lock\Exception\InvalidTtlException;
use LockmeDep\Symfony\Component\Lock\Exception\LockAcquiringException;
use LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException;
use LockmeDep\Symfony\Component\Lock\Exception\LockExpiredException;
use LockmeDep\Symfony\Component\Lock\Exception\LockStorageException;
use LockmeDep\Symfony\Component\Lock\Key;
use LockmeDep\Symfony\Component\Lock\PersistingStoreInterface;
/**
 * MongoDbStore is a StoreInterface implementation using MongoDB as a storage
 * engine. Support for MongoDB server >=2.2 due to use of TTL indexes.
 *
 * CAUTION: TTL Indexes are used so this store relies on all client and server
 * nodes to have synchronized clocks for lock expiry to occur at the correct
 * time. To ensure locks don't expire prematurely; the TTLs should be set with
 * enough extra time to account for any clock drift between nodes.
 *
 * CAUTION: The locked resource name is indexed in the _id field of the lock
 * collection. An indexed field's value in MongoDB can be a maximum of 1024
 * bytes in length inclusive of structural overhead.
 *
 * @see https://docs.mongodb.com/manual/reference/limits/#Index-Key-Limit
 *
 * @author Joe Bennett <joe@assimtech.com>
 */
class MongoDbStore implements \LockmeDep\Symfony\Component\Lock\PersistingStoreInterface
{
    private $collection;
    private $client;
    private $uri;
    private $options;
    private $initialTtl;
    use ExpiringStoreTrait;
    /**
     * @param Collection|Client|string $mongo      An instance of a Collection or Client or URI @see https://docs.mongodb.com/manual/reference/connection-string/
     * @param array                    $options    See below
     * @param float                    $initialTtl The expiration delay of locks in seconds
     *
     * @throws InvalidArgumentException If required options are not provided
     * @throws InvalidTtlException      When the initial ttl is not valid
     *
     * Options:
     *      gcProbablity:  Should a TTL Index be created expressed as a probability from 0.0 to 1.0 [default: 0.001]
     *      database:      The name of the database [required when $mongo is a Client]
     *      collection:    The name of the collection [required when $mongo is a Client]
     *      uriOptions:    Array of uri options. [used when $mongo is a URI]
     *      driverOptions: Array of driver options. [used when $mongo is a URI]
     *
     * When using a URI string:
     *      The database is determined from the uri's path, otherwise the "database" option is used. To specify an alternate authentication database; "authSource" uriOption or querystring parameter must be used.
     *      The collection is determined from the uri's "collection" querystring parameter, otherwise the "collection" option is used.
     *
     * For example: mongodb://myuser:mypass@myhost/mydatabase?collection=mycollection
     *
     * @see https://docs.mongodb.com/php-library/current/reference/method/MongoDBClient__construct/
     *
     * If gcProbablity is set to a value greater than 0.0 there is a chance
     * this store will attempt to create a TTL index on self::save().
     * If you prefer to create your TTL Index manually you can set gcProbablity
     * to 0.0 and optionally leverage
     * self::createTtlIndex(int $expireAfterSeconds = 0).
     *
     * writeConcern and readConcern are not specified by MongoDbStore meaning the connection's settings will take effect.
     * readPreference is primary for all queries.
     * @see https://docs.mongodb.com/manual/applications/replication/
     */
    public function __construct($mongo, array $options = [], float $initialTtl = 300.0)
    {
        $this->options = \array_merge(['gcProbablity' => 0.001, 'database' => null, 'collection' => null, 'uriOptions' => [], 'driverOptions' => []], $options);
        $this->initialTtl = $initialTtl;
        if ($mongo instanceof \LockmeDep\MongoDB\Collection) {
            $this->collection = $mongo;
        } elseif ($mongo instanceof \LockmeDep\MongoDB\Client) {
            $this->client = $mongo;
        } elseif (\is_string($mongo)) {
            $this->uri = $this->skimUri($mongo);
        } else {
            throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException(\sprintf('"%s()" requires "%s" or "%s" or URI as first argument, "%s" given.', __METHOD__, \LockmeDep\MongoDB\Collection::class, \LockmeDep\MongoDB\Client::class, \get_debug_type($mongo)));
        }
        if (!$mongo instanceof \LockmeDep\MongoDB\Collection) {
            if (null === $this->options['database']) {
                throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException(\sprintf('"%s()" requires the "database" in the URI path or option.', __METHOD__));
            }
            if (null === $this->options['collection']) {
                throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException(\sprintf('"%s()" requires the "collection" in the URI querystring or option.', __METHOD__));
            }
        }
        if ($this->options['gcProbablity'] < 0.0 || $this->options['gcProbablity'] > 1.0) {
            throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException(\sprintf('"%s()" gcProbablity must be a float from 0.0 to 1.0, "%f" given.', __METHOD__, $this->options['gcProbablity']));
        }
        if ($this->initialTtl <= 0) {
            throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidTtlException(\sprintf('"%s()" expects a strictly positive TTL, got "%d".', __METHOD__, $this->initialTtl));
        }
    }
    /**
     * Extract default database and collection from given connection URI and remove collection querystring.
     *
     * Non-standard parameters are removed from the URI to improve libmongoc's re-use of connections.
     *
     * @see https://www.php.net/manual/en/mongodb.connection-handling.php
     */
    private function skimUri(string $uri) : string
    {
        if (\false === ($parsedUrl = \parse_url($uri))) {
            throw new \LockmeDep\Symfony\Component\Lock\Exception\InvalidArgumentException(\sprintf('The given MongoDB Connection URI "%s" is invalid.', $uri));
        }
        $pathDb = \ltrim($parsedUrl['path'] ?? '', '/') ?: null;
        if (null !== $pathDb) {
            $this->options['database'] = $pathDb;
        }
        $matches = [];
        if (\preg_match('/^(.*[\\?&])collection=([^&#]*)&?(([^#]*).*)$/', $uri, $matches)) {
            $prefix = $matches[1];
            $this->options['collection'] = $matches[2];
            if (empty($matches[4])) {
                $prefix = \substr($prefix, 0, -1);
            }
            $uri = $prefix . $matches[3];
        }
        return $uri;
    }
    /**
     * Creates a TTL index to automatically remove expired locks.
     *
     * If the gcProbablity option is set higher than 0.0 (defaults to 0.001);
     * there is a chance this will be called on self::save().
     *
     * Otherwise; this should be called once manually during database setup.
     *
     * Alternatively the TTL index can be created manually on the database:
     *
     *  db.lock.ensureIndex(
     *      { "expires_at": 1 },
     *      { "expireAfterSeconds": 0 }
     *  )
     *
     * Please note, expires_at is based on the application server. If the
     * database time differs; a lock could be cleaned up before it has expired.
     * To ensure locks don't expire prematurely; the lock TTL should be set
     * with enough extra time to account for any clock drift between nodes.
     *
     * A TTL index MUST BE used to automatically clean up expired locks.
     *
     * @see http://docs.mongodb.org/manual/tutorial/expire-data/
     *
     * @throws UnsupportedException          if options are not supported by the selected server
     * @throws MongoInvalidArgumentException for parameter/option parsing errors
     * @throws DriverRuntimeException        for other driver errors (e.g. connection errors)
     */
    public function createTtlIndex(int $expireAfterSeconds = 0)
    {
        $this->getCollection()->createIndex([
            // key
            'expires_at' => 1,
        ], [
            // options
            'expireAfterSeconds' => $expireAfterSeconds,
        ]);
    }
    /**
     * {@inheritdoc}
     *
     * @throws LockExpiredException when save is called on an expired lock
     */
    public function save(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        $key->reduceLifetime($this->initialTtl);
        try {
            $this->upsert($key, $this->initialTtl);
        } catch (\MongoDB\Driver\Exception\WriteException $e) {
            if ($this->isDuplicateKeyException($e)) {
                throw new \LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException('Lock was acquired by someone else.', 0, $e);
            }
            throw new \LockmeDep\Symfony\Component\Lock\Exception\LockAcquiringException('Failed to acquire lock.', 0, $e);
        }
        if ($this->options['gcProbablity'] > 0.0 && (1.0 === $this->options['gcProbablity'] || \random_int(0, \PHP_INT_MAX) / \PHP_INT_MAX <= $this->options['gcProbablity'])) {
            $this->createTtlIndex();
        }
        $this->checkNotExpired($key);
    }
    /**
     * {@inheritdoc}
     *
     * @throws LockStorageException
     * @throws LockExpiredException
     */
    public function putOffExpiration(\LockmeDep\Symfony\Component\Lock\Key $key, $ttl)
    {
        $key->reduceLifetime($ttl);
        try {
            $this->upsert($key, $ttl);
        } catch (\MongoDB\Driver\Exception\WriteException $e) {
            if ($this->isDuplicateKeyException($e)) {
                throw new \LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException('Failed to put off the expiration of the lock.', 0, $e);
            }
            throw new \LockmeDep\Symfony\Component\Lock\Exception\LockStorageException($e->getMessage(), 0, $e);
        }
        $this->checkNotExpired($key);
    }
    /**
     * {@inheritdoc}
     */
    public function delete(\LockmeDep\Symfony\Component\Lock\Key $key)
    {
        $this->getCollection()->deleteOne([
            // filter
            '_id' => (string) $key,
            'token' => $this->getUniqueToken($key),
        ]);
    }
    /**
     * {@inheritdoc}
     */
    public function exists(\LockmeDep\Symfony\Component\Lock\Key $key) : bool
    {
        return null !== $this->getCollection()->findOne([
            // filter
            '_id' => (string) $key,
            'token' => $this->getUniqueToken($key),
            'expires_at' => ['$gt' => $this->createMongoDateTime(\microtime(\true))],
        ], ['readPreference' => new \MongoDB\Driver\ReadPreference(\defined(\MongoDB\Driver\ReadPreference::PRIMARY) ? \MongoDB\Driver\ReadPreference::PRIMARY : \MongoDB\Driver\ReadPreference::RP_PRIMARY)]);
    }
    /**
     * Update or Insert a Key.
     *
     * @param float $ttl Expiry in seconds from now
     */
    private function upsert(\LockmeDep\Symfony\Component\Lock\Key $key, float $ttl)
    {
        $now = \microtime(\true);
        $token = $this->getUniqueToken($key);
        $this->getCollection()->updateOne([
            // filter
            '_id' => (string) $key,
            '$or' => [['token' => $token], ['expires_at' => ['$lte' => $this->createMongoDateTime($now)]]],
        ], [
            // update
            '$set' => ['_id' => (string) $key, 'token' => $token, 'expires_at' => $this->createMongoDateTime($now + $ttl)],
        ], [
            // options
            'upsert' => \true,
        ]);
    }
    private function isDuplicateKeyException(\MongoDB\Driver\Exception\WriteException $e) : bool
    {
        $code = $e->getCode();
        $writeErrors = $e->getWriteResult()->getWriteErrors();
        if (1 === \count($writeErrors)) {
            $code = $writeErrors[0]->getCode();
        }
        // Mongo error E11000 - DuplicateKey
        return 11000 === $code;
    }
    private function getCollection() : \LockmeDep\MongoDB\Collection
    {
        if (null !== $this->collection) {
            return $this->collection;
        }
        if (null === $this->client) {
            $this->client = new \LockmeDep\MongoDB\Client($this->uri, $this->options['uriOptions'], $this->options['driverOptions']);
        }
        $this->collection = $this->client->selectCollection($this->options['database'], $this->options['collection']);
        return $this->collection;
    }
    /**
     * @param float $seconds Seconds since 1970-01-01T00:00:00.000Z supporting millisecond precision. Defaults to now.
     */
    private function createMongoDateTime(float $seconds) : \MongoDB\BSON\UTCDateTime
    {
        return new \MongoDB\BSON\UTCDateTime($seconds * 1000);
    }
    /**
     * Retrieves an unique token for the given key namespaced to this store.
     *
     * @param Key lock state container
     *
     * @return string token
     */
    private function getUniqueToken(\LockmeDep\Symfony\Component\Lock\Key $key) : string
    {
        if (!$key->hasState(__CLASS__)) {
            $token = \base64_encode(\random_bytes(32));
            $key->setState(__CLASS__, $token);
        }
        return $key->getState(__CLASS__);
    }
}
