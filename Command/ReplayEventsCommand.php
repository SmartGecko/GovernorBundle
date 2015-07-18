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

namespace Governor\Bundle\GovernorBundle\Command;

use Governor\Bundle\GovernorBundle\Replay\ReplayService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Governor\Framework\EventHandling\Amqp\NamespaceRoutingKeyResolver;

/**
 * Replays events from the event store to the specified AMQP endpoint.
 *
 * @author    "David Kalosi" <david.kalosi@smartgecko.eu>
 * @copyright 2014 "SmartGecko s.r.o."
 * @license   <a href="http://www.opensource.org/licenses/mit-license.php">MIT License</a>
 */
class ReplayEventsCommand extends ContainerAwareCommand
{

    protected function configure()
    {
        $this->setName('governor:replay-events')
            ->addArgument('exchange', InputArgument::REQUIRED,
                'Destination exchange')
            ->addArgument('connection', InputArgument::OPTIONAL,
                'Name of the AMQP connection to use.', 'default')
            ->setDescription('Replays the events from the event store to the given AMQP exchange.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $exchange = $input->getArgument('exchange');
        $connectionName = $input->getArgument('connection');
        $connection = $this->getContainer()->get(sprintf('governor.amqp.connection.%s',
            $connectionName));

        $eventStore = $this->getContainer()->get('governor.event_store');
        $serializer = $this->getContainer()->get('governor.serializer');
        $routingKeyResolver = new NamespaceRoutingKeyResolver();

        $replay = new ReplayService($connection, $eventStore, $serializer,
            $routingKeyResolver);

        $replay->setLogger($this->getContainer()->get('logger'));
        $replay->replay($exchange);
    }

}
