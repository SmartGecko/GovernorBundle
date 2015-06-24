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

use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

/**
 * Class GovernorFrameworkExtension.
 *
 * @author    "David Kalosi" <david.kalosi@gmail.com>
 * @license   <a href="http://www.opensource.org/licenses/mit-license.php">MIT License</a>
 */
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

        $container->setAlias('governor.uow_factory', new Alias($config['uow_factory']));

        $container->setParameter('governor.aggregates', $config['aggregates']);

        $loader = new XmlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../Resources/config')
        );
        $loader->load('services.xml');

        // configure annotation reader
        $this->loadAnnotationReader($config, $container);
        // configure mongo templates
        $this->loadMongoTemplates($config, $container);
        //configure terminals
        $this->loadTerminals($config, $container);
        // configure command buses
        $this->loadCommandBuses($config, $container);
        // configure event buses
        $this->loadEventBuses($config, $container);
        // configure command gateways
        $this->loadCommandGateways($config, $container);
        // configure repositories
        $this->loadRepositories($config, $container);
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
    private function loadMongoTemplates($config, ContainerBuilder $container)
    {
        if (!isset($config['mongo_templates'])) {
            return;
        }

        foreach ($config['mongo_templates'] as $name => $params) {
            $definition = new Definition(
                $container->getParameter('governor.mongo_template.default.class')
            );

            $definition->addArgument($params['server']);
            $definition->addArgument($params['database']);

            if (isset($params['auth_database'])) {
                $definition->addArgument($params['auth_database']);
            }

            if (isset($params['event_collection'])) {
                $definition->addArgument($params['event_collection']);
            }

            if (isset($params['snapshot_collection'])) {
                $definition->addArgument($params['snapshot_collection']);
            }

            $container->setDefinition(sprintf('governor.mongo_template.%s', $name), $definition);
        }

    }
    /**
     * @param array $config
     * @param ContainerBuilder $container
     */
    private function loadAnnotationReader($config, ContainerBuilder $container)
    {
        $definition = new Definition(
            $container->getParameter(
                sprintf('governor.annotation_reader_factory.%s.class', $config['annotation_reader']['type'])
            )
        );

        switch ($config['annotation_reader']['type']) {
            case 'simple':
                break;
            case 'file_cache':
                $definition->addArgument($config['annotation_reader']['parameters']['path']);
                $definition->addArgument($config['annotation_reader']['parameters']['debug']);
                break;
        }

        $container->setDefinition('governor.annotation_reader_factory', $definition);
    }

    /**
     * @param $config
     * @param ContainerBuilder $container
     */
    private function loadAmqpTerminals($config, ContainerBuilder $container)
    {
        foreach ($config as $name => $terminal) {
            $connectionDefinition = new Definition(
                $container->getParameter('governor.amqp.connection.class'),
                [
                    $terminal['host'],
                    $terminal['port'],
                    $terminal['user'],
                    $terminal['password'],
                    $terminal['vhost']
                ]
            );

            $connectionDefinition->setLazy(true);

            $container->setDefinition(
                sprintf(
                    "governor.amqp.connection.%s",
                    $name
                ),
                $connectionDefinition
            );

            $definition = new Definition($container->getParameter('governor.terminal.amqp.class'));
            $definition->addArgument(new Reference('governor.serializer'));
            $definition->addMethodCall(
                'setConnection',
                [
                    new Reference(
                        sprintf(
                            "governor.amqp.connection.%s",
                            $name
                        )
                    )
                ]
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
                sprintf("governor.terminal.amqp.%s", $name),
                $definition
            );
        }
    }

    /**
     * @param array $config
     * @param ContainerBuilder $container
     */
    private function loadTerminals($config, ContainerBuilder $container)
    {
        if (empty($config['terminals'])) {
            return;
        }

        foreach ($config['terminals'] as $type => $data) {
            switch ($type) {
                case 'amqp':
                    $this->loadAmqpTerminals($data, $container);
                    break;
            }
        }
    }

    /**
     * @param array $config
     * @param ContainerBuilder $container
     */
    private function loadCommandBuses($config, ContainerBuilder $container)
    {
        foreach ($config['command_buses'] as $name => $bus) {
            $handlerInterceptors = [];
            $dispatchInterceptors = [];

            $template = $container->findDefinition($bus['registry']);
            $registryDefinition = new Definition($template->getClass());
            $registryDefinition->setArguments($template->getArguments());

            $container->setDefinition(sprintf('governor.command_bus.registry.%s', $name), $registryDefinition);

            $definition = new Definition($bus['class']);
            $definition->addArgument(new Reference(sprintf('governor.command_bus.registry.%s', $name)));
            $definition->addArgument(new Reference('governor.uow_factory'));
            $definition->addMethodCall('setLogger', [new Reference('logger')]);

            foreach ($bus['handler_interceptors'] as $interceptor) {
                $handlerInterceptors[] = new Reference($interceptor);
            }

            foreach ($bus['dispatch_interceptors'] as $interceptor) {
                $dispatchInterceptors[] = new Reference($interceptor);
            }

            $definition->addMethodCall('setHandlerInterceptors', [$handlerInterceptors]);
            $definition->addMethodCall('setDispatchInterceptors', [$dispatchInterceptors]);

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
    private function loadEventBuses($config, ContainerBuilder $container)
    {
        foreach ($config['event_buses'] as $name => $bus) {
            $terminals = [];

            $template = $container->findDefinition($bus['registry']);
            $registryDefinition = new Definition($template->getClass());
            $registryDefinition->setArguments($template->getArguments());

            $container->setDefinition(sprintf('governor.event_bus.registry.%s', $name), $registryDefinition);

            $definition = new Definition($bus['class']);
            $definition->addArgument(new Reference(sprintf('governor.event_bus.registry.%s', $name)));
            $definition->addMethodCall(
                'setLogger',
                [new Reference('logger')]
            );

            foreach ($bus['terminals'] as $terminal) {
                $terminals[] = new Reference($terminal);
            }

            $definition->addMethodCall('setTerminals', [$terminals]);

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

        $registry = $container->findDefinition(
            sprintf(
                "governor.event_bus.registry.%s",
                $config['saga_manager']['event_bus']
            )
        );
        $registry->addMethodCall(
            'subscribe',
            [new Reference('governor.saga_manager')]
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
            case 'mongo':
                $definition->addArgument(new Reference($config['event_store']['parameters']['mongo_template']));
                $definition->addArgument(new Reference('governor.serializer'));
                $definition->addArgument(new Reference($config['event_store']['parameters']['storage_strategy']));

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
    private function loadRepositories($config, ContainerBuilder $container)
    {
        foreach ($config['aggregates'] as $name => $parameters) {
            $repository = new DefinitionDecorator(
                sprintf(
                    'governor.repository.%s',
                    $parameters['repository']
                )
            );

            $repository->replaceArgument(0, $parameters['class'])
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

            if ($parameters['repository'] === 'event_sourcing' &&
                isset($parameters['factory'])
            ) {
                $repository->replaceArgument(
                    4,
                    new Reference($parameters['factory'])
                );
            }

            $container->setDefinition(
                sprintf('%s.repository', $name),
                $repository
            );
        }
    }

}
