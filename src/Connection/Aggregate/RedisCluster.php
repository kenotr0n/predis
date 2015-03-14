<?php

/*
 * This file is part of the Predis package.
 *
 * (c) Daniele Alessandri <suppakilla@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Predis\Connection\Aggregate;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use OutOfBoundsException;
use Predis\NotSupportedException;
use Predis\Cluster\StrategyInterface;
use Predis\Cluster\RedisStrategy as RedisClusterStrategy;
use Predis\Replication\ReplicationStrategy as ReadOnlyStrategy;
use Predis\Command\CommandInterface;
use Predis\Command\RawCommand;
use Predis\Connection\NodeConnectionInterface;
use Predis\Connection\FactoryInterface;
use Predis\Response\ErrorInterface as ErrorResponseInterface;

/**
 * Abstraction for a Redis-backed cluster of nodes (Redis >= 3.0.0).
 *
 * This connection backend offers smart support for redis-cluster by handling
 * automatic slots map (re)generation upon -MOVED or -ASK responses returned by
 * Redis when redirecting a client to a different node.
 *
 * The cluster can be pre-initialized using only a subset of the actual nodes in
 * the cluster, Predis will do the rest by adjusting the slots map and creating
 * the missing underlying connection instances on the fly.
 *
 * It is possible to pre-associate connections to a slots range with the "slots"
 * parameter in the form "$first-$last". This can greatly reduce runtime node
 * guessing and redirections.
 *
 * It is also possible to ask for the full and updated slots map directly to one
 * of the nodes and optionally enable such a behaviour upon -MOVED redirections.
 * Asking for the cluster configuration to Redis is actually done by issuing a
 * CLUSTER SLOTS command to a random node in the pool.
 *
 * @author Daniele Alessandri <suppakilla@gmail.com>
 */
class RedisCluster implements ClusterInterface, IteratorAggregate, Countable
{
    private $useClusterSlots = true;
    private $defaultParameters = array();
    private $pool = array();
    private $slots = array();
    private $connSlaves = array();
    private $readOnlyStrategy;
    private $slotsMap;
    private $strategy;
    private $connections;

    /**
     * @param FactoryInterface  $connections Optional connection factory.
     * @param StrategyInterface $strategy    Optional cluster strategy.
     */
    public function __construct(
        FactoryInterface $connections,
        StrategyInterface $strategy = null
    ) {
        $this->connections = $connections;
        $this->strategy = $strategy ?: new RedisClusterStrategy();
        $this->readOnlyStrategy = new ReadOnlyStrategy();
        $this->buildSlavesMap();
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected()
    {
        foreach ($this->pool as $connection) {
            if ($connection->isConnected()) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function connect()
    {
        if ($connection = $this->getRandomConnection()) {
            $connection->connect();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect()
    {
        foreach ($this->pool as $connection) {
            $connection->disconnect();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function add(NodeConnectionInterface $connection)
    {
        $this->pool[(string) $connection] = $connection;
        unset($this->slotsMap);
    }

    /**
     * {@inheritdoc}
     */
    public function remove(NodeConnectionInterface $connection)
    {
        if (false !== $id = array_search($connection, $this->pool, true)) {
            unset(
                $this->pool[$id],
                $this->slotsMap
            );

            return true;
        }

        return false;
    }

    /**
     * Removes a connection instance by using its identifier.
     *
     * @param string $connectionID Connection identifier.
     *
     * @return bool True if the connection was in the pool.
     */
    public function removeById($connectionID)
    {
        if (isset($this->pool[$connectionID])) {
            unset(
                $this->pool[$connectionID],
                $this->slotsMap
            );

            return true;
        }

        return false;
    }

    /**
     * Generates the current slots map by guessing the cluster configuration out
     * of the connection parameters of the connections in the pool.
     *
     * Generation is based on the same algorithm used by Redis to generate the
     * cluster, so it is most effective when all of the connections supplied on
     * initialization have the "slots" parameter properly set accordingly to the
     * current cluster configuration.
     */
    public function buildSlotsMap()
    {
        $this->slotsMap = array();

        foreach ($this->pool as $connectionID => $connection) {
            $parameters = $connection->getParameters();

            if (!isset($parameters->slots)) {
                continue;
            }

            foreach (explode(',', $parameters->slots) as $slotRange) {
                list($first,$last) = explode('-', $slotRange, 2);

                $this->setSlots($first, $last, $connectionID);
            }
        }
    }

    /**
     * Sets pre-configured slave mappings for each connection in the pool
     * from the "slaves" connection parameter.
     *
     */
    public function buildSlavesMap()
    {
        $this->connSlaves = array();

        foreach ($this->pool as $connectionID => $connection) {
            $parameters = $connection->getParameters();

            if (!isset($parameters->slaves)) {
                continue;
            }

            $connStr = (string)$connection;

            // 'slaves' parameter is a comma-separated list of host:port slave ids:
            foreach (explode(',', $parameters->slaves) as $slaveID) {
                $this->connSlaves[$connStr][] = $slaveID;
            }
        }
    }

    /**
     * Generates an updated slots map fetching the cluster configuration using
     * the CLUSTER SLOTS command against the specified node or a random one from
     * the pool.
     *
     * @param NodeConnectionInterface $connection Optional connection instance.
     *
     * @return array
     */
    public function askSlotsMap(NodeConnectionInterface $connection = null)
    {
        if (!$connection && !$connection = $this->getRandomConnection()) {
            return array();
        }

        $command = RawCommand::create('CLUSTER', 'SLOTS');
        $response = $connection->executeCommand($command);

        foreach ($response as $clusterSlotsOutput) {
            // first two elements are slot range beginning and ending, next is master host+port:
            $start = array_shift($clusterSlotsOutput);
            $end = array_shift($clusterSlotsOutput);
            list($masterHost, $masterPort) = array_shift($clusterSlotsOutput);

            // remainder of output are slaves, so store them for read-only commands:
            $masterStr = ($masterHost === '') ? (string) $connection : "$masterHost:$masterPort";
            $this->setSlots($start, $end, $masterStr);

            // add any configured slaves for this master:
            foreach ($slots as $slaveID) {
                list($host,$port) = $slaveID;
                $this->connSlaves[$masterStr][] = "$host:$port";
            }
        }

        return $this->slotsMap;
    }

    /**
     * Returns the current slots map for the cluster.
     *
     * @return array
     */
    public function getSlotsMap()
    {
        if (!isset($this->slotsMap)) {
            $this->slotsMap = array();
        }

        return $this->slotsMap;
    }

    /**
     * Pre-associates a connection to a slots range to avoid runtime guessing.
     *
     * @param int                            $first      Initial slot of the range.
     * @param int                            $last       Last slot of the range.
     * @param NodeConnectionInterface|string $connection ID or connection instance.
     *
     * @throws \OutOfBoundsException
     */
    public function setSlots($first, $last, $connection)
    {
        if ($first < 0x0000 || $first > 0x3FFF ||
            $last < 0x0000 || $last > 0x3FFF ||
            $last < $first
        ) {
            throw new OutOfBoundsException(
                "Invalid slot range for $connection: [$first-$last]."
            );
        }

        $slots = array_fill($first, $last - $first + 1, (string) $connection);
        $this->slotsMap = $this->getSlotsMap() + $slots;
    }

    /**
     * Guesses the correct node associated to a given slot using a precalculated
     * slots map, falling back to the same logic used by Redis to initialize a
     * cluster (best-effort).
     *
     * @param int $slot Slot index.
     *
     * @return string Connection ID.
     */
    protected function guessNode($slot)
    {
        if (!isset($this->slotsMap)) {
            $this->buildSlotsMap();
        }

        if (isset($this->slotsMap[$slot])) {
            return $this->slotsMap[$slot];
        }

        $count = count($this->pool);
        $index = min((int) ($slot / (int) (16384 / $count)), $count - 1);
        $nodes = array_keys($this->pool);

        return $nodes[$index];
    }

    /**
     * Creates a new connection instance from the given connection ID.
     *
     * @param string $connectionID Identifier for the connection.
     *
     * @return NodeConnectionInterface
     */
    protected function createConnection($connectionID)
    {
        $host = explode(':', $connectionID, 2);

        $parameters = array_merge($this->defaultParameters, array(
            'host' => $host[0],
            'port' => $host[1],
        ));

        $connection = $this->connections->create($parameters);

        return $connection;
    }

    /**
     * {@inheritdoc}
     */
    public function getConnection(CommandInterface $command)
    {
        $slot = $this->strategy->getSlot($command);
        if (!isset($slot)) {
            throw new NotSupportedException(
                "Cannot use '{$command->getId()}' with redis-cluster."
            );
        }

        if (isset($this->readOnlyStrategy) && $this->readOnlyStrategy->isReadOperation($command)) {
            return $this->randomSlaveForSlot($slot);
        }

        if (isset($this->slots[$slot])) {
            return $this->slots[$slot];
        } else {
            return $this->getConnectionBySlot($slot);
        }
    }

    /** Returns a connection to a slave for the given slot.
     *
     * If no slave is known for that slot, returns the master connection instead.
     *
     * @param int $slot Slot index.
     *
     * @return NodeConnectionInterface
     *
     * @throws \OutOfBoundsException
     */
    public function randomSlaveForSlot($slot)
    {
        $connection = isset($this->slots[$slot]) ? $this->slots[$slot] : $this->getConnectionBySlot($slot);

        $masterStr = (string)$connection;
        if (!isset($this->connSlaves[$masterStr])) {
            return  $connection;
        }

        $slaves = $this->connSlaves[$masterStr];
        $slaveID= $slaves[array_rand($slaves)];

        if (!$connection = $this->getConnectionById($slaveID)) {
            $connection = $this->createConnection($slaveID);
        }

        // must prepare the connection for read-only operations to slaves:
        $connection->executeCommand(RawCommand::create('READONLY'));
        return $connection;
    }

    /**
     * Returns the connection currently associated to a given slot.
     *
     * @param int $slot Slot index.
     *
     * @return NodeConnectionInterface
     *
     * @throws \OutOfBoundsException
     */
    public function getConnectionBySlot($slot)
    {
        if ($slot < 0x0000 || $slot > 0x3FFF) {
            throw new OutOfBoundsException("Invalid slot [$slot].");
        }

        if (isset($this->slots[$slot])) {
            return $this->slots[$slot];
        }

        $connectionID = $this->guessNode($slot);

        if (!$connection = $this->getConnectionById($connectionID)) {
            $connection = $this->createConnection($connectionID);
            $this->pool[$connectionID] = $connection;
        }

        return $this->slots[$slot] = $connection;
    }

    /**
     * {@inheritdoc}
     */
    public function getConnectionById($connectionID)
    {
        if (isset($this->pool[$connectionID])) {
            return $this->pool[$connectionID];
        }
    }

    /**
     * Returns a random connection from the pool.
     *
     * @return NodeConnectionInterface|null
     */
    protected function getRandomConnection()
    {
        if ($this->pool) {
            return $this->pool[array_rand($this->pool)];
        }
    }

    /**
     * Permanently associates the connection instance to a new slot.
     * The connection is added to the connections pool if not yet included.
     *
     * @param NodeConnectionInterface $connection Connection instance.
     * @param int                     $slot       Target slot index.
     */
    protected function move(NodeConnectionInterface $connection, $slot)
    {
        $this->pool[(string) $connection] = $connection;
        $this->slots[(int) $slot] = $connection;
    }

    /**
     * Handles -ERR responses returned by Redis.
     *
     * @param CommandInterface       $command Command that generated the -ERR response.
     * @param ErrorResponseInterface $error   Redis error response object.
     *
     * @return mixed
     */
    protected function onErrorResponse(CommandInterface $command, ErrorResponseInterface $error)
    {
        $details = explode(' ', $error->getMessage(), 2);

        switch ($details[0]) {
            case 'MOVED':
                return $this->onMovedResponse($command, $details[1]);

            case 'ASK':
                return $this->onAskResponse($command, $details[1]);

            default:
                return $error;
        }
    }

    /**
     * Handles -MOVED responses by executing again the command against the node
     * indicated by the Redis response.
     *
     * @param CommandInterface $command Command that generated the -MOVED response.
     * @param string           $details Parameters of the -MOVED response.
     *
     * @return mixed
     */
    protected function onMovedResponse(CommandInterface $command, $details)
    {
        list($slot, $connectionID) = explode(' ', $details, 2);

        if (!$connection = $this->getConnectionById($connectionID)) {
            $connection = $this->createConnection($connectionID);
        }

        if ($this->useClusterSlots) {
            $this->askSlotsMap($connection);
        }

        $this->move($connection, $slot);
        $response = $this->executeCommand($command);

        return $response;
    }

    /**
     * Handles -ASK responses by executing again the command against the node
     * indicated by the Redis response.
     *
     * @param  CommandInterface $command Command that generated the -ASK response.
     * @param  string           $details Parameters of the -ASK response.
     * @return mixed
     */
    protected function onAskResponse(CommandInterface $command, $details)
    {
        list($slot, $connectionID) = explode(' ', $details, 2);

        if (!$connection = $this->getConnectionById($connectionID)) {
            $connection = $this->createConnection($connectionID);
        }

        $connection->executeCommand(RawCommand::create('ASKING'));
        $response = $connection->executeCommand($command);

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function writeRequest(CommandInterface $command)
    {
        $this->getConnection($command)->writeRequest($command);
    }

    /**
     * {@inheritdoc}
     */
    public function readResponse(CommandInterface $command)
    {
        return $this->getConnection($command)->readResponse($command);
    }

    /**
     * {@inheritdoc}
     */
    public function executeCommand(CommandInterface $command)
    {
        $connection = $this->getConnection($command);
        $response = $connection->executeCommand($command);

        if ($response instanceof ErrorResponseInterface) {
            return $this->onErrorResponse($command, $response);
        }

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function count()
    {
        return count($this->pool);
    }

    /**
     * {@inheritdoc}
     */
    public function getIterator()
    {
        return new ArrayIterator(array_values($this->pool));
    }

    /**
     * Returns the underlying command hash strategy used to hash commands by
     * using keys found in their arguments.
     *
     * @return StrategyInterface
     */
    public function getClusterStrategy()
    {
        return $this->strategy;
    }

    /**
     * Returns the underlying connection factory used to create new connection
     * instances to Redis nodes indicated by redis-cluster.
     *
     * @return FactoryInterface
     */
    public function getConnectionFactory()
    {
        return $this->connections;
    }

    /**
     * Enables automatic fetching of the current slots map from one of the nodes
     * using the CLUSTER SLOTS command. This option is disabled by default but
     * asking the current slots map to Redis upon -MOVED responses may reduce
     * overhead by eliminating the trial-and-error nature of the node guessing
     * procedure, mostly when targeting many keys that would end up in a lot of
     * redirections.
     *
     * The slots map can still be manually fetched using the askSlotsMap()
     * method whether or not this option is enabled.
     *
     * @param bool $value Enable or disable the use of CLUSTER SLOTS.
     */
    public function useClusterSlots($value)
    {
        $this->useClusterSlots = (bool) $value;
    }

    /**
     * Sets a default array of connection parameters to be applied when creating
     * new connection instances on the fly when they are not part of the initial
     * pool supplied upon cluster initialization.
     *
     * These parameters are not applied to connections added to the pool using
     * the add() method.
     *
     * @param array $parameters Array of connection parameters.
     */
    public function setDefaultParameters(array $parameters)
    {
        $this->defaultParameters = array_merge(
            $this->defaultParameters,
            $parameters ?: array()
        );
    }
}
