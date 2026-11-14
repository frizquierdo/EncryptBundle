# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`psolutions/encrypt-bundle` is a Symfony bundle (PSR-4 namespace `PSolutions\EncryptBundle\`) that transparently
encrypts/decrypts Doctrine entity fields marked with `#[Encrypted]`, using AES-256-GCM authenticated encryption.
It is a fork of https://github.com/mogilvie/EncryptBundle. Published standalone via Composer/Packagist, installed
into host Symfony applications (there is no `bin/console` or app kernel in this repo itself — it's a library).

## Commands

Install dependencies:
```bash
composer install
```

Run the test suite (uses `symfony/phpunit-bridge`, not raw phpunit):
```bash
vendor/bin/simple-phpunit
```

Run a single test file or test method:
```bash
vendor/bin/simple-phpunit tests/Unit/Encryptors/OpenSslEncryptorTest.php
vendor/bin/simple-phpunit --filter testEncryptReturnsExpectedFormat
```

`phpunit.xml.dist` defines two suites: `tests/Unit` and `tests/Integration`. Integration tests use an in-memory SQLite
entity manager (`tests/Fixtures/OrmTestCase.php`) and are skipped without `pdo_sqlite`. If the local PHP lacks it, run the
full suite in a container that has it (the official PHP images do):
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/simple-phpunit
```

After changing `recipe/manifest.json` or `recipe/config/`, run `php recipe/build.php` (a test checks they match
`InstallCommand::CONFIG_TEMPLATE` and the compiled recipe).

## Architecture

### Request flow: how a field gets encrypted/decrypted

1. **`PSolutionsEncryptBundle`** (`src/PSolutionsEncryptBundle.php`) is an `AbstractBundle` that defines the
   `psolutions_encrypt` config tree (encrypt_key, method, listener_class, encryptor_class, is_disabled,
   connections, annotation_classes) and imports `config/services.yaml` into the container.
2. **`EncryptorFactory`** (`src/Encryptors/EncryptorFactory.php`) is a DI factory that instantiates the
   configured encryptor class (default `OpenSslEncryptor`), validates it implements `EncryptorInterface`,
   and injects the base64 secret key from `%psolutions_encrypt.encrypt_key%`.
3. **`DoctrineEncryptListener`** (`src/EventListener/DoctrineEncryptListener.php`) is tagged as a Doctrine
   listener for `onFlush`, `postLoad`, and `postUpdate` (see `config/services.yaml`). This is where the actual
   entity-field encryption/decryption happens via reflection:
   - `postLoad` → decrypts fields right after Doctrine hydrates the entity, and calls
     `UnitOfWork::setOriginalEntityProperty()` so Doctrine's change tracking treats the decrypted value as
     the baseline (otherwise every load would look like a pending change).
   - `onFlush` → for scheduled insertions/updates, re-encrypts fields that are in Doctrine's change set,
     then calls `recomputeSingleEntityChangeSet()`. For inserts the decrypted value is restored on the
     entity immediately after; for updates the decrypted value is stashed in `$this->rawValues` keyed by
     `spl_object_id()`.
   - `postUpdate` → restores the stashed decrypted values from `$this->rawValues` onto the entity so the
     in-memory object remains decrypted after a flush.
   - Which fields are encrypted is determined by scanning `ClassMetadata::getFieldNames()` for properties
     carrying one of the configured `annotation_classes` (default: `Attributes\Encrypted`), with per-class
     results cached in `$encryptedFieldCache`.
4. **`OpenSslEncryptor`** (`src/Encryptors/OpenSslEncryptor.php`) does the actual AES-256-GCM crypto. Format:
   `base64(IV[12 bytes] . TAG[16 bytes] . ciphertext) . '<ENC>'`. The `<ENC>` suffix
   (`DoctrineEncryptListenerInterface::ENCRYPTED_SUFFIX`) is the signal used everywhere to decide whether a
   value is already encrypted — both `encrypt()` and `decrypt()` are idempotent/no-op guards based on this
   suffix, so passing an already-encrypted or plain value through either method is always safe.
5. Decryption failures throw `EncryptException` (not caught) — a short decoded length is used as a heuristic
   to detect pre-v2.1 CBC-encrypted data and throw a migration-specific message instead of a generic one.

### Secondary paths that bypass the Doctrine listener

- **Twig**: `EncryptExtension` (`src/Twig/EncryptExtension.php`) exposes a `decrypt` filter for values fetched
  via array-hydrating queries (DQL `SELECT ... FROM ... (array result)`), which skip Doctrine's entity
  lifecycle events entirely.
- **Events**: `EncryptEventListener` (`src/EventListener/EncryptEventListener.php`) listens for the
  `EncryptEvents::ENCRYPT`/`DECRYPT` kernel events (dispatched manually via `EncryptEvent`) — the way to
  encrypt/decrypt an arbitrary string outside of an entity context (e.g. in a controller/service).
- **Key override**: `EncryptKeyEvents::LOAD_KEY` is dispatched by `OpenSslEncryptor::getValidatedSecretKey()`
  on every encrypt/decrypt call, letting a listener override the configured key at runtime via
  `EncryptKeyEvent::setKey()` — takes priority over the `.env`/config key.
- **`encrypt:database` command** (`src/Command/EncryptDatabaseCommand.php`) bulk-encrypts/decrypts an entire
  database by reflecting over all registered entity metadata, finding encrypted columns, and running raw
  SQL `SELECT`/`UPDATE` directly through the DBAL connection — it does **not** go through the Doctrine
  entity lifecycle or the `DoctrineEncryptListener`. This is the required tool for migrating between
  encryption formats (see README's CBC→GCM migration steps) since changing the algorithm means the
  previous format must be fully decrypted to plaintext before switching encryptors and re-encrypting.
- **`encrypt:genkey` command** (`src/Command/GenKeyCommand.php`) just emits a random base64-encoded 256-bit key.

### Blind indexes and key maintenance (v5.1)

- **`#[BlindIndex(source, normalizer)]`** marks a column holding `HMAC-SHA256(normalize(plain source), key)`.
  `BlindIndexer` (`src/BlindIndex/`) owns the metadata scan (cached per class, validates that an index is not
  `#[Encrypted]` and its source is a mapped field), the key (dedicated `blind_index_key`, or HKDF-derived from
  `encrypt_key`, following `LOAD_KEY` overrides) and the normalizers (tagged locator of
  `BlindIndexNormalizerInterface` services).
- The listener computes indexes in `onFlush` **before** encrypting and before the `is_disabled` early return.
- **`Maintenance\DatabaseRewriter`** is the shared DBAL engine of `encrypt:blind-index:rebuild`,
  `encrypt:rotate-key` and the index step of `encrypt:database`: read-only scan (errors, collisions on unique
  indexes) → write in transactions on all managers → verify (decrypt with the target encryptor, recompute indexes)
  → commit all, or roll back all.
- On insert, the listener sets the restored plain value as the UoW original (`setOriginalEntityProperty`); without
  it, each `recomputeSingleEntityChangeSet()` overwrote the change set of the previous encrypted field.

### Key invariants to preserve

- `EncryptorInterface::encrypt()`/`decrypt()` must always return `null` for `null` input, and must be
  safe to call on values that are already in the target state (checked via the `<ENC>` suffix) — multiple
  call sites rely on this idempotency instead of checking state themselves beforehand.
- The bundle supports swapping in a custom encryptor (`encryptor_class` config) or listener
  (`listener_class` config) — avoid hardcoding `OpenSslEncryptor`/`DoctrineEncryptListener` outside of
  `config/services.yaml` defaults; go through `EncryptorInterface`/`DoctrineEncryptListenerInterface`.
- `getEncryptedFields()`/`isEncryptedField()` in `DoctrineEncryptListener` build the reflection class from
  Doctrine's own `ClassMetadata::getReflectionClass()` rather than `new ReflectionClass($className)`, to
  stay correct for proxy classes and across Doctrine 2/3 metadata differences — `EncryptDatabaseCommand`
  follows the same pattern.
