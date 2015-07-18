<?php

/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * The software is based on the Axon Framework project which is
 * licensed under the Apache 2.0 license. For more information on the Axon Framework
 * see <http://www.axonframework.org/>.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.governor-framework.org/>.
 */

namespace Governor\Bundle\GovernorBundle\Replay;

use Governor\Framework\EventHandling\Amqp\AmqpTerminal;
use Governor\Framework\EventHandling\Replay\DiscardingIncomingMessageHandler;
use Governor\Framework\EventHandling\InMemoryEventListenerRegistry;
use Governor\Framework\EventHandling\Replay\ReplayingEventBus;
use Governor\Framework\EventHandling\SimpleEventBus;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerAwareInterface;
use PhpAmqpLib\Connection\AMQPConnection;
use Governor\Framework\EventStore\Management\EventStoreManagementInterface;
use Governor\Framework\UnitOfWork\DefaultUnitOfWork;
use Governor\Framework\Serializer\SerializerInterface;
use Governor\Framework\EventHandling\Amqp\RoutingKeyResolverInterface;
use Governor\Framework\EventHandling\Amqp\DefaultAmqpMessageConverter;

/**
 * Description of PosInitialSynchronizationService
 *
 * @author david
 */
class ReplayService implements LoggerAwareInterface
{

    /**
     * @var EventStoreManagementInterface
     */
    private $eventStore;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var AMQPConnection
     */
    private $connection;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var RoutingKeyResolverInterface
     */
    private $routingKeyResolver;

    /**
     * @param AMQPConnection $connection
     * @param EventStoreManagementInterface $eventStore
     * @param SerializerInterface $serializer
     * @param RoutingKeyResolverInterface $routingKeyResolver
     */
    function __construct(
        AMQPConnection $connection,
        EventStoreManagementInterface $eventStore,
        SerializerInterface $serializer,
        RoutingKeyResolverInterface $routingKeyResolver
    ) {
        $this->eventStore = $eventStore;
        $this->connection = $connection;
        $this->serializer = $serializer;
        $this->routingKeyResolver = $routingKeyResolver;
    }

    public function replay($exchange)
    {
        $channel = $this->connection->channel();
        $channel->tx_select();

        $uow = DefaultUnitOfWork::startAndGet();

        $eventBus = new SimpleEventBus(new InMemoryEventListenerRegistry());
        $terminal = new AmqpTerminal(
            $this->serializer,
            new DefaultAmqpMessageConverter($this->serializer, $this->routingKeyResolver)
        );
        $terminal->setExchangeName($exchange);
        $eventBus->setTerminals([$terminal]);

        $replayingEventBus = new ReplayingEventBus(
            $eventBus, $this->eventStore,
            new DiscardingIncomingMessageHandler()
        );

        $replayingEventBus->setLogger($this->logger);

        try {
            $replayingEventBus->startReplay();
            $uow->commit();
            $channel->tx_commit();
        } catch (\Exception $ex) {
            $channel->tx_rollback();
            $uow->rollback($ex);
        }
    }

    /**
     * @param LoggerInterface $logger
     * @return null
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

}
