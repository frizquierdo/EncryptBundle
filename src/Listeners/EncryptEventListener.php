<?php

namespace PSolutions\EncryptBundle\Listeners;

use PSolutions\EncryptBundle\Encryptors\EncryptorInterface;
use PSolutions\EncryptBundle\Event\EncryptEventInterface;
use PSolutions\EncryptBundle\Event\EncryptEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
/**
 * Event listener which encrypt/decrypt entities.
 */
#[AsEventListener(event: EncryptEvents::ENCRYPT)]
#[AsEventListener(event: EncryptEvents::DECRYPT)]
class EncryptEventListener
{
    /**
     * Encryptor created by the factory service.
     */
    protected EncryptorInterface $encryptor;

    /**
     * Store if the encryption is enabled or disabled in config.
     */
    private bool $isDisabled;

    /**
     * EncryptSubscriber constructor.
     *
     * @param $isDisabled
     */
    public function __construct(EncryptorInterface $encryptor, bool $isDisabled)
    {
        $this->encryptor = $encryptor;
        $this->isDisabled = $isDisabled;
    }

    /**
     * Return the encryptor.
     */
    public function getEncryptor(): EncryptorInterface
    {
        return $this->encryptor;
    }

    /**
     * Use an Encrypt event to encrypt a value.
     */
    public function encrypt(EncryptEventInterface $event): EncryptEventInterface
    {
        $value = $event->getValue();

        if (false === $this->isDisabled) {
            $value = $this->encryptor->encrypt($value);
        }

        $event->setValue($value);

        return $event;
    }

    /**
     * Use a decrypt event to decrypt a single value.
     */
    public function decrypt(EncryptEventInterface $event): EncryptEventInterface
    {
        $value = $event->getValue();

        $decrypted = $this->getEncryptor()->decrypt($value);

        $event->setValue($decrypted);

        return $event;
    }

    /**
     * Decrypt a value.
     *
     * If the value is an object, or if it does not contain the suffic <ENC> then return the value iteslf back.
     * Otherwise, decrypt the value and return.
     */
    public function decryptValue(?string $value): ?string
    {
        return $this->getEncryptor()->decrypt($value);
    }
}
