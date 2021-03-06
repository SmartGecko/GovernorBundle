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

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Describes the configuration of the Governor Framework.
 * 
 * @author    "David Kalosi" <david.kalosi@gmail.com>  
 * @license   <a href="http://www.opensource.org/licenses/mit-license.php">MIT License</a> 
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('governor');

        $rootNode
            ->children()
                ->scalarNode('node_name')->isRequired()->end()
                ->scalarNode('uow_factory')->defaultValue('governor.uow_factory.default')->end()
                ->scalarNode('command_target_resolver')
                    ->defaultValue('annotation')
                    ->validate()
                    ->ifNotInArray(['annotation', 'metadata'])
                        ->thenInvalid('Invalid command target resolver "%s", possible values are '.
                                       "[\"annotation\",\"metadata\"]")
                    ->end()
                ->end()
                ->scalarNode('order_resolver')
                    ->defaultValue('annotation')
                ->end()
                ->scalarNode('lock_manager')
                    ->defaultValue('null')
                    ->validate()
                    ->ifNotInArray(['null', 'optimistic', 'pesimistic'])
                        ->thenInvalid('Invalid lock manager "%s", possible values are '.
                                       "[\"null\",\"optimistic\",\"pesimistic\"]")
                    ->end()
                ->end()
                ->arrayNode('annotation_reader')
                    ->children()
                        ->scalarNode('type')
                            ->defaultValue('simple')
                            ->validate()
                            ->ifNotInArray(['simple', 'file_cache'])
                            ->thenInvalid('Invalid annotation reader "%s", possible values are '.
                                "[\"simple\",\"file_cache\"]")
                            ->end()
                        ->end()
                        ->arrayNode('parameters')
                            ->children()
                                ->scalarNode('path')->end()
                                ->booleanNode('debug')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('event_store')
                    ->append($this->addMongoTemplates())
                    ->children()
                        ->scalarNode('type')                            
                            ->validate()
                            ->ifNotInArray(['orm', 'mongo', 'filesystem'])
                                ->thenInvalid('Invalid event store "%s", possible values are '.
                                           "[\"orm\",\"mongo\", \"filesystem\"]")
                            ->end()
                        ->end()
                        ->arrayNode('parameters')
                            ->children()
                                ->scalarNode('entity_manager')->end()
                                ->scalarNode('entry_store')->end()
                                ->scalarNode('mongo_template')->defaultValue('governor.event_store.mongo_template.default')->end()
                                ->scalarNode('storage_strategy')->defaultValue('governor.storage_strategy.event')->end()
                                ->scalarNode('directory')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('saga_repository')
                    ->append($this->addMongoTemplates())
                    ->children()
                        ->scalarNode('type')
                            ->defaultValue('orm')
                            ->validate()
                            ->ifNotInArray(['orm', 'mongo'])
                                ->thenInvalid('Invalid saga repository "%s", possible values are '.
                                           "[\"orm\",\"mongo\"]")
                            ->end()
                        ->end()
                        ->arrayNode('parameters')
                            ->children()
                                ->scalarNode('entity_manager')->end()
                                ->scalarNode('mongo_template')->defaultValue('governor.saga_repository.mongo_template.default')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('serializer')
                    ->defaultValue('jms')
                    ->validate()
                    ->ifNotInArray(['jms'])
                        ->thenInvalid('Invalid serializer "%s", possible values are '.
                                           "[\"jms\"]")
                    ->end()
                ->end()
                ->arrayNode('saga_manager')
                    ->children()
                        ->scalarNode('type')->defaultValue('annotation')->end()
                        ->scalarNode('event_bus')->defaultValue('default')->end()
                        ->arrayNode('saga_locations')
                            ->prototype('scalar')->end()
                        ->end()
                    ->end()
                ->end()
                ->append($this->addAggregatesNode())
                ->append($this->addRoutingStrategies())
                ->append($this->addCommandBusesNode())
                ->append($this->addEventBusesNode())
                ->append($this->addCommandGatewaysNode())
                ->append($this->addTerminalsNode())
                ->append($this->addConnectorsNode())
            ->end();

        return $treeBuilder;
    }

    private function addRoutingStrategies()
    {
        $treeBuilder = new TreeBuilder();
        $node = $treeBuilder->root('routing_strategies');

        $node->useAttributeAsKey('name')
            ->prototype('array')
                ->children()
                    ->scalarNode('type')->isRequired()->end()
                    ->arrayNode('parameters')
                        ->children()
                            ->scalarNode('key_name')->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private function addCommandBusesNode()
    {
        $treeBuilder = new TreeBuilder();
        $node = $treeBuilder->root('command_buses');

        $node->useAttributeAsKey('name')
            ->prototype('array')
                ->children()
                    ->scalarNode('type')->isRequired()->end()
                    ->scalarNode('connector')->end()
                    ->scalarNode('routing_strategy')->end()
                    ->arrayNode('handler_interceptors')
                        ->prototype('scalar')->end()
                    ->end()
                    ->arrayNode('dispatch_interceptors')
                        ->prototype('scalar')->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private function addEventBusesNode()
    {
        $treeBuilder = new TreeBuilder();
        $node = $treeBuilder->root('event_buses');

        $node
            ->useAttributeAsKey('name')
            ->prototype('array')
                ->children()
                    ->scalarNode('class')->isRequired()->end()
                    ->scalarNode('registry')->isRequired()->end()
                    ->arrayNode('terminals')
                        ->prototype('scalar')->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private function addMongoTemplates()
    {
        $treeBuilder = new TreeBuilder();
        $node = $treeBuilder->root('mongo_templates');

        $node
            ->useAttributeAsKey('name')
            ->prototype('array')
                ->children()
                    ->scalarNode('server')->isRequired()->end()
                    ->scalarNode('database')->isRequired()->end()
                    ->scalarNode('auth_database')->defaultValue(null)->end()
                    ->scalarNode('event_collection')->defaultValue(null)->end()
                    ->scalarNode('snapshot_collection')->defaultValue(null)->end()
                ->end()
            ->end();

        return $node;
    }

    private function addCommandGatewaysNode()
    {
        $treeBuilder = new TreeBuilder();
        $node = $treeBuilder->root('command_gateways');

        $node
            ->useAttributeAsKey('name')
            ->prototype('array')
                ->children()
                    ->scalarNode('class')->isRequired()->end()
                    ->scalarNode('command_bus')->defaultValue('default')->end()
                ->end()
            ->end();

        return $node;
    }

    private function addTerminalsNode()
    {
        $treeBuilder = new TreeBuilder();
        $node = $treeBuilder->root('terminals');
        
        $node
            ->children()
                ->arrayNode('amqp')
                    ->canBeUnset()
                        ->useAttributeAsKey('name')
                        ->prototype('array')
                            ->cannotBeEmpty()
                            ->children()
                                ->scalarNode('host')->defaultValue('localhost')->end()
                                ->scalarNode('port')->defaultValue(5672)->end()
                                ->scalarNode('user')->defaultValue('guest')->end()
                                ->scalarNode('password')->defaultValue('guest')->end()
                                ->scalarNode('vhost')->defaultValue('/')->end()
                                ->scalarNode('routing_key_resolver')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
        
        return $node;
    }

    private function addConnectorsNode()
    {
        $treeBuilder = new TreeBuilder();
        $node = $treeBuilder->root('connectors');

        $node
            ->children()
                ->arrayNode('redis')
                    ->canBeUnset()
                        ->useAttributeAsKey('name')
                        ->prototype('array')
                            ->cannotBeEmpty()
                            ->children()
                                ->scalarNode('url')->defaultValue('tcp://127.0.0.1:6379')->end()
                                ->scalarNode('routing_strategy')->end()
                                ->scalarNode('local_segment')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private function addAggregatesNode()
    {
        $treeBuilder = new TreeBuilder();
        $node = $treeBuilder->root('aggregates');

        $node
            ->useAttributeAsKey('name')
                ->prototype('array')
                    ->children()
                        ->scalarNode('class')->isRequired()->end()
                        ->booleanNode('handler')->defaultValue(false)->end()
                        ->scalarNode('event_bus')->defaultValue('default')->end()
                        ->scalarNode('command_bus')->defaultValue('default')->end()
                        ->scalarNode('factory')->end()
                        ->scalarNode('repository')
                            ->isRequired()
                            ->cannotBeEmpty()
                            ->validate()
                            ->ifNotInArray(['orm', 'event_sourcing', 'hybrid'])
                                ->thenInvalid("Invalid repository type %s, possible values are " .
                                            "[\"orm\",\"event_sourcing\",\"hybrid\"]")
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end();

        return $node;
    }

}
