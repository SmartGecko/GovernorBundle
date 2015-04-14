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

namespace Governor\Bundle\GovernorBundle\DependencyInjection\Compiler;

use Governor\Framework\Annotations\CommandHandler;
use Governor\Framework\CommandHandling\Handlers\AnnotatedCommandHandler;
use Governor\Framework\Common\Annotation\MethodMessageHandlerInspector;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Description of CommandHandlerPass
 *
 * @author david
 */
class CommandHandlerPass extends AbstractHandlerPass
{

    public function process(ContainerBuilder $container)
    {
        foreach ($container->findTaggedServiceIds('governor.command_handler') as $id => $attributes) {
            $busDefinition = $container->findDefinition(
                sprintf(
                    "governor.command_bus.%s",
                    isset($attributes['command_bus']) ? $attributes['command_bus']
                        : 'default'
                )
            );

            $definition = $container->findDefinition($id);
            $class = $definition->getClass();

            $inspector = new MethodMessageHandlerInspector(
                new \ReflectionClass($class),
                CommandHandler::class
            );

            foreach ($inspector->getHandlerDefinitions() as $handlerDefinition) {
                $handlerId = $this->getHandlerIdentifier("governor.command_handler");

                $container->register($handlerId, AnnotatedCommandHandler::class)
                    ->addArgument($class)
                    ->addArgument($handlerDefinition->getMethod()->name)
                    ->addArgument(new Reference('governor.parameter_resolver_factory'))
                    ->addArgument(new Reference($id))
                    ->setPublic(true)
                    ->setLazy(true);

                $busDefinition->addMethodCall(
                    'subscribe',
                    array($handlerDefinition->getPayloadType(), new Reference($handlerId))
                );
            }
        }
    }

}
