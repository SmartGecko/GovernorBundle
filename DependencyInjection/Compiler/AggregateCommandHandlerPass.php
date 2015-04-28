<?php
/**
 * This file is part of the SmartGecko(c) business platform.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Governor\Bundle\GovernorBundle\DependencyInjection\Compiler;

use Governor\Framework\Annotations\CommandHandler;

use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Governor\Framework\CommandHandling\Handlers\AnnotatedAggregateCommandHandler;
use Governor\Framework\Common\Annotation\MethodMessageHandlerInspector;

class AggregateCommandHandlerPass extends AbstractHandlerPass
{
    /**
     * @param ContainerBuilder $container
     */
    public function process(ContainerBuilder $container)
    {
        $aggregates = $container->getParameter('governor.aggregates');

        foreach ($aggregates as $name => $parameters) {
            if (!$parameters['handler']) {
                continue;
            }

            $registry = $container->findDefinition(
                sprintf(
                    "governor.command_bus.registry.%s",
                    isset($attributes['command_bus']) ? $attributes['command_bus']
                        : 'default'
                )
            );

            $inspector = new MethodMessageHandlerInspector(
                $container->get('governor.annotation_reader_factory'),
                new \ReflectionClass($parameters['class']),
                CommandHandler::class
            );

            foreach ($inspector->getHandlerDefinitions() as $handlerDefinition) {
                $handlerId = $this->getHandlerIdentifier("governor.aggregate_command_handler");

                $container->register($handlerId, AnnotatedAggregateCommandHandler::class)
                    ->addArgument($parameters['class'])
                    ->addArgument($handlerDefinition->getMethod()->name)
                    ->addArgument(new Reference('governor.parameter_resolver_factory'))
                    ->addArgument(new Reference(sprintf('%s.repository', $name)))
                    ->addArgument(new Reference('governor.command_target_resolver'))
                    ->addArgument(new Reference('governor.annotation_reader_factory'))
                    ->setPublic(true)
                    ->setLazy(true);

                $registry->addMethodCall(
                    'subscribe',
                    [
                        $handlerDefinition->getPayloadType(),
                        new Reference($handlerId)
                    ]
                );
            }
        }
    }
}