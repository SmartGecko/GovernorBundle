# GovernorBundle

[![Build Status](https://travis-ci.org/SmartGecko/GovernorBundle.svg?branch=master)](https://travis-ci.org/SmartGecko/GovernorBundle)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/SmartGecko/GovernorBundle/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/SmartGecko/GovernorBundle/?branch=master)
[![Code Coverage](https://scrutinizer-ci.com/g/SmartGecko/GovernorBundle/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/SmartGecko/GovernorBundle/?branch=master)

Symfony 2 bundle for the Governor framework.

## Configuration reference

### Unit Of Work Factory

* ```governor.uow_factory.default```
* ```governor.uow_factory.debug``` 

The debug UOW uses the Symfony Stopwatch component to send data to the profiler timeline.

### Annotation Reader

#### Type
* ```simple```
* ```file_cache``` 

#### Parameters

The ```file_cache``` annotation reader takes the following paramters
* ```debug: boolean```
* ```path: string``` 

### AMQP

To configure an AMQP terminal add an entry to the ```governor_framework.terminals.amqp``` section.
The bundle will create a service in the Container named ```governor.terminal.amqp.%name%```

### Command buses

To configure a command bus add an entry to the ```governor_framework.command_buses``` section. 
A default command bus named ```default``` must be configured.

* ```class: string```
* ```registry: string```
* ```dispatch_interceptors: array```
* ```handler_interceptors: array```

### Event buses

## Sample configuration
```
governor_framework:
    uow_factory: governor.uow_factory.debug
    annotation_reader:
        type: file_cache
        parameters:
            debug: %kernel.debug%
            path: "%kernel.cache_dir%/governor"
    terminals:
        amqp:
            default:
                host: %amqp_host%
                port: %amqp_port%
                user: %amqp_user%
                password: %amqp_password%
                vhost: %amqp_vhost%
    command_buses:
        default:
            class: Governor\Framework\CommandHandling\SimpleCommandBus
            registry: governor.command_bus_registry.in_memory
            dispatch_interceptors: [governor.interceptor.validator]
            handler_interceptors: [domain.governor.interceptor.audit]
    event_buses:
        default:
            class: Governor\Framework\EventHandling\SimpleEventBus
            registry: governor.event_bus_registry.in_memory
            terminals: [governor.terminal.amqp.default]
    command_gateways:
        default:
            class: Governor\Framework\CommandHandling\Gateway\DefaultCommandGateway
            command_bus: default
    lock_manager: "null"
    command_target_resolver: annotation
    order_resolver: annotation
    aggregates:
        first:
            class: My\Project\FirstAggregate
            repository: hybrid
            handler: true
        another:
            class: My\Project\AnotherAggregate
            repository: hybrid
            handler: true
    event_store:
        type: orm
        parameters:
            entity_manager: governor_entity_manager
    serializer: jms
    saga_repository:
        type: orm
        parameters:
            entity_manager: governor_entity_manager
    saga_manager:
        type: annotation
        saga_locations: ["%kernel.root_dir%/../src/SmartGecko/Domain/*/Saga"]
```
