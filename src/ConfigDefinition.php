<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter;

use Keboola\Component\Config\BaseConfigDefinition;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

class ConfigDefinition extends BaseConfigDefinition
{
    protected function getParametersDefinition(): ArrayNodeDefinition
    {
        $parametersNode = parent::getParametersDefinition();
        // @formatter:off
        /** @noinspection NullPointerExceptionInspection */
        $parametersNode->children()
            ->booleanNode('loadOnly')->end()
            ->booleanNode('multiLoad')->end()
            ->scalarNode('action')->end()
            ->scalarNode('bucket')->end() // Bucket for readModel action
            ->scalarNode('configurationId')->end() // configurationId for readModel action
            ->arrayNode('user')->isRequired()
                ->children()
                    ->scalarNode('uid')->end()
                    ->scalarNode('login')->isRequired()->cannotBeEmpty()->end()
                    ->scalarNode('#password')->isRequired()->cannotBeEmpty()->end()
                ->end()
            ->end()
            ->arrayNode('project')->isRequired()
                ->children()
                    ->scalarNode('pid')->isRequired()->cannotBeEmpty()->end()
                    ->scalarNode('backendUrl')->end()
                ->end()
            ->end()
            ->arrayNode('tables')->useAttributeAsKey('name')
                ->normalizeKeys(false)
                ->arrayPrototype()
                    ->children()
                        ->scalarNode('title')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('identifier')->end()
                        ->booleanNode('disabled')->end()
                        ->scalarNode('anchorIdentifier')->end()
                        ->arrayNode('columns')->isRequired()->useAttributeAsKey('name')
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('type')->isRequired()->cannotBeEmpty()->end()
                                    ->scalarNode('title')->end()
                                    ->scalarNode('dataType')->end()
                                    ->scalarNode('dataTypeSize')->end()
                                    ->scalarNode('dateDimension')->end()
                                    ->scalarNode('reference')->end()
                                    ->scalarNode('schemaReference')->end()
                                    ->scalarNode('format')->end()
                                    ->scalarNode('sortLabel')->end()
                                    ->scalarNode('sortOrder')->end()
                                    ->scalarNode('identifier')->end()
                                    ->scalarNode('identifierLabel')->end()
                                    ->scalarNode('identifierTimeFact')->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('grain')
                            ->scalarPrototype()->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->arrayNode('dimensions')->useAttributeAsKey('name')
                ->arrayPrototype()
                    ->children()
                        ->scalarNode('includeTime')->defaultValue(false)->end()
                        ->scalarNode('template')->end()
                        ->scalarNode('identifier')->end()
                    ->end()
                ->end()
            ->end()
        ->end();
        // @formatter:on
        return $parametersNode;
    }
}
