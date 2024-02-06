<?php

namespace PSolutions\EncryptBundle\Twig;

use PSolutions\EncryptBundle\Encryptors\EncryptorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class EncryptExtension extends AbstractExtension {

    private EncryptorInterface $encryptor;

    public function __construct(EncryptorInterface $encryptor) {
        $this->encryptor = $encryptor;
    }

    /**
     * @return TwigFilter[]
     */
    public function getFilters(): array {
        return array(
            new TwigFilter('decrypt', array($this, 'decryptFilter'))
        );
    }

    public function decryptFilter(string $data): ?string {
        return $this->encryptor->decrypt($data);
    }

    public function getName(): string {
        return 'psolutions_encrypt_extension';
    }
}
