<?php

namespace Governor\Tests\Plugin\SymfonyBundle;

use Governor\Framework\Domain\AbstractAggregateRoot;
use Governor\Framework\Domain\IdentifierFactory;
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

$loader = require __DIR__ . "/../../vendor/autoload.php";
$loader->add('Governor', __DIR__);

\Doctrine\Common\Annotations\AnnotationRegistry::registerLoader(array($loader, 'loadClass'));

class GovernorFrameworkExtensionTest extends \PHPUnit_Framework_TestCase
{

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

    public function testEventHandlers()
    {
        $eventBus = $this->testSubject->get('governor.event_bus.default');

        $this->assertInstanceOf(EventBusInterface::class, $eventBus);

        $reflProperty = new \ReflectionProperty($eventBus, 'listeners');
        $reflProperty->setAccessible(true);

        $listeners = $reflProperty->getValue($eventBus);

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
            if (preg_match('/^governor.command_handler.*/', $id)) {
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

    public function createTestContainer()
    {

        $config = array(
            'governor' => array(
                'repositories' => [
                    'dummy1' => [
                        'aggregate_root' => Dummy1Aggregate::class,
                        'type' => 'orm'
                    ],
                    'dummy2' => [
                        'aggregate_root' => Dummy2Aggregate::class,
                        'type' => 'orm'
                    ]
                ],
                'aggregate_command_handlers' => [
                    'dummy1' => [
                        'aggregate_root' => Dummy1Aggregate::class,
                        'repository' => 'dummy1.repository'
                    ],
                    'dummy2' => [
                        'aggregate_root' => Dummy2Aggregate::class,
                        'repository' => 'dummy2.repository'
                    ]
                ],
                'command_buses' => [
                    'default' => [
                        'class' => SimpleCommandBus::class
                    ]
                ],
                'event_buses' => [
                    'default' => [
                        'class' => SimpleEventBus::class
                    ]
                ],
                'command_gateways' => array(
                    'default' => array(
                        'class' => 'Governor\Framework\CommandHandling\Gateway\DefaultCommandGateway'
                    )
                ),
                'clusters' => array(
                    'default' => array(
                        'class' => 'Governor\Framework\EventHandling\SimpleCluster',
                        'order_resolver' => 'governor.order_resolver'
                    )
                ),
                'saga_repository' => array(
                    'type' => 'orm',
                    'parameters' => array(
                        'entity_manager' => 'default_entity_manager'
                    )
                ),
                'saga_manager' => array(
                    'type' => 'annotation',
                    'saga_locations' => array(
                        sys_get_temp_dir()
                    )
                ),
                'cluster_selector' => array(
                    'class' => 'Governor\Framework\EventHandling\DefaultClusterSelector'
                ),
                'order_resolver' => 'annotation'
            )
        );

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

        $container->set('logger', $this->getMock(\Psr\Log\LoggerInterface::class));
        $container->set('jms_serializer', $this->getMock(\JMS\Serializer\SerializerInterface::class));
        $container->set('validator', $this->getMock(\Symfony\Component\Validator\ValidatorInterface::class));

        $this->addTaggedCommandHandlers($container);
        $this->addTaggedEventListeners($container);

        $loader->load($config, $container);

        $container->addCompilerPass(
            new CommandHandlerPass(),
            PassConfig::TYPE_BEFORE_REMOVING
        );
        $container->addCompilerPass(
            new EventHandlerPass(),
            PassConfig::TYPE_BEFORE_REMOVING
        );
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

class Dummy1Aggregate extends AbstractAggregateRoot
{

    public function getIdentifier()
    {
        // TODO: Implement getIdentifier() method.
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

class ContainerEvent1
{

}

class ContainerCommandHandler1
{

    /**
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
