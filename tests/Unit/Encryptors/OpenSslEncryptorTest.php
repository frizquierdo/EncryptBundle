<?php

namespace PSolutions\EncryptBundle\tests\Unit\Encryptors;

use PSolutions\EncryptBundle\Encryptors\OpenSslEncryptor;
use PSolutions\EncryptBundle\Exception\EncryptException;
use Symfony\Component\EventDispatcher\EventDispatcher;

class OpenSslEncryptorTest extends \PHPUnit\Framework\TestCase
{
    private const TEST_KEY = 'YBmNcBGfrZoayB+V254wdYa/abvxSUWJsjCtlMc1tRI=';

    public function testConstruct(): void
    {
        $encryptor = new OpenSslEncryptor(new EventDispatcher());
        $this->assertInstanceOf(OpenSslEncryptor::class, $encryptor);
    }

    public function testEncryptWithNull(): void
    {
        $encryptor = new OpenSslEncryptor(new EventDispatcher());
        $encryptor->setSecretKey(self::TEST_KEY);

        $result = $encryptor->encrypt(null);
        $this->assertNull($result);
    }

    public function testEncryptWithAlreadyEncryptedValue(): void
    {
        $encryptor = new OpenSslEncryptor(new EventDispatcher());
        $encryptor->setSecretKey(self::TEST_KEY);

        $alreadyEncrypted = 'somevalue<ENC>';
        $result = $encryptor->encrypt($alreadyEncrypted);
        $this->assertSame($alreadyEncrypted, $result);
    }

    public function testEncryptReturnsExpectedFormat(): void
    {
        $encryptor = new OpenSslEncryptor(new EventDispatcher());
        $encryptor->setSecretKey(self::TEST_KEY);

        $plaintext = 'Test data 123';
        $ciphertext = $encryptor->encrypt($plaintext);

        // Must end with <ENC>
        $this->assertStringEndsWith('<ENC>', $ciphertext);

        // Must be base64-encoded before the suffix
        $base64Part = substr($ciphertext, 0, -5);
        $decoded = base64_decode($base64Part, true);
        $this->assertFalse($decoded === false, 'Ciphertext should be valid base64');

        // Decoded length must be at least IV + TAG (12 + 16 = 28 bytes)
        $this->assertGreaterThanOrEqual(28, strlen($decoded));
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $encryptor = new OpenSslEncryptor(new EventDispatcher());
        $encryptor->setSecretKey(self::TEST_KEY);

        $testCases = [
            'simple string',
            'Honey, where are my pants?',
            '1234567890',
            'Special chars: !@#$%^&*()',
            'UTF-8: café naïve résumé',
            '', // empty string
        ];

        foreach ($testCases as $plaintext) {
            $encrypted = $encryptor->encrypt($plaintext);
            $this->assertIsString($encrypted);
            $this->assertStringEndsWith('<ENC>', $encrypted);

            $decrypted = $encryptor->decrypt($encrypted);
            $this->assertSame($plaintext, $decrypted, "Round-trip failed for: $plaintext");
        }
    }

    public function testDecryptWithNull(): void
    {
        $encryptor = new OpenSslEncryptor(new EventDispatcher());
        $encryptor->setSecretKey(self::TEST_KEY);

        $result = $encryptor->decrypt(null);
        $this->assertNull($result);
    }

    public function testDecryptWithNonEncryptedValue(): void
    {
        $encryptor = new OpenSslEncryptor(new EventDispatcher());
        $encryptor->setSecretKey(self::TEST_KEY);

        $plain = 'Normal text without suffix';
        $result = $encryptor->decrypt($plain);
        $this->assertSame($plain, $result);
    }

    public function testDecryptWithInvalidBase64(): void
    {
        $encryptor = new OpenSslEncryptor(new EventDispatcher());
        $encryptor->setSecretKey(self::TEST_KEY);

        // Not valid base64, but ends with <ENC>
        $invalid = 'not-valid-base64-data<ENC>';

        $this->expectException(EncryptException::class);
        $this->expectExceptionMessage('Invalid base64 encoded encrypted data');
        $encryptor->decrypt($invalid);
    }

    public function testDecryptWithTooShortData(): void
    {
        $encryptor = new OpenSslEncryptor(new EventDispatcher());
        $encryptor->setSecretKey(self::TEST_KEY);

        // Valid base64 but too short to contain IV + TAG (minimum 28 bytes decoded)
        $short = base64_encode('x<ENC>'); // 2 chars decoded

        $this->expectException(EncryptException::class);
        $this->expectExceptionMessage('Encrypted data is too short');
        $encryptor->decrypt($short);
    }

    public function testDecryptDetectsTampering(): void
    {
        $encryptor = new OpenSslEncryptor(new EventDispatcher());
        $encryptor->setSecretKey(self::TEST_KEY);

        $plaintext = 'Secret message';
        $ciphertext = $encryptor->encrypt($plaintext);

        // Tamper with the base64 string (change one character before <ENC>)
        $tampered = substr_replace($ciphertext, 'A', 5, 1);

        $this->expectException(EncryptException::class);
        $this->expectExceptionMessage('Decryption failed');
        $encryptor->decrypt($tampered);
    }

    public function testDecryptDetectsOldCbcFormat(): void
    {
        $encryptor = new OpenSslEncryptor(new EventDispatcher());
        $encryptor->setSecretKey(self::TEST_KEY);

        // Simulate old CBC encrypted data: base64(IV=16 + ciphertext) with <ENC> suffix
        // CBC used 16-byte IV, no tag. Total length will be less than GCM's 28 bytes minimum
        // if ciphertext is short. We'll create a 20-byte decoded total (16 IV + 4 ciphertext)
        $fakeCbcIv = random_bytes(16);
        $fakeCbcCipher = 'test';
        $fakeCbc = base64_encode($fakeCbcIv . $fakeCbcCipher) . '<ENC>';

        $this->expectException(EncryptException::class);
        $this->expectExceptionMessage('appears to be encrypted with the old CBC format');
        $encryptor->decrypt($fakeCbc);
    }

    public function testDecryptDetectsTagMismatch(): void
    {
        $encryptor = new OpenSslEncryptor(new EventDispatcher());
        $encryptor->setSecretKey(self::TEST_KEY);

        $plaintext = 'Another secret';
        $ciphertext = $encryptor->encrypt($plaintext);

        // Decode, modify a byte in the ciphertext portion, re-encode
        $withoutSuffix = substr($ciphertext, 0, -5);
        $decoded = base64_decode($withoutSuffix);
        $modified = substr($decoded, 0, 30) . chr(ord($decoded[30]) ^ 0x01) . substr($decoded, 31);
        $tampered = base64_encode($modified) . '<ENC>';

        $this->expectException(EncryptException::class);
        $encryptor->decrypt($tampered);
    }

    public function testEncryptWithEmptyString(): void
    {
        $encryptor = new OpenSslEncryptor(new EventDispatcher());
        $encryptor->setSecretKey(self::TEST_KEY);

        $encrypted = $encryptor->encrypt('');
        $decrypted = $encryptor->decrypt($encrypted);
        $this->assertSame('', $decrypted);
    }

    public function testDifferentEncryptionsProduceDifferentCiphertexts(): void
    {
        $encryptor = new OpenSslEncryptor(new EventDispatcher());
        $encryptor->setSecretKey(self::TEST_KEY);

        $plaintext = 'same data';
        $ciphertext1 = $encryptor->encrypt($plaintext);
        $ciphertext2 = $encryptor->encrypt($plaintext);

        // Due to random IV, ciphertexts must be different
        $this->assertNotSame($ciphertext1, $ciphertext2);

        // Both must decrypt correctly
        $this->assertSame($plaintext, $encryptor->decrypt($ciphertext1));
        $this->assertSame($plaintext, $encryptor->decrypt($ciphertext2));
    }

    public function testInvalidKeyLengthThrowsException(): void
    {
        $encryptor = new OpenSslEncryptor(new EventDispatcher());

        // Too short key
        $this->expectException(EncryptException::class);
        $encryptor->setSecretKey('short-key');
        $encryptor->encrypt('test');
    }

    public function testMethodConstant(): void
    {
        $this->assertSame('aes-256-gcm', OpenSslEncryptor::METHOD);
    }
}
