<?php

namespace Governor\Tests\Plugin\SymfonyBundle;

use Governor\Bundle\GovernorBundle\DependencyInjection\Compiler\AggregateCommandHandlerPass;
use Psr\Log\LoggerInterface;
use Governor\Framework\CommandHandling\CommandBusInterface;
use Governor\Framework\CommandHandling\CommandHandlerInterface;
use Governor\Framework\CommandHandling\GenericCommandMessage;
use Governor\Framework\CommandHandling\NoHandlerForCommandException;
use Governor\Framework\Domain\AbstractAggregateRoot;
use Governor\Framework\Domain\IdentifierFactory;
use Governor\Framework\EventSourcing\Annotation\AbstractAnnotatedAggregateRoot;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Governor\Bundle\GovernorBundle\DependencyInjection\GovernorFrameworkExtension;
use Governor\Bundle\GovernorBundle\DependencyInjection\Compiler\CommandHandlerPass;
use Governor\Bundle\GovernorBundle\DependencyInjection\Compiler\EventHandlerPass;
use Governor\Framework\Repository\RepositoryInterface;
use Governor\Framework\Annotations\EventHandler;
use Governor\Framework\Annotations\CommandHandler;
use Symfony\Bridge\ProxyManager\LazyProxy\Instantiator\RuntimeInstantiator;
use Governor\Framework\EventHandling\EventBusInterface;
use Governor\Framework\EventHandling\EventListenerInterface;
use Governor\Framework\CommandHandling\SimpleCommandBus;
use Governor\Framework\EventHandling\SimpleEventBus;
use Governor\Framework\CommandHandling\InMemoryCommandHandlerRegistry;
use Governor\Framework\EventHandling\InMemoryEventListenerRegistry;
use Symfony\Component\Stopwatch\Stopwatch;
use Governor\Framework\EventHandling\Amqp\AmqpTerminal;

$loader = require __DIR__."/../../vendor/autoload.php";
$loader->add('Governor', __DIR__);

\Doctrine\Common\Annotations\AnnotationRegistry::registerLoader(array($loader, 'loadClass'));

class GovernorFrameworkExtensionTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var ContainerInterface
     */
    private $testSubject;

    public function setUp()
    {
        $this->testSubject = $this->createTestContainer();
    }

    public function testRepositories()
    {
        $repo1 = $this->testSubject->get('dummy1.repository');
        $repo2 = $this->testSubject->get('dummy2.repository');

        $this->assertInstanceOf(RepositoryInterface::class, $repo1);
        $this->assertInstanceOf(RepositoryInterface::class, $repo2);
        $this->assertNotSame($repo1, $repo2);
        $this->assertEquals(
            Dummy1Aggregate::class,
            $repo1->getClass()
        );
        $this->assertEquals(
            Dummy2Aggregate::class,
            $repo2->getClass()
        );
    }

    public function testCommandHandlers()
    {
        /** @var CommandBusInterface $commandBus */
        $commandBus = $this->testSubject->get('governor.command_bus.default');
        $this->assertInstanceOf(CommandBusInterface::class, $commandBus);

        $registry = $commandBus->getCommandHandlerRegistry();
        $this->assertInstanceOf(InMemoryCommandHandlerRegistry::class, $registry);

        $handler = $registry->findCommandHandlerFor(GenericCommandMessage::asCommandMessage(new ContainerCommand1()));
        $this->assertInstanceOf(CommandHandlerInterface::class, $handler);

        try {
            $registry->findCommandHandlerFor(GenericCommandMessage::asCommandMessage(new \stdClass()));
            $this->fail('NoHandlerForCommandException expected');
        } catch (NoHandlerForCommandException $ex) {

        }
    }

    public function testEventHandlers()
    {
        /** @var EventBusInterface $eventBus */
        $eventBus = $this->testSubject->get('governor.event_bus.default');

        $this->assertInstanceOf(EventBusInterface::class, $eventBus);

        $registry = $eventBus->getEventListenerRegistry();
        $this->assertInstanceOf(InMemoryEventListenerRegistry::class, $registry);

        $listeners = $registry->getListeners();

        $this->assertCount(2, $listeners);
        $this->assertContainsOnlyInstancesOf(EventListenerInterface::class, $listeners);
    }

    public function testEventHandlerLazyLoading()
    {
        foreach ($this->testSubject->getServiceIds() as $id) {
            if (preg_match('/^governor.event_handler.*/', $id)) {
                $def = $this->testSubject->getDefinition($id);
                $this->assertTrue($def->isLazy());
            }
        }
    }

    public function testCommandHandlerLazyLoading()
    {
        foreach ($this->testSubject->getServiceIds() as $id) {
            if (preg_match('/^governor.command_handler.*/', $id) || preg_match(
                    '/^governor.aggregate_command_handler.*/',
                    $id
                )
            ) {
                $def = $this->testSubject->getDefinition($id);
                $this->assertTrue($def->isLazy());
            }
        }
    }

    public function testIdentifierFactory()
    {
        $factory = $this->testSubject->get('governor.identifier_factory');

        $this->assertInstanceOf(IdentifierFactory::class, $factory);
    }

    public function testAggregates()
    {
        $aggregates = $this->testSubject->getParameter('governor.aggregates');
        $this->assertCount(2, $aggregates);

        $this->assertTrue($aggregates['dummy1']['handler']);
        $this->assertFalse($aggregates['dummy2']['handler']);

        $count = 0;

        foreach ($this->testSubject->getServiceIds() as $id) {
            if (preg_match('/^governor.aggregate_command_handler.*/', $id)) {
                $def = $this->testSubject->getDefinition($id);

                $this->assertEquals(Dummy1Aggregate::class, $def->getArgument(0));
                $count++;;
            }
        }

        $this->assertEquals(1, $count);
    }

    public function testAmqp()
    {
        /** @var AmqpTerminal $terminal */
        $terminal = $this->testSubject->get('governor.terminal.amqp.default');
        $this->assertInstanceOf(AmqpTerminal::class, $terminal);

        $connection = $this->testSubject->get('governor.amqp.connection.default');
    }

    public function testEventBuses()
    {
        /** @var EventBusInterface $first */
        $first = $this->testSubject->get('governor.event_bus.default');
        /** @var EventBusInterface $second */
        $second = $this->testSubject->get('governor.event_bus.second');

        $this->assertInstanceOf(EventBusInterface::class, $first);
        $this->assertInstanceOf(EventBusInterface::class, $second);
    }

    public function createTestContainer()
    {

        $config = [
            'governor' => [
                'terminals' => [
                    'amqp' => [
                        'default' => [
                        ]
                    ]
                ],
                'annotation_reader' => [
                    'type' => 'file_cache',
                    'parameters' => [
                        'debug' => false,
                        'path' => '%kernel.cache_dir%'
                    ]
                ],
                'aggregates' => [
                    'dummy1' => [
                        'class' => Dummy1Aggregate::class,
                        'repository' => 'event_sourcing',
                        'handler' => true
                    ],
                    'dummy2' => [
                        'class' => Dummy2Aggregate::class,
                        'repository' => 'hybrid',
                        'handler' => false
                    ]
                ],
                'command_buses' => [
                    'default' => [
                        'class' => SimpleCommandBus::class,
                        'registry' => 'governor.command_bus_registry.in_memory'
                    ]
                ],
                'event_buses' => [
                    'default' => [
                        'class' => SimpleEventBus::class,
                        'registry' => 'governor.event_bus_registry.in_memory',
                        'terminals' => [
                            'governor.terminal.amqp.default'
                        ]
                    ],
                    'second' => [
                        'class' => SimpleEventBus::class,
                        'registry' => 'governor.event_bus_registry.in_memory'
                    ]
                ],
                'event_store' => [
                    'type' => 'mongo',
                    'parameters' => [

                    ]
                ],
                'command_gateways' => [
                    'default' => [
                        'class' => 'Governor\Framework\CommandHandling\Gateway\DefaultCommandGateway'
                    ]
                ],
                'saga_repository' => [
                    'type' => 'orm',
                    'parameters' => [
                        'entity_manager' => 'default_entity_manager'
                    ]
                ],
                'saga_manager' => [
                    'type' => 'annotation',
                    'saga_locations' => [
                        sys_get_temp_dir()
                    ]
                ],
                'order_resolver' => 'annotation',
                'mongo_templates' => [
                    'default' => [
                        'server' => 'mongodb://localhost:27017',
                        'database' => 'test'
                    ]
                ]
            ]
        ];

        $container = new ContainerBuilder(
            new ParameterBag(
                [
                    'kernel.debug' => false,
                    'kernel.bundles' => [],
                    'kernel.cache_dir' => sys_get_temp_dir(),
                    'kernel.environment' => 'test',
                    'kernel.root_dir' => __DIR__.'/../../../../' // src dir
                ]
            )
        );

        $loader = new GovernorFrameworkExtension();

        $container->setProxyInstantiator(new RuntimeInstantiator());

        $container->registerExtension($loader);
        $container->set(
            'doctrine.orm.default_entity_manager',
            $this->getMock(
                \Doctrine\ORM\EntityManager::class,
                [
                    'find',
                    'flush',
                    'persist',
                    'remove'
                ],
                [],
                '',
                false
            )
        );

        $container->set('logger', $this->getMock(LoggerInterface::class));
        $container->set('debug.stopwatch', $this->getMock(Stopwatch::class));
        $container->set('jms_serializer', $this->getMock(\JMS\Serializer\SerializerInterface::class));
        $container->set('validator', $this->getMock(\Symfony\Component\Validator\ValidatorInterface::class));

        $this->addTaggedCommandHandlers($container);
        $this->addTaggedEventListeners($container);

        $loader->load($config, $container);

        $container->addCompilerPass(new CommandHandlerPass(), PassConfig::TYPE_BEFORE_REMOVING);
        $container->addCompilerPass(new EventHandlerPass(), PassConfig::TYPE_BEFORE_REMOVING);
        $container->addCompilerPass(new AggregateCommandHandlerPass(), PassConfig::TYPE_BEFORE_REMOVING);

        $container->compile();

        return $container;
    }

    private function addTaggedCommandHandlers(ContainerBuilder $container)
    {
        $definition = new Definition(ContainerCommandHandler1::class);
        $definition->addTag('governor.command_handler')
            ->setPublic(true);

        $container->setDefinition('test.command_handler', $definition);
    }

    private function addTaggedEventListeners(ContainerBuilder $container)
    {
        $definition = new Definition(ContainerEventListener1::class);
        $definition->addTag('governor.event_handler')
            ->setPublic(true);

        $container->setDefinition('test.event_handler', $definition);
    }

}

class Dummy1Aggregate extends AbstractAnnotatedAggregateRoot
{

    public function getIdentifier()
    {
        // TODO: Implement getIdentifier() method.
    }

    /**
     * @CommandHandler()
     * @param ContainerCommand2 $command
     */
    public function doSomething(ContainerCommand2 $command)
    {

    }

}

class Dummy2Aggregate extends AbstractAggregateRoot
{

    public function getIdentifier()
    {
        // TODO: Implement getIdentifier() method.
    }

}

class ContainerCommand1
{

}

class ContainerCommand2
{

}

class ContainerEvent1
{

}

class ContainerCommandHandler1
{

    /**
     * @param ContainerCommand1 $command
     * @CommandHandler
     */
    public function onCommand1(ContainerCommand1 $command)
    {

    }

}

class ContainerEventListener1
{

    /**
     * @EventHandler
     */
    public function onEvent1(ContainerEvent1 $event)
    {

    }

}
