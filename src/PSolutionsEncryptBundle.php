<?php

declare(strict_types=1);

namespace PSolutions\EncryptBundle;

use PSolutions\EncryptBundle\Annotations\Encrypted;
use PSolutions\EncryptBundle\Encryptors\OpenSslEncryptor;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class PSolutionsEncryptBundle extends AbstractBundle {

    protected string $extensionAlias = 'psolutions_encrypt';

    public function getPath(): string {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void {
        $definition->rootNode()
                ->children()
                ->scalarNode('encrypt_key')->end()
                ->scalarNode('method')->defaultValue('OpenSSL')->end()
                ->scalarNode('encryptor_class')->defaultValue(OpenSslEncryptor::class)->end()
                ->scalarNode('is_disabled')->defaultValue(false)->end()
                ->arrayNode('connections')
                ->treatNullLike([])
                ->prototype('scalar')->end()
                ->defaultValue([
                    'default',
                ])
                ->end()
                ->arrayNode('annotation_classes')
                ->treatNullLike([])
                ->prototype('scalar')->end()
                ->defaultValue([
                    Encrypted::class,
                ])
                ->end()
                ->end()
        ;
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void {
        $container->import('../config/services.yaml');

        if ($builder->hasParameter('encrypt_key')) {
            trigger_deprecation('PSolutionsEncryptBundle', 'v3.0.2', 'storing PSolutions Encrypt Key in parameters is deprecated. Move to Config/Packages/psolutions_encrypt.yaml');
        }

        $encryptKey = $config['encrypt_key'];

        $container->parameters()->set($this->extensionAlias . '.encrypt_key', $encryptKey);
        $container->parameters()->set($this->extensionAlias . '.method', $config['method']);
        $container->parameters()->set($this->extensionAlias . '.encryptor_class', $config['encryptor_class']);
        $container->parameters()->set($this->extensionAlias . '.annotation_classes', $config['annotation_classes']);
        $container->parameters()->set($this->extensionAlias . '.is_disabled', $config['is_disabled']);
    }
}
