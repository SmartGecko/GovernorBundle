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

namespace Governor\Bundle\GovernorBundle\DependencyInjection;

use Governor\Framework\Annotations\CommandHandler;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Governor\Framework\Common\Annotation\MethodMessageHandlerInspector;
use Governor\Framework\CommandHandling\Handlers\AnnotatedAggregateCommandHandler;

class GovernorFrameworkExtension extends Extension
{

    public function load(array $configs, ContainerBuilder $container)
    {
        $config = $this->processConfiguration(new Configuration, $configs);

        $container->setAlias(
            'governor.lock_manager',
            new Alias(
                sprintf(
                    'governor.lock_manager.%s',
                    $config['lock_manager']
                )
            )
        );

        $container->setAlias(
            'governor.command_target_resolver',
            new Alias(
                sprintf(
                    'governor.command_target_resolver.%s',
                    $config['command_target_resolver']
                )
            )
        );

        $container->setAlias(
            'governor.order_resolver',
            new Alias(
                sprintf(
                    'governor.order_resolver.%s',
                    $config['order_resolver']
                )
            )
        );

        $container->setAlias(
            'governor.serializer',
            new Alias(
                sprintf(
                    'governor.serializer.%s',
                    $config['serializer']
                )
            )
        );

        $loader = new XmlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../Resources/config')
        );
        $loader->load('services.xml');

        // configure clusters
        $this->loadClusters($config, $container);
        // configure cluster selector
        $this->loadClusterSelector($config, $container);
        //configure AMQP terminals
        $this->loadAMQPTerminals($config, $container);
        // configure command buses
        $this->loadCommandBuses($config, $container);
        // configure event buses
        $this->loadEventBuses($config, $container);
        // configure command gateways
        $this->loadCommandGateways($config, $container);
        // configure repositories
        $this->loadRepositories($config, $container);
        //configure aggregate command handlers
        $this->loadAggregateCommandHandlers($config, $container);
        // configure event store
        $this->loadEventStore($config, $container);
        // configure saga repository
        $this->loadSagaRepository($config, $container);
        // configure saga manager
        $this->loadSagaManager($config, $container);
    }

    /**
     * @param array $config
     * @param ContainerBuilder $container
     */
    private function loadAMQPTerminals($config, ContainerBuilder $container)
    {
        foreach ($config['amqp_terminals'] as $name => $terminal) {
            $connectionDefinition = new Definition(
                $container->getParameter('governor.amqp_terminal.connection.class'),
                array(
                    $terminal['connection']['host'],
                    $terminal['connection']['port'],
                    $terminal['connection']['user'],
                    $terminal['connection']['password'],
                    $terminal['connection']['vhost']
                )
            );

            $container->setDefinition(
                sprintf(
                    "governor.amqp_terminal.connection.%s",
                    $name
                ),
                $connectionDefinition
            );

            $definition = new Definition($container->getParameter('governor.event_bus_terminal.amqp.class'));
            $definition->addArgument(new Reference('governor.serializer'));
            $definition->addMethodCall(
                'setConnection',
                array(
                    new Reference(
                        sprintf(
                            "governor.amqp_terminal.connection.%s",
                            $name
                        )
                    )
                )
            );

            $definition->addMethodCall(
                'setLogger',
                array(new Reference('logger'))
            );

            if (isset($terminal['routing_key_resolver'])) {
                $definition->addMethodCall(
                    'setRoutingKeyResolver',
                    array(new Reference('routing_key_resolver'))
                );
            }

            $container->setDefinition(
                sprintf("governor.amqp_terminal.%s", $name),
                $definition
            );
        }
    }

    /**
     * @param array $config
     * @param ContainerBuilder $container
     */
    private function loadCommandBuses($config, ContainerBuilder $container)
    {
        foreach ($config['command_buses'] as $name => $bus) {
            $handlerInterceptors = array();
            $dispatchInterceptors = array();

            $definition = new Definition($bus['class']);
            $definition->addMethodCall(
                'setLogger',
                array(new Reference('logger'))
            );

            foreach ($bus['handler_interceptors'] as $interceptor) {
                $handlerInterceptors[] = new Reference($interceptor);
            }

            foreach ($bus['dispatch_interceptors'] as $interceptor) {
                $dispatchInterceptors[] = new Reference($interceptor);
            }

            $definition->addMethodCall(
                'setHandlerInterceptors',
                array($handlerInterceptors)
            );

            $definition->addMethodCall(
                'setDispatchInterceptors',
                array($dispatchInterceptors)
            );

            $container->setDefinition(
                sprintf("governor.command_bus.%s", $name),
                $definition
            );
        }

        if (!$container->hasDefinition('governor.command_bus.default')) {
            throw new \RuntimeException(
                "Missing default command bus configuration, a command bus with the name \"default\" has to be configured."
            );
        }
    }

    /**
     * @param array $config
     * @param ContainerBuilder $container
     */
    private function loadClusterSelector($config, ContainerBuilder $container)
    {
        $definition = new Definition($config['cluster_selector']['class']);
        $definition->addArgument(
            new Reference(
                sprintf(
                    'governor.cluster.%s',
                    $config['cluster_selector']['cluster']
                )
            )
        );

        $container->setDefinition("governor.cluster_selector", $definition);
    }

    /**
     * @param array $config
     * @param ContainerBuilder $container
     */
    private function loadClusters($config, ContainerBuilder $container)
    {
        foreach ($config['clusters'] as $name => $cluster) {
            $definition = new Definition($cluster['class']);
            $definition->addArgument($name);
            $definition->addArgument(new Reference($cluster['order_resolver']));

            $definition->addMethodCall(
                'setLogger',
                array(new Reference('logger'))
            );

            $container->setDefinition(
                sprintf("governor.cluster.%s", $name),
                $definition
            );
        }
    }

    /**
     * @param array $config
     * @param ContainerBuilder $container
     */
    private function loadEventBuses($config, ContainerBuilder $container)
    {
        foreach ($config['event_buses'] as $name => $bus) {
            $definition = new Definition($bus['class']);
            $definition->addMethodCall(
                'setLogger',
                array(new Reference('logger'))
            );

            if (isset($bus['terminal'])) {
                $definition->addArgument(new Reference('governor.cluster_selector'));
                $definition->addArgument(new Reference($bus['terminal']));
            }

            $container->setDefinition(
                sprintf("governor.event_bus.%s", $name),
                $definition
            );
        }

        if (!$container->hasDefinition('governor.event_bus.default')) {
            throw new \RuntimeException(
                "Missing default event bus configuration, an event bus with the name \"default\" has to be configured."
            );
        }
    }

    /**
     * @param array $config
     * @param ContainerBuilder $container
     */
    private function loadCommandGateways($config, ContainerBuilder $container)
    {
        foreach ($config['command_gateways'] as $name => $gateway) {
            $definition = new Definition($gateway['class']);
            $definition->addArgument(
                new Reference(
                    sprintf(
                        "governor.command_bus.%s",
                        $gateway['command_bus']
                    )
                )
            );

            $container->setDefinition(
                sprintf(
                    "governor.command_gateway.%s",
                    $name
                ),
                $definition
            );
        }
    }

    /**
     * @param array $config
     * @param ContainerBuilder $container
     */
    private function loadSagaRepository($config, ContainerBuilder $container)
    {
        if (!isset($config['saga_repository'])) {
            return;
        }

        $definition = new Definition(
            $container->getParameter(
                sprintf(
                    "governor.saga_repository.%s.class",
                    $config['saga_repository']['type']
                )
            )
        );

        $serviceId = sprintf(
            "governor.saga_repository.%s",
            $config['saga_repository']['type']
        );

        switch ($config['saga_repository']['type']) {
            case 'orm':
                $definition->addArgument(
                    new Reference(
                        sprintf(
                            'doctrine.orm.%s',
                            $config['saga_repository']['parameters']['entity_manager']
                        )
                    )
                );
                $definition->addArgument(new Reference('governor.resource_injector'));
                $definition->addArgument(new Reference('governor.serializer'));
                break;
        }

        $definition->addMethodCall('setLogger', array(new Reference('logger')));

        $container->setDefinition($serviceId, $definition);
        $container->setAlias('governor.saga_repository', $serviceId);
    }

    /**
     * @param array $config
     * @param ContainerBuilder $container
     */
    private function loadSagaManager($config, ContainerBuilder $container)
    {
        if (!isset($config['saga_manager'])) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($config['saga_manager']['saga_locations']);
        $classes = array();

        // !!! TODO this is temporary and very poor
        foreach ($finder as $file) {
            if (preg_match("/^.*\/src\/(.*)\.php$/", $file, $matches)) {
                $classes[] = str_replace('/', '\\', $matches[1]);
            }
        }

        $container->setParameter('governor.sagas', $classes);

        $busDefinition = $container->findDefinition(
            sprintf(
                "governor.event_bus.%s",
                $config['saga_manager']['event_bus']
            )
        );
        $busDefinition->addMethodCall(
            'subscribe',
            array(new Reference('governor.saga_manager'))
        );

        $definition = new Definition($container->getParameter('governor.saga_manager.annotation.class'));
        $definition->addArgument(new Reference('governor.saga_repository'));
        $definition->addArgument(new Reference('governor.saga_factory'));
        $definition->addArgument($container->getParameter('governor.sagas'));
        $definition->addMethodCall('setLogger', array(new Reference('logger')));

        $container->setDefinition('governor.saga_manager', $definition);
    }

    /**
     * @param array $config
     * @param ContainerBuilder $container
     */
    private function loadEventStore($config, ContainerBuilder $container)
    {
        if (!array_key_exists('event_store', $config)) {
            return;
        }

        $definition = new Definition(
            $container->getParameter(
                sprintf(
                    "governor.event_store.%s.class",
                    $config['event_store']['type']
                )
            )
        );
        $serviceId = sprintf(
            'governor.event_store.%s',
            $config['event_store']['type']
        );

        switch ($config['event_store']['type']) {
            case 'filesystem':
                break;
            case 'orm':
                $definition->addArgument(
                    new Reference(
                        sprintf(
                            'doctrine.orm.%s',
                            $config['event_store']['parameters']['entity_manager']
                        )
                    )
                );
                $definition->addArgument(new Reference('governor.serializer'));

                if (isset($config['event_store']['parameters']['entry_store'])) {
                    $definition->addArgument(new Reference($config['event_store']['parameters']['entry_store']));
                }

                break;
            case 'odm':
                break;
        }

        $definition->addMethodCall('setLogger', array(new Reference('logger')));

        $container->setDefinition($serviceId, $definition);
        $container->setAlias('governor.event_store', $serviceId);
    }

    /**
     * @param array $config
     * @param ContainerBuilder $container
     */
    private function loadAggregateCommandHandlers(
        $config,
        ContainerBuilder $container
    ) {
        foreach ($config['aggregate_command_handlers'] as $name => $parameters) {
            $busDefinition = $container->findDefinition(
                sprintf(
                    "governor.command_bus.%s",
                    $parameters['command_bus']
                )
            );

            $inspector = new MethodMessageHandlerInspector(
                new \ReflectionClass($parameters['aggregate_root']),
                CommandHandler::class
            );

            foreach ($inspector->getHandlerDefinitions() as $handlerDefinition) {
                $handlerId = sprintf(
                    "governor.aggregate_command_handler.%s",
                    hash('crc32', openssl_random_pseudo_bytes(8))
                );

                $container->register($handlerId, AnnotatedAggregateCommandHandler::class)
                    ->addArgument($parameters['aggregate_root'])
                    ->addArgument($handlerDefinition->getMethod()->name)
                    ->addArgument(new Reference('governor.parameter_resolver_factory'))
                    ->addArgument(new Reference($parameters['repository']))
                    ->addArgument(new Reference('governor.command_target_resolver'))
                    ->setPublic(true)
                    ->setLazy(true);

                $busDefinition->addMethodCall(
                    'subscribe',
                    array($handlerDefinition->getPayloadType(), new Reference($handlerId))
                );
            }
        }
    }

    /**
     * @param array $config
     * @param ContainerBuilder $container
     */
    private function loadRepositories($config, ContainerBuilder $container)
    {
        foreach ($config['repositories'] as $name => $parameters) {
            $repository = new DefinitionDecorator(
                sprintf(
                    'governor.repository.%s',
                    $parameters['type']
                )
            );
            $repository->replaceArgument(0, $parameters['aggregate_root'])
                ->setPublic(true);
            $repository->replaceArgument(
                1,
                new Reference(
                    sprintf(
                        "governor.event_bus.%s",
                        $parameters['event_bus']
                    )
                )
            );

            if ($parameters['type'] === 'eventsourcing' &&
                isset($parameters['aggregate_factory'])
            ) {
                $repository->replaceArgument(
                    4,
                    new Reference($parameters['aggregate_factory'])
                );
            }

            $container->setDefinition(
                sprintf('%s.repository', $name),
                $repository
            );
        }
    }

}
