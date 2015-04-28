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

namespace Governor\Bundle\GovernorBundle\UnitOfWork;

use Governor\Framework\Domain\AggregateRootInterface;
use Governor\Framework\EventHandling\EventBusInterface;
use Governor\Framework\UnitOfWork\SaveAggregateCallbackInterface;
use Governor\Framework\UnitOfWork\TransactionManagerInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Governor\Framework\UnitOfWork\DefaultUnitOfWork;

class DebugUnitOfWork extends DefaultUnitOfWork
{
    /**
     * @var int
     */
    private static $level = 0;

    /**
     * @var Stopwatch
     */
    private $stopwatch;

    /**
     * @param Stopwatch $stopwatch
     * @param TransactionManagerInterface $transactionManager
     */
    public function __construct(Stopwatch $stopwatch, TransactionManagerInterface $transactionManager = null)
    {
        parent::__construct($transactionManager);
        $this->stopwatch = $stopwatch;
    }

    /**
     * {@inheritdoc}
     */
    public function start()
    {
        $this->stopwatch->openSection(sprintf('governor_uow_%s', self::$level));
        self::$level++;
        parent::start();
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        parent::commit();
        $this->stopwatch->stopSection(sprintf('governor_uow_%s', self::$level));
        self::$level--;
    }

    /**
     * {@inheritdoc}
     */
    public function rollback(\Exception $ex = null)
    {
        parent::rollback($ex);
        $this->stopwatch->stopSection(sprintf('governor_uow_%s', self::$level));
        self::$level--;
    }

    /**
     * {@inheritdoc}
     */
    public function registerAggregate(
        AggregateRootInterface $aggregateRoot,
        EventBusInterface $eventBus,
        SaveAggregateCallbackInterface $saveAggregateCallback
    ) {
        $this->stopwatch->start('register_aggregates');

        $result = parent::registerAggregate(
            $aggregateRoot,
            $eventBus,
            $saveAggregateCallback
        );

        $this->stopwatch->stop('register_aggregates');

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    protected function publishEvents()
    {
        $this->stopwatch->start('publish_events');

        $result = parent::publishEvents();

        $this->stopwatch->stop('publish_events');

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    protected function saveAggregates()
    {
        $this->stopwatch->start('save_aggregates');

        parent::saveAggregates();

        $this->stopwatch->stop('save_aggregates');
    }


}