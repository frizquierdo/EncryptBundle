<?php

declare(strict_types=1);

namespace PSolutions\EncryptBundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PSolutions\EncryptBundle\BlindIndex\BlindIndexer;
use PSolutions\EncryptBundle\Encryptors\EncryptorInterface;
use PSolutions\EncryptBundle\Exception\EncryptException;
use PSolutions\EncryptBundle\PSolutionsEncryptBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * The bundle works with no config/packages/psolutions_encrypt.yaml, only with PSOLUTIONS_ENCRYPT_KEY.
 */
class BundleConfigurationTest extends TestCase
{
    private const KEY = 'YBmNcBGfrZoayB+V254wdYa/abvxSUWJsjCtlMc1tRI=';

    protected function tearDown(): void
    {
        unset($_SERVER['PSOLUTIONS_ENCRYPT_KEY'], $_ENV['PSOLUTIONS_ENCRYPT_KEY']);
    }

    private function container(array $configs = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->setDefinition('event_dispatcher', new Definition(EventDispatcher::class));
        $container->setDefinition('doctrine', (new Definition(\stdClass::class))->setSynthetic(true));

        $bundle = new PSolutionsEncryptBundle();
        $extension = $bundle->getContainerExtension();
        $container->registerExtension($extension);
        foreach ($configs ?: [[]] as $config) {
            $container->loadFromExtension($extension->getAlias(), $config);
        }
        $bundle->build($container);

        $container->addCompilerPass(new class() implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ([EncryptorInterface::class, BlindIndexer::class] as $id) {
                    $container->getDefinition($id)->setPublic(true);
                }
            }
        });
        $container->compile(true);
        $container->set('doctrine', $this->createMock(\Doctrine\Persistence\ManagerRegistry::class));

        return $container;
    }

    public function testWorksWithOnlyTheEnvironmentVariable(): void
    {
        $_SERVER['PSOLUTIONS_ENCRYPT_KEY'] = self::KEY;
        $container = $this->container();

        /** @var EncryptorInterface $encryptor */
        $encryptor = $container->get(EncryptorInterface::class);
        $encrypted = $encryptor->encrypt('secret');

        $this->assertStringEndsWith('<ENC>', $encrypted);
        $this->assertSame('secret', $encryptor->decrypt($encrypted));
        $this->assertTrue($container->getParameter('psolutions_encrypt.blind_index_key_derived'));
        $this->assertFalse($container->get(BlindIndexer::class)->hasDedicatedKey());
    }

    public function testMissingKeyFailsOnFirstUseWithAHelpfulMessage(): void
    {
        $encryptor = $this->container()->get(EncryptorInterface::class);

        $this->expectException(EncryptException::class);
        $this->expectExceptionMessage('encrypt:install');
        $encryptor->encrypt('secret');
    }

    public function testPlaceholderKeyFails(): void
    {
        $_SERVER['PSOLUTIONS_ENCRYPT_KEY'] = 'change_me!';
        $encryptor = $this->container()->get(EncryptorInterface::class);

        $this->expectException(EncryptException::class);
        $this->expectExceptionMessage('change_me!');
        $encryptor->encrypt('secret');
    }

    public function testDedicatedBlindIndexKey(): void
    {
        $container = $this->container([['encrypt_key' => self::KEY, 'blind_index_key' => self::KEY]]);

        $this->assertTrue($container->get(BlindIndexer::class)->hasDedicatedKey());
    }
}
