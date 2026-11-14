# Change Log

## [5.1.0] (2026-11-14)

Backwards compatible: no change to the encrypted format or the existing API.

### Added
- **Blind indexes** for searchable encrypted fields: `#[BlindIndex(source: 'email', normalizer: BlindIndex::LOWER_TRIM)]`
  stores `HMAC-SHA256(normalize(value), key)` next to the encrypted field, usable in `WHERE` and `UNIQUE` indexes.
  Computed by the Doctrine listener on flush (also when `is_disabled` is true). Query with `BlindIndexer::hash()`.
  Normalizers: `NONE`, `LOWER_TRIM` or any `BlindIndexNormalizerInterface` service (autoconfigured).
- `blind_index_key` option. Default: derived from `encrypt_key` with HKDF (never the same key for both).
- `encrypt:blind-index:rebuild`: recompute indexes of existing rows, in batches, all-or-nothing; lists collisions on
  unique indexes without writing anything; `--dry-run`, `--entity`, `--connection`.
- `encrypt:rotate-key`: rotate `encrypt_key` and/or `blind_index_key` in one step, in one transaction per
  connection, verified before commit (rollback on any error or collision); `--dry-run`, `--write-env`.
- `encrypt:install`: creates `config/packages/psolutions_encrypt.yaml` and the key(s) in `.env`/`.env.local`.
  Idempotent; `--force`, `--local`, `--blind-index-key`.
- `encrypt:genkey --blind-index`; `encrypt:genkey` now warns that a generated key is not applied by itself.
- `encrypt:database encrypt` rebuilds blind indexes afterwards (`--no-blind-index`); `decrypt --clear-blind-index`.
- Flex recipe endpoint (`recipe/index.json`) for projects that declare it.

### Changed
- `encrypt_key` defaults to `%env(default::PSOLUTIONS_ENCRYPT_KEY)%`: the bundle works without a config file.
  A missing key, or the `change_me!` placeholder, fails on first use with an `EncryptException` pointing to
  `encrypt:install` / `encrypt:genkey`.
- Removed `extra.symfony.recipes` from `composer.json` (Flex ignores it in dependencies).
- `symfony/yaml` is now a declared dependency (the bundle loads `config/services.yaml`).

### Fixed
- **Inserting an entity with two or more `#[Encrypted]` fields stored all but the last one in plain text.**
  Re-save affected rows: run `bin/console encrypt:database encrypt` (it only encrypts values that are not
  encrypted yet).
- `encryptor_class` was ignored: the configured encryptor is now passed to the factory.
- `encrypt:database` stopped scanning entities after the first table shared by single table inheritance, and
  used the deprecated `ClassMetadata::getReflectionProperties()`.
- `OpenSslEncryptor::decrypt()`: base64 is decoded in strict mode (invalid input now throws
  "Invalid base64 encoded encrypted data"), and the hint for pre-v2.1 CBC data is reachable again.
- The test suite runs (`phpunit.xml.dist` with the autoloader).

## [5.0.0] (2026-05-10) - Security Enhancement

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