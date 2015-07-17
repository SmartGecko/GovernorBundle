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

namespace Governor\Bundle\GovernorBundle\CacheWarmer;

use Governor\Framework\CommandHandling\Distributed\RedisCommandBusConnector;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Governor\Framework\Common\Logging\NullLogger;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

class RedisConnectorCacheWarmer implements CacheWarmerInterface, LoggerAwareInterface
{
    /**
     * @var RedisCommandBusConnector
     */
    private $connector;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param RedisCommandBusConnector $connector
     */
    function __construct(RedisCommandBusConnector $connector)
    {
        $this->connector = $connector;
        $this->logger = new NullLogger();
    }

    /**
     * Mandatory warmer.
     *
     * @return bool
     */
    public function isOptional()
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function warmUp($cacheDir)
    {
        try {
            $this->connector->saveSubscriptions();
            $this->logger->info('Warmed cache for connector on node {node}',
                [
                    'node' => $this->connector->getNodeName(),
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to warm cache for connector on node {node}: {error}',
                [
                    'node' => $this->connector->getNodeName(),
                    'error' => $e->getMessage()
                ]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }


}