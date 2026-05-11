# Change Log

## [Unreleased] - Security Enhancement

### 🚨 BREAKING CHANGE: Encryption Algorithm Upgrade (CBC → GCM)

**This version changes the encryption algorithm from AES-256-CBC to AES-256-GCM.**

#### Impact
- **Data encrypted with previous versions CANNOT be decrypted** after upgrading.
- **Migration is MANDATORY** for any database containing encrypted data.
- Encrypted values are now longer (include authentication tag) and end with same `<ENC>` suffix.

#### What Changed
- `OpenSslEncryptor` now uses `aes-256-gcm` instead of `aes-256-cbc`
- Added authentication tag (16 bytes) to ciphertext for tamper detection
- Format: `base64(IV . TAG . ciphertext)` vs old `base64(IV . ciphertext)`
- Decryption now validates authentication tag; any tampering throws `EncryptException`

#### Migration Steps (MUST FOLLOW)

1. **Decrypt all existing data with OLD version** (before updating bundle):
   ```bash
   php bin/console encrypt:database decrypt
   ```

2. **Update the bundle**:
   ```bash
   composer update psolutions/encrypt-bundle
   ```

3. **Re-encrypt all data with NEW version**:
   ```bash
   php bin/console encrypt:database encrypt
   ```

4. **Verify** application works correctly.

⚠️ **Skipping step 1 will result in PERMANENT DATA LOSS** for existing encrypted records.

#### Security Improvements
- **Authenticated Encryption**: AES-GCM provides confidentiality + integrity
- **Tamper-proof**: Any modification to encrypted data is detected
- **No padding oracle vulnerabilities**: GCM mode is not vulnerable to CBC padding attacks
- **Better IV handling**: 12-byte IV (optimal for GCM) vs 16-byte IV (CBC)

#### Rollback
If you encounter issues after migration:
1. Keep database backups before starting!
2. With NEW version installed, run `encrypt:database decrypt` to get plaintext
3. Roll back to old bundle version
4. Re-encrypt with `encrypt:database encrypt` (using old CBC method)

---

## 4.0.0 (2024-02-04)  Symfony 7 and PHP 8.2
Major backward compatibility breaking change to Symfony 7 and PHP 8.2.

## 3.1.0 (2023-02-22) Update
Add attribute support for #[Encrypted] attributes instead of @Encrypted annotations.
Add option to catch doctrine events from multiple connections.
Add encrypt and decrypt CLI commands.
Refactor for symfony flex and Symfony 6 recommended third party bundle structure

## 3.0.1 (2022-03-13) Symfony 6 and PHP 8
Major backward compatibility breaking change to Symfony 6 and PHP 8.

### Encyptor Factory
- Remove logging and event dispatcher constructors
- Change constructor to allow passing of an optional encryptor class name.

Service definition was:
```yaml
    # Factory to create the encryptor/decryptor
    SpecShaper\EncryptBundle\Encryptors\EncryptorFactory:
        arguments: ['@logger', '@event_dispatcher']
        tags:
            - { name: monolog.logger, channel: app }
        
    SpecShaper\EncryptBundle\Encryptors\EncryptorInterface:
        factory: ['@SpecShaper\EncryptBundle\Encryptors\EncryptorFactory','createService']
        arguments:
            - '%spec_shaper_encrypt.method%'
            - '%spec_shaper_encrypt.encrypt_key%'
```
Service definition becomes:
```yaml
    # Factory to create the encryptor/decryptor
    SpecShaper\EncryptBundle\Encryptors\EncryptorFactory:
        arguments: ['@event_dispatcher']
        tags:
            - { name: monolog.logger, channel: app }

    # The encryptor service created by the factory according to the passed method and using the encrypt_key
    SpecShaper\EncryptBundle\Encryptors\EncryptorInterface:
        factory: ['@SpecShaper\EncryptBundle\Encryptors\EncryptorFactory','createService']
        arguments:
            $encryptKey: '%spec_shaper_encrypt.encrypt_key%'
            $encryptorClass: '%spec_shaper_encrypt.encryptor_class%' #optional
```