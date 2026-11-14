# PSolutions Encrypt Bundle

A bundle to handle encoding and decoding of parameters using OpenSSL and Doctrine lifecycle events. It's a fork of https://github.com/mogilvie/EncryptBundle

Features include:
- v1 is Symfony 6.4 and 7.0 compatible.
- Uses OpenSSL with **AES-256-GCM authenticated encryption** (since v2.1)
- Uses Event listeners
- Provides confidentiality AND authenticity (tamper-proof)

## ⚠️ IMPORTANT: Migration from Pre-v2.1 Versions

**This bundle now uses AES-256-GCM instead of the old AES-256-CBC method.**

If you are currently using a previous version of this bundle and have data already encrypted in your database:

### **MIGRATION STEPS REQUIRED**

1. **Export your existing encrypted data using the OLD version** (v2.0.x):
   ```bash
   # With the old version installed, decrypt everything first:
   php bin/console encrypt:database decrypt
   ```

2. **Update the bundle to v2.1+**:
   ```bash
   composer update psolutions/encrypt-bundle
   ```

3. **Re-encrypt all data with the new GCM method**:
   ```bash
   php bin/console encrypt:database encrypt
   ```

**WARNING**: If you skip the migration step, the new version will NOT be able to decrypt data encrypted with the old CBC method. The two formats are incompatible.

### About AES-256-GCM

- **Authenticated Encryption**: AES-GCM provides both encryption AND message authentication.
- **Tamper Detection**: Any modification to the encrypted data will be detected and will cause decryption to fail.
- **No Padding Oracle Vulnerabilities**: Unlike CBC mode, GCM is not vulnerable to padding oracle attacks.
- **Format**: Encrypted values are stored as `base64(IV . TAG . ciphertext) . '<ENC>'`.

Features road map:

- [x] Create a factory method to expand for different encryptors
- [x] Create a twig function to decrypt encoded values
- [x] Expand parameters to allow selection of encoding method
- [x] Create CLI commands to encrypt and decrypt the entire database
- [ ] Handle DateTime data types via the bundle.

## License

This bundle is under the MIT license. See the complete license in the bundle:

    Resources/meta/LICENSE

## About

EncryptBundle has been written for the [Parolla Plugins](https://plugins.parolla.ie) and [Parolla](https://www.parolla.ie) websites
to encode users private data. The bundle is expanded in a larger [gdpr-bundle](https://github.com/mogilvie/GdprBundle).

## Reporting an issue or a feature request

Issues and feature requests are tracked in the [Github issue tracker](https://github.com/frizquierdo/EncryptBundle/issues).

When reporting a bug, it may be a good idea to reproduce it in a basic project
built using the [Symfony Standard Edition](https://github.com/symfony/symfony-standard)
to allow developers of the bundle to reproduce the issue by simply cloning it
and following some steps.

# Installation

## Step 1: Install from package

Open a command console, enter your project directory and execute the
following command to download the latest development version of this bundle:

```
$ composer require psolutions/encrypt-bundle
```
## Step 2: Enable the bundle

Flex registers the bundle automatically. Otherwise add it to `config/bundles.php`:

```php
return [
    // ...
    PSolutions\EncryptBundle\PSolutionsEncryptBundle::class => ['all' => true],
];
```

## Step 3: Configure the bundle

> **Note:** Symfony Flex ignores recipe endpoints declared by a dependency, so `composer require` alone does
> **not** create the config file. Since v5.1 the bundle works without it: the key defaults to
> `%env(PSOLUTIONS_ENCRYPT_KEY)%`.

Run the installer once (creates `config/packages/psolutions_encrypt.yaml` and adds a generated key to `.env`;
it never overwrites unless `--force`; use `--local` to write the key to `.env.local`):

```
$ bin/console encrypt:install
```

Or do it by hand: generate a 256-bit key with `bin/console encrypt:genkey` and add it to `.env`:

```
###> psolutions/encrypt-bundle ###
PSOLUTIONS_ENCRYPT_KEY=<generated key>
###< psolutions/encrypt-bundle ###
```

Optionally, to get the official recipe applied on install, declare the endpoint in **your project's**
`composer.json`:

```json
"extra": { "symfony": { "endpoint": [
    "https://api.github.com/repos/frizquierdo/EncryptBundle/contents/recipe/index.json",
    "flex://defaults"
] } }
```

Full configuration (every option is optional):

```yaml
# config/packages/psolutions_encrypt.yaml
psolutions_encrypt:
  encrypt_key: '%env(PSOLUTIONS_ENCRYPT_KEY)%'  # default (a missing variable fails on first use)
  blind_index_key: ~  # default: derived from encrypt_key (HKDF); see "Blind indexes"
  is_disabled: false # Turn this to true to disable the encryption.
  connections:   # Optional, define the connection name(s) for the subscriber to listen to.
    - 'default'
  encryptor_class: App\Encryptors\MyCustomEncryptor # Optional to override the bundle OpenSslEncryptor.
  annotation_classes: # Optional to override the default Attribute object.
    - App\Annotation\MyAttribute
```

The bundle uses **AES-256-GCM** authenticated encryption by default. You can override the encryptor class if you need a different cipher method, but it's strongly recommended to keep GCM for security.

You can disable encryption by setting the 'is_disabled' option to true. Decryption still continues if any values
contain the \<ENC> suffix.

If you want to define your own attribute, then this can be used to trigger encryption by adding the attribute
to properties class that you want yo be encrypted.

You can pass the class name of your own encyptor service using the optional encryptorClass option.

### Alternative EncryptKeyEvent
The EncryptKey can be set via a dispatched event listener, which overrides any .env or param.yml defined key.
Create a listener for the EncryptKeyEvents::LOAD_KEY event and set your encryption key at that point.

## Step 4: Create the entities
Add the Encrypted attribute class within the entity.

```php
<?php
...
use PSolutions\EncryptBundle\Annotations\Encrypted;
```

Add the attribute #[Encrypted] to the properties you want encrypted.

```php
<?php

    #[Encrypted]
    #[Column]
    protected string $taxNumber;

    #[Column(type: string, nullable: true)]
    #[Encrypted]
    protected ?bool $isSelfEmployed;

    /**
     * Date of birth
     */
    #[Encrypted]
    #[Column]
    protected ?String $dob;

```
Where encrypting a field you will need to set the column type as string.

Your getters and setters may also need to be type declared.

For example, boolean should either be return declared bool, or return a bool using a ternary method.

```php
<?php
    /**
     * Get isSelfEmployed
     *
     * @return boolean
     */
    public function isSelfEmployed(): bool
    {
        return $this->isSelfEmployed;
    }

    /**
     * Get isSelfEmployed
     *
     * @return boolean
     */
    public function isSelfEmployed(): bool
    {
        return ($this->isSelfEmployed == 1 ? true: false);
    }

```

For DateTime parameters store the date as a string, and use the getters and setters
to convert that string.

You may also need to create a DataTransformer if you are using the parameter in a form
with the DateType form type.

## Step 5: General Use

The bundle comes with an DoctrineEncryptListener. This listener catches the doctrine events
onLoad, onFlush and postFlush.

The onLoad event listener will decrypt your entity parameter at loading. This means that your forms
and form fields will already be decrypted.

The onFlush and postFlush event listeners will check if encryption is enabled, and encrypt the data
before entry to the database.

So, in normal CRUD operation you do not need to do anything in the controller for encrypting or decrypting
the data.

## Step 6: Decrypt in services and controllers

You can of course inject the EncryptorInterface service any time into classes
either by using autowiring or defining the injection in your service definitions.

```php
<?php
    use PSolutions\EncryptBundle\Encryptors\EncryptorInterface;

    // Inject the Encryptor from the service container at class construction
    public function __construct(private readonly EncryptorInterface $encryptor)
    {

    }

    // Inject the Encryptor in controller actions.
    public function editAction(EncryptorInterface $encryptor)
    {
        ...
        // An example encrypted value, you would get this from your database query.
        $encryptedValue = "3DDOXwqZAEEDPJDK8/LI4wDsftqaNCN2kkyt8+QWr8E=<ENC>";

        $decrypted = $encryptor->decrypt($encryptedValue);
        ...
    }


```

Or you can dispatch the EncryptEvent.

```php
<?php
    ...
    use PSolutions\EncryptBundle\Event\EncryptEvent;
    use PSolutions\EncryptBundle\Event\EncryptEvents;
    use Symfony\Component\EventDispatcher\EventDispatcherInterface;
    ...

    public function indexAction(EventDispatcherInterface $dispatcher)
    {
        ...
        // An example encrypted value, you would get this from your database query.
        $event = new EncryptEvent("3DDOXwqZAEEDPJDK8/LI4wDsftqaNCN2kkyt8+QWr8E=<ENC>");

        $dispatcher->dispatch(EncryptEvents::DECRYPT, $event);

        $decrypted = $event->getValue();
    }
```

## Step 7: Decrypt in templates

If you query a repository using a select with an array result
then the doctrine onLoad event subscriber will not decrypt any encrypted
values.

In this case, use the twig filter to decrypt your value when rendering.

```
{{ employee.bankAccountNumber | decrypt }}
```

# Security Features

## Authenticated Encryption (AES-256-GCM)

This bundle uses **AES-256-GCM** (Galois/Counter Mode), which provides:

- **Confidentiality**: Data is encrypted and cannot be read without the key
- **Authenticity**: Each ciphertext includes an authentication tag (16 bytes) that verifies it was created with the correct key
- **Integrity**: Any modification to the encrypted data (bit flips, insertions, deletions) will be detected during decryption and will cause an `EncryptException`
- **Protection against bit-flipping attacks**: Unlike CBC mode, GCM prevents attackers from modifying ciphertext in a controlled manner
- **No padding oracle vulnerabilities**: GCM is a stream cipher mode that doesn't use padding

## Key Requirements

- The encryption key **must be exactly 256 bits (32 bytes)** after base64 decoding
- Keys are stored base64-encoded in your `.env` or secrets vault
- Generate a proper key with: `bin/console encrypt:genkey`

## Data Format

Encrypted values in the database have the following structure:

```
[base64( IV(12) + TAG(16) + ciphertext )] + '<ENC>'
```

- **IV** (Initialization Vector): 12 random bytes, unique for each encryption
- **TAG** (Authentication Tag): 16 bytes, computed from ciphertext + key
- **ciphertext**: The actual encrypted data
- **'<ENC>'**: Suffix marker to identify encrypted values

All components are necessary; removing or altering any part will cause decryption to fail.

# Blind indexes (searchable encrypted fields)

Encrypted values use a random IV, so they cannot be queried with `WHERE` nor have a `UNIQUE` index. A blind index
stores, next to the encrypted field, a deterministic, non-reversible `HMAC-SHA256` of the normalized plain value:

```php
use PSolutions\EncryptBundle\Attributes\BlindIndex;
use PSolutions\EncryptBundle\Attributes\Encrypted;

#[Encrypted]
#[ORM\Column(length: 255, nullable: true)]
private ?string $email = null;

#[BlindIndex(source: 'email', normalizer: BlindIndex::LOWER_TRIM)]
#[ORM\Column(length: 64, nullable: true, unique: true)]
private ?string $emailHash = null;
```

The listener computes it on flush, before encrypting. Query it through the `BlindIndexer` service:

```php
$user = $repo->findOneBy([
    'emailHash' => $blindIndexer->hash(User::class, 'emailHash', $email),
]);
```

- Exact match only (no `LIKE`, ranges or sorting).
- An empty source gives a `null` index. The index property must not be `#[Encrypted]`.
- Normalizers: `BlindIndex::NONE` (default), `BlindIndex::LOWER_TRIM` or your own
  `BlindIndexNormalizerInterface` service.
- Trade-off: rows with the same value share the same index, so equality between rows is revealed.
- Changing `blind_index_key` (or `encrypt_key` when the index key is derived) invalidates every index: rebuild it.
- Use a dedicated key if you plan to rotate `encrypt_key`, so both keys rotate independently:

```
$ bin/console encrypt:install --blind-index-key   # adds PSOLUTIONS_BLIND_INDEX_KEY to .env and the yaml option
$ bin/console encrypt:genkey --blind-index        # or generate one by hand
```

```yaml
psolutions_encrypt:
  blind_index_key: '%env(PSOLUTIONS_BLIND_INDEX_KEY)%'
```

# Key rotation

Rotate keys with a single command: it never leaves data undecryptable or indexes stale.

```
$ bin/console encrypt:rotate-key --write-env [--encrypt-key=NEW] [--blind-index-key=NEW] [--dry-run]
```

- Without values, new keys are generated. With a derived blind index key, rotating `encrypt_key` also rotates it.
  With a dedicated one, `--blind-index-key` alone only rebuilds indexes and `--encrypt-key` alone only re-encrypts.
- Per connection, in one transaction: decrypt with the current key, encrypt with the new one, recompute indexes,
  then verify everything decrypts and no unique index collides. Any failure rolls back, leaving the database untouched.
- `--write-env` stores the new keys in `.env` (or the given file). Then clear the cache and restart your workers.
- `--dry-run` runs everything and rolls back, reporting rows, collisions and errors.
- `--connection` names Doctrine entity managers (default: the `connections` option). The key is shared, so rotate
  every connection that holds encrypted data in the same run: they are committed together after verification.
- Not available when the key comes from an `EncryptKeyEvents::LOAD_KEY` listener.
- Take a database backup first.

`encrypt:genkey` only prints a key; it does not apply it. `encrypt:database encrypt` also rebuilds blind indexes
(`--no-blind-index` to skip).

# Commands

You have already seen the command to generate a encryption key (`--blind-index` generates a blind index key):
```
$ bin/console encrypt:genkey [--blind-index]
```

You can decrypt/encrypt the entire database using the following
```
$ bin/console encrypt:database decrypt connection
```

The requried argument should be be decrypt or encrypt.

There is an option to define the database connection if you employ multiple connections in your application.

Create the config file and the key (see Installation):
```
$ bin/console encrypt:install [--force] [--local] [--blind-index-key]
```

Rebuild blind indexes for existing rows or after a key rotation. With `--dry-run` it only reports; without it, it
refuses to write anything if two rows collide on a unique index and lists them:
```
$ bin/console encrypt:blind-index:rebuild [--connection=default] [--entity=FQCN] [--batch-size=200] [--dry-run]
```

# Migration from Previous Versions

## From v2.0.x or earlier (CBC mode) to v2.1+ (GCM mode)

**This is a mandatory migration if you have existing encrypted data.**

The encryption format changed from CBC to GCM, making them incompatible. Follow these steps exactly:

### Step 1: Decrypt all existing data

Using your **current codebase** (before updating the bundle), run:

```bash
php bin/console encrypt:database decrypt
```

This will convert all encrypted fields to plain text in the database.

⚠️ **Verify** that the data is now unencrypted (check your database directly).

### Step 2: Update the bundle

```bash
composer update psolutions/encrypt-bundle
```

Or update your `composer.json` and run `composer install`.

### Step 3: Re-encrypt with the new GCM method

With the updated bundle installed, run:

```bash
php bin/console encrypt:database encrypt
```

This will re-encrypt all plaintext fields using the new AES-256-GCM algorithm.

### Step 4: Verify the migration

Check a few records in your database. Encrypted values should now:
- End with `<ENC>` suffix
- Be longer than before (due to IV + TAG added)
- Be base64-encoded strings

Also test your application workflows to ensure encryption/decryption works correctly.

## Rollback Procedure

If something goes wrong after migration:

1. Keep a backup of the database before starting migration
2. If you need to roll back to the old version, you must first run `encrypt:database decrypt` with the new version, then reinstall the old version and run `encrypt:database encrypt` with it

---

⚠️ **Never skip the decryption step** before updating, or you will **permanently lose access** to data encrypted with the old CBC format.

# Custom Encryptors

You can create your own encryptor by implementing `EncryptorInterface`:

```php
use PSolutions\EncryptBundle\Encryptors\EncryptorInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class MyCustomEncryptor implements EncryptorInterface
{
    private EventDispatcherInterface $dispatcher;
    private string $secretKey;

    public function __construct(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function setSecretKey(string $key): void
    {
        $this->secretKey = $key;
    }

    public function encrypt(?string $data): ?string
    {
        // Your custom encryption logic
        // Must return null for null input
        // Must check for '<ENC>' suffix and return if present
    }

    public function decrypt(?string $data): ?string
    {
        // Your custom decryption logic
        // Must return null for null input
        // Must check for '<ENC>' suffix and return if not present
    }
}
```

Then configure it:

```yaml
psolutions_encrypt:
  encryptor_class: App\Encryptors\MyCustomEncryptor
```

⚠️ **Security Warning**: If you implement your own encryptor, you must ensure it provides proper authentication (like GCM's tag) to prevent tampering. Avoid CBC mode without HMAC.

## Reference Implementation

See `src/Encryptors/OpenSslEncryptor.php` for the reference implementation using AES-256-GCM. It demonstrates:
- Proper IV generation (12 random bytes)
- Authentication tag handling (16 bytes)
- Base64 encoding of combined binary data
- Key validation (32 bytes after decoding)
- Error handling that doesn't leak information

---

For questions or issues, please open an issue on [GitHub](https://github.com/frizquierdo/EncryptBundle/issues).

