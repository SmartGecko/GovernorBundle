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

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;

// !!! TODO consider removing this
class DebugCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('governor:debug')
            ->setDescription('Display currently registered commands and events.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainerBuilder();

        $maxName        = strlen('Command-Handler Service');
        $maxId          = strlen('Command');
        $maxCommandType = strlen('Class');
        $commands       = array();

        foreach ($container->findTaggedServiceIds('governor.command_handler') as $id => $attributes) {
            $definition = $container->findDefinition($id);
            $class = $definition->getClass();

            $reflClass = new \ReflectionClass($class);
            foreach ($reflClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getNumberOfParameters() != 1) {
                    continue;
                }

                $commandParam = current($method->getParameters());

                if (!$commandParam->getClass()) {
                    continue;
                }

                $commandClass = $commandParam->getClass();
                $commandType = $commandClass->getName();

                $parts = explode("\\", $commandType);
                $name = preg_replace('/Command/i', '', end($parts));

                if (strtolower($method->getName()) !== strtolower($name)) {
                    continue;
                }

                $commands[$id][$commandType] = array('name' => $commandClass->getShortName(), 'id'  => $id, 'class' => $class);

                $maxName        = max(strlen($commandClass->getShortName()), $maxName);
                $maxId          = max(strlen($id), $maxId);
                $maxCommandType = max(strlen($commandType), $maxCommandType);
            }
        }

        $output->writeln('<info>COMMANDS</info>');
        $output->writeln('<info>========</info>');
        $output->writeln('');

        $format  = '%-'.$maxId.'s %-'.$maxName.'s %s';

        // the title field needs extra space to make up for comment tags
        $format1  = '%-'.($maxId + 19).'s %-'.($maxName + 19).'s %s';
        $output->writeln(sprintf($format1, '<comment>Command-Handler Service</comment>', '<comment>Command</comment>', '<comment>Class</comment>'));

        foreach ($commands as $service => $serviceCommands) {
            foreach ($serviceCommands as $type => $command) {
                $output->writeln(sprintf($format, $service, $command['name'], $type));
            }
            $output->writeln('');
        }

        $events         = array();
        $maxName        = strlen("Event");
        $maxId          = strlen('Event-Handler Service');
        $maxEventName   = strlen('Class');

        foreach ($container->findTaggedServiceIds('governor.event_handler') as $id => $attributes) {
            $definition = $container->findDefinition($id);
            $class = $definition->getClass();

            $reflClass = new \ReflectionClass($class);
            foreach ($reflClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getNumberOfParameters() != 1) {
                    continue;
                }

                $methodName = $method->getName();
                if (strpos($methodName, "on") !== 0) {
                    continue;
                }

                $eventName = (substr($methodName, 2));

                if (!isset($services[$eventName])) {
                    $services[$eventName] = array();
                }

                $events[$id][] = array('eventName' => $eventName, 'id' => $id, 'class' => $class);
                $maxName       = max(strlen($eventName), $maxName);
                $maxId         = max(strlen($id), $maxId);
                $maxEventName  = max(strlen($eventName), $maxEventName);
            }
        }

        $output->writeln('');
        $output->writeln('<info>EVENTS</info>');
        $output->writeln('<info>========</info>');
        $output->writeln('');

        $format  = '%-'.$maxId.'s %-'.$maxEventName.'s %s';

        // the title field needs extra space to make up for comment tags
        $format1  = '%-'.($maxId + 19).'s %-'.($maxEventName + 19).'s %s';
        $output->writeln(sprintf($format1, '<comment>Event-Handler Service</comment>', '<comment>Event</comment>', '<comment>Class</comment>'));

        foreach ($events as $serviceId => $serviceEvents) {
            foreach ($serviceEvents as $event) {
                $output->writeln(sprintf($format, $event['id'], $event['eventName'], $event['class']));
            }
            $output->writeln('');
        }
    }

    /**
     * Loads the ContainerBuilder from the cache.
     *
     * @return ContainerBuilder
     */
    private function getContainerBuilder()
    {
        if (!$this->getApplication()->getKernel()->isDebug()) {
            throw new \LogicException(sprintf('Debug information about the container is only available in debug mode.'));
        }

        if (!file_exists($cachedFile = $this->getContainer()->getParameter('debug.container.dump'))) {
            throw new \LogicException(sprintf('Debug information about the container could not be found. Please clear the cache and try again.'));
        }

        $container = new ContainerBuilder();

        $loader = new XmlFileLoader($container, new FileLocator());
        $loader->load($cachedFile);

        return $container;
    }
}

