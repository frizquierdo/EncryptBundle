# Migration Guide: v2.1.0 - AES-256-GCM Upgrade

## Overview

**Version**: v2.1.0 (or later)
**Security Level**: Critical
**Type**: Breaking Change - Encryption Algorithm Migration
**Estimated Downtime**: Depends on database size (plan accordingly)

This guide walks you through migrating from **AES-256-CBC** (pre-v2.1) to **AES-256-GCM** (v2.1+). The two formats are **completely incompatible**.

---

## Table of Contents

1. [Why This Change?](#why-this-change)
2. [Before You Begin](#before-you-begin)
3. [Prerequisites](#prerequisites)
4. [Migration Procedures](#migration-procedures)
   - [Option A: Standard Migration](#option-a-standard-migration)
   - [Option B: Live Migration (Zero Downtime)](#option-b-live-migration-zero-downtime)
5. [Verification](#verification)
6. [Troubleshooting](#troubleshooting)
7. [Rollback](#rollback)
8. [Post-Migration Checklist](#post-migration-checklist)

---

## Why This Change?

### Security Improvements

| Aspect | CBC (Old) | GCM (New) |
|--------|-----------|-----------|
| **Authentication** | ❌ None | ✅ Built-in (16-byte tag) |
| **Tamper Detection** | ❌ Vulnerable | ✅ Detects modifications |
| **Padding Oracle** | ⚠️ Vulnerable | ✅ Immune |
| **IV Length** | 16 bytes | 12 bytes (optimal) |
| **Security Level** | ~128-bit | ~256-bit with integrity |

### What's at Risk if You Don't Migrate?

- **Bit-flipping attacks**: Attacker can modify ciphertext and cause predictable changes in plaintext
- **Padding oracle attacks**: Can leak information about plaintext through error messages
- **No integrity protection**: Corrupted data goes undetected

---

## Before You Begin

### ⚠️ Critical Warnings

1. **DATA LOSS RISK**: Skipping Step 1 (decrypt old data) will **permanently lose access** to all previously encrypted records.
2. **BACKUP REQUIRED**: Take a **full database backup** before starting.
3. **TEST FIRST**: Practice the migration on a staging environment that mirrors production.
4. **NO ROLLBACK WITHOUT DECRYPT**: If you upgrade without decrypting first, you cannot roll back to the old version.

### What Gets Encrypted?

Identify all entities with `#[Encrypted]` attributes:

```bash
# Find all entities with Encrypted attribute
find src/Entity -name "*.php" -exec grep -l "#\[Encrypted\]" {} \;
```

Or scan the database:

```sql
-- Check for <ENC> suffix in columns
SELECT table_name, column_name
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND data_type LIKE '%char%';
-- Then manually verify which columns contain values ending with '<ENC>'
```

---

## Prerequisites

### 1. Environment Requirements

- PHP 8.2 or higher
- OpenSSL extension with AES-GCM support (standard in most PHP installations)
- Symfony CLI (`bin/console`)
- Database access with read/write permissions
- Sufficient disk space for temporary plaintext storage (during migration window)

### 2. Verify Your Current Setup

```bash
# Check OpenSSL ciphers available (must include aes-256-gcm)
php -r "print_r(openssl_get_cipher_methods());" | grep 'aes-256-gcm'

# Should output: aes-256-gcm
```

If `aes-256-gcm` is not listed, you need to upgrade OpenSSL. This is a server-level requirement.

### 3. Generate a New Key (Optional but Recommended)

If you want to rotate your encryption key during migration (recommended):

```bash
# Generate a new 256-bit key
php bin/console encrypt:genkey

# Output example:
# Generated Key
# Key is: X5Yh7s9KpQ2wE4zR6tBvN8qWc3yZaA+BbCcDdEeFfGg=
```

**Save this key** – you'll need it after updating the bundle.

### 4. Prepare Your .env File

```bash
# Ensure your key is set (use the EXISTING key first, then new key after upgrade)
cat .env | grep PSOLUTIONS_ENCRYPT_KEY

# If using Symfony secrets (preferred):
# The key is stored in vault, not .env
```

---

## Migration Procedures

### Option A: Standard Migration (Scheduled Downtime)

**Best for**: Most applications, simpler process, single maintenance window.

#### Timeline Estimate

| DB Size | Decrypt Time | Upgrade | Re-encrypt | Total Downtime |
|---------|-------------|---------|------------|----------------|
| < 10k rows | 2-5 min | 10 min | 2-5 min | ~20 min |
| 10k-100k rows | 5-15 min | 10 min | 5-15 min | ~35 min |
| 100k-1M rows | 15-30 min | 10 min | 15-30 min | ~60 min |
| 1M+ rows | 30-60+ min | 10 min | 30-60+ min | 100+ min |

#### Step-by-Step

##### **Step 1: Prepare - Decrypt Everything (OLD version)**

**IMPORTANT**: Use the codebase **BEFORE updating the bundle**.

```bash
# Make sure you're on the old version (v2.0.x or earlier)
composer show psolutions/encrypt-bundle
# Should show version < 2.1.0

# Run decrypt command
php bin/console encrypt:database decrypt

# Output:
# "X tables to decrypt"
# [============================] 100%

# Monitor the command - it should complete without errors.
```

**What this does**:
- Reads each entity with `#[Encrypted]` fields
- Decrypts the values using AES-256-CBC
- Writes plaintext back to the database
- Removes the `<ENC>` suffix

**Verification**:
```sql
-- Check a sample record
SELECT id, your_encrypted_column
FROM your_table
WHERE id = 1;

-- Should see plain text (no <ENC> suffix, not base64-encoded)
```

🔒 **Security Note**: During this window, data is **stored in plaintext**. Ensure:
- No unauthorized DB access
- Encrypt backups if taken
- Complete migration quickly

##### **Step 2: Deploy New Code**

```bash
# Update the bundle
composer update psolutions/encrypt-bundle

# Or if using composer.json lock:
composer install

# Verify version
composer show psolutions/encrypt-bundle | grep versions
# Should show 2.1.0 or later
```

**Files changed**:
- `vendor/psolutions/encrypt-bundle/src/Encryptors/OpenSslEncryptor.php` (now AES-256-GCM)
- Your own code remains unchanged (backward compatible API)

##### **Step 3: Re-encrypt Everything (NEW version)**

```bash
# With the new bundle installed, re-encrypt all plaintext data
php bin/console encrypt:database encrypt

# Output:
# "X tables to encrypt"
# [============================] 100%
```

**What this does**:
- Reads plaintext values from the database
- Encrypts using AES-256-GCM (with IV + TAG)
- Writes ciphertext (base64 + `<ENC>` suffix) back

##### **Step 4: Verify Application**

1. **Check a sample record**:
   ```sql
   SELECT id, your_encrypted_column
   FROM your_table
   WHERE id = 1;
   
   -- Should see: long base64 string ending with <ENC>
   -- Example: "X5Yh7s9KpQ2wE4zR6tBvN8qWc3yZaA+<ENC>"
   ```

2. **Test through your application**:
   - Load an entity with encrypted fields
   - Verify getters return decrypted values
   - Create/update an entity
   - Verify data is saved encrypted in DB

3. **Check logs**:
   ```bash
   tail -f var/log/prod.log | grep -i encrypt
   # Should see no errors
   ```

##### **Step 5: Monitor**

Deploy to production during low-traffic period. Monitor for:
- Database performance (encryption adds ~1-2ms per field)
- Error rates in application logs
- Any `EncryptException` thrown

---

### Option B: Live Migration (Zero Downtime for Reads)

**Best for**: High-availability systems, large databases, read-heavy apps.

This approach keeps the application running while migrating in the background.

#### Overview

1. Keep old version running (CBC)
2. Create new columns for each encrypted field (`*_enc_v2`)
3. Background job: read → decrypt (CBC) → encrypt (GCM) → write to new column
4. Deploy code to read from new column
5. Switch writes to new column
6. Cleanup old columns

#### Detailed Steps

##### **Phase 1: Dual-Write Setup (Deploy v2.0.x + schema changes)**

```sql
-- For each encrypted field, add a new column
ALTER TABLE user
  ADD COLUMN ssn_enc_v2 VARCHAR(255) NULL,
  ADD COLUMN passport_enc_v2 VARCHAR(255) NULL;

-- Add index if needed
CREATE INDEX idx_user_ssn_enc_v2 ON user(ssn_enc_v2);
```

Update your entity:

```php
#[ORM\Column(type: 'string', nullable: true)]
#[Encrypted]
private ?string $ssn = null;

// New v2 field (not marked as Encrypted in entity, it's raw)
#[ORM\Column(name: 'ssn_enc_v2', type: 'string', nullable: true)]
private ?string $ssnV2 = null;

// Maintain both in lifecycle callbacks
public function setSsn(string $ssn): self
{
    $this->ssn = $ssn; // Old CBC column
    $this->ssnV2 = (new OpenSslEncryptor(...))->encrypt($ssn); // New GCM column
    return $this;
}
```

Deploy this code – writes to both columns.

##### **Phase 2: Background Migration**

Create a command:

```php
// src/Command/MigrateEncryptionCommand.php
#[AsCommand(name: 'encrypt:migrate-gcm')]
class MigrateEncryptionCommand extends Command
{
    public function __construct(
        private EncryptorInterface $oldEncryptor, // CBC implementation
        private EncryptorInterface $newEncryptor, // GCM implementation
        private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Migrating Encryption: CBC → GCM');

        $users = $this->em->createQuery('SELECT u FROM App\Entity\User u WHERE u.ssnV2 IS NULL')
            ->toIterable();

        $count = 0;
        foreach ($users as $user) {
            if ($user->getSsn()) {
                // Decrypt old CBC value, encrypt with GCM, save to new column
                $decrypted = $this->oldEncryptor->decrypt($user->getSsn());
                $user->setSsnV2($this->newEncryptor->encrypt($decrypted));
                $this->em->flush();
                $count++;
            }
        }

        $io->success("Migrated $count records");
        return Command::SUCCESS;
    }
}
```

Run it:

```bash
# Run in background with low priority
php bin/console encrypt:migrate-gcm --limit=1000
# Or run as a daemon/cron every minute until complete
```

##### **Phase 3: Switch Reads**

Update getters:

```php
public function getSsn(): ?string
{
    // Prefer new GCM column if populated, fallback to old
    if ($this->ssnV2) {
        return $this->newEncryptor->decrypt($this->ssnV2);
    }
    return $this->oldEncryptor->decrypt($this->ssn);
}
```

Deploy. Now reads use GCM data.

##### **Phase 4: Cutover Writes**

After migration complete:

1. Update setters to write only to `ssn_enc_v2`
2. Remove writes to old `ssn` column
3. Deploy

##### **Phase 5: Cleanup (Optional)**

After monitoring (1-2 weeks):

```sql
ALTER TABLE user
  DROP COLUMN ssn_enc,
  DROP COLUMN ssn_enc_v2;
```

---

## Verification

### Automated Checks

Run these checks after migration:

```bash
# 1. Check all encrypted columns have <ENC> suffix
php bin/console doctrine:query:sql "
  SELECT COUNT(*) as total
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND column_name LIKE '%_enc%'
" # (adjust for your column naming)

# 2. Sample decrypt/encrypt cycle
php -r "
  require 'vendor/autoload.php';
  use PSolutions\EncryptBundle\Encryptors\EncryptorInterface;
  // ... inject service and test
"

# 3. Check application forms render correctly
# Visit a form page with encrypted fields in browser
# Values should appear plain (decrypted by Doctrine listener)
```

### Database-Level Validation

```sql
-- Verify all encrypted values end with <ENC> (case-sensitive)
SELECT COUNT(*) as not_encrypted
FROM user
WHERE ssn IS NOT NULL
  AND ssn NOT LIKE '%<ENC>';
-- Should return 0

-- Verify base64 encoding
SELECT COUNT(*) as invalid_base64
FROM user
WHERE sn IS NOT NULL
  AND ssn LIKE '%<ENC>'
  AND base64_decode(substring(ssn, 1, char_length(ssn) - 5)) IS NULL;
-- Should return 0
```

### Application-Level Smoke Tests

Create a test script:

```php
// tests/Migration/GcmMigrationTest.php
class GcmMigrationTest extends TestCase
{
    public function testAllEncryptedFieldsAreReadable(): void
    {
        $user = $this->entityManager->find(User::class, 1);
        $this->assertNotNull($user->getSsn(), 'SSN should not be null');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]+$/', $user->getSsn());
        $this->assertTrue($user->isSsnValid()); // Your business logic
    }

    public function testEncryptionRoundTrip(): void
    {
        $encryptor = self::getContainer()->get(EncryptorInterface::class);
        $original = '123-45-6789';
        $encrypted = $encryptor->encrypt($original);
        $decrypted = $encryptor->decrypt($encrypted);
        $this->assertSame($original, $decrypted);
    }
}
```

Run:
```bash
php bin/phpunit tests/Migration/GcmMigrationTest.php
```

---

## Troubleshooting

### Error: "Decryption failed: data appears to be encrypted with the old CBC format"

**Cause**: You're trying to decrypt CBC-encrypted data with GCM code. The decryption detected that the data length is too short to be valid GCM.

**Solution**: You skipped Step 1. Roll back to old version, decrypt, then upgrade.

```bash
# If you already deployed v2.1 without decrypting:
# 1. Downgrade immediately
composer require psolutions/encrypt-bundle:^2.0

# 2. Decrypt all data
php bin/console encrypt:database decrypt

# 3. Upgrade again
composer update psolutions/encrypt-bundle

# 4. Re-encrypt
php bin/console encrypt:database encrypt
```

### Error: "The bundle requires an encryption key"

**Cause**: `PSOLUTIONS_ENCRYPT_KEY` not set in environment.

**Solution**:
```bash
# Generate a key
php bin/console encrypt:genkey

# Set in .env
echo "PSOLUTIONS_ENCRYPT_KEY=your_generated_key" >> .env

# Clear cache
php bin/console cache:clear
```

### Error: "Encryption key must be 256 bits (32 bytes)"

**Cause**: Key length invalid.

**Solution**:
```bash
# Check your key length
php -r "echo strlen(base64_decode('YOUR_KEY_HERE'));"

# Should output: 32

# If not, generate new key and update .env
php bin/console encrypt:genkey
```

### Command Hangs or Times Out

**Cause**: Large table, not enough PHP memory/time.

**Solution**: Process in batches manually.

```bash
# Edit EncryptDatabaseCommand to add --limit and --offset options
# Or process table by table:

# Get list of tables with encrypted fields
php bin/console encrypt:database encrypt --manager=default --table=User
php bin/console encrypt:database encrypt --manager=default --table=Customer
# ... etc
```

Or increase PHP limits temporarily:

```bash
php -d memory_limit=512M -d max_execution_time=0 bin/console encrypt:database encrypt
```

### "Access denied" on database command

**Cause**: Database user lacks UPDATE permission.

**Solution**: Grant UPDATE on all tables to the DB user:

```sql
GRANT UPDATE ON your_database.* TO 'your_user'@'localhost';
FLUSH PRIVILEGES;
```

### OpenSSL extension not loaded

**Cause**: PHP OpenSSL extension missing.

**Solution**:
```bash
# Ubuntu/Debian
sudo apt-get install php-openssl
sudo service php8.2-fpm restart  # or apache2

# Verify
php -m | grep openssl
# Should output: openssl
```

---

## Rollback

### Scenario: You upgraded to v2.1+ and now need to revert to v2.0.x

**Prerequisite**: You **MUST** have run `encrypt:database decrypt` with v2.1+ before downgrading. If you didn't, data is permanently lost.

#### Step 1: Decrypt with NEW version (v2.1+)

```bash
# With v2.1+ still installed
php bin/console encrypt:database decrypt

# This converts GCM ciphertext → plaintext
# (It's safe – it will just fail on old CBC data if any remains)
```

Verify plaintext in DB.

#### Step 2: Downgrade bundle

```bash
composer require psolutions/encrypt-bundle:^2.0
# Or specify exact version:
composer require psolutions/encrypt-bundle:2.0.x
```

#### Step 3: Re-encrypt with OLD version

```bash
php bin/console encrypt:database encrypt

# Now data is back in CBC format (v2.0 compatible)
```

#### Step 4: Verify old app works

Test your application thoroughly.

---

## Post-Migration Checklist

### Security Checklist

- [ ] Verify `<ENC>` suffix appears on all encrypted fields in DB
- [ ] Confirm ciphertext length increased by ~28 bytes (IV 12 + TAG 16)
- [ ] Test decryption of old backup files (should fail with helpful error)
- [ ] Rotate encryption key if desired (requires another full migration)
- [ ] Review database access logs for any异常 during migration window
- [ ] Ensure backups are now encrypted at rest (if using encrypted backups)

### Operational Checklist

- [ ] Application reads/writes work correctly
- [ ] Forms display decrypted values
- [ ] Search functionality (if searching encrypted fields) – note: GCM doesn't change this
- [ ] API endpoints returning encrypted data still work
- [ ] Twig `|decrypt` filter works
- [ ] CLI commands that reference encrypted fields work
- [ ] Database replication (if any) synced post-migration
- [ ] Clear cache: `php bin/console cache:clear`
- [ ] Warm up cache: `php bin/console cache:warmup`

### Documentation & Communication

- [ ] Update internal runbooks with new migration procedure
- [ ] Notify all developers of breaking change
- [ ] Update Docker images/CI/CD pipelines if key management changed
- [ ] Schedule key rotation (if planning) – separate process

---

## Frequently Asked Questions (FAQ)

### Q: Can I skip the decrypt step if I don't have any data yet?

**A**: Yes! If your database is empty or all data is test/dev data you can wipe and start fresh:

```bash
# Option 1: Fresh database
php bin/console doctrine:database:drop --force
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

# Then just deploy v2.1+ and start using it
```

### Q: My database is huge (10M+ rows). How do I migrate without downtime?

**A**: Use **Option B: Live Migration** above. It takes longer but avoids downtime.

### Q: Can I just change the cipher method in config without migrating data?

**A**: No. CBC and GCM ciphertext formats are incompatible. Old data will be unreadable.

### Q: Will this affect performance?

**A**: GCM is actually **faster** than CBC in most cases (especially with 12-byte IV). Expect similar or slightly better performance. The additional 16-byte tag increases storage by ~16%.

### Q: What about existing API consumers?

**A**: If your API returns decrypted values (application layer), no change. If you expose encrypted values (rare), those values will still be CBC-format until re-encrypted. Document that all encrypted values after v2.1 are GCM format.

### Q: Can I use the same encryption key?

**A**: Yes. The key derivation remains the same (256-bit key). You can keep your current key. However, best practice is to rotate the key during migration.

### Q: How do I know migration is complete?

**A**: 
1. `encrypt:database encrypt` completes successfully
2. All rows in encrypted columns end with `<ENC>`
3. Application works normally (no decryption errors)
4. No CBC-formatted data remains in the database

### Q: What if the migration command fails halfway?

**A**: 
1. Check error logs
2. Fix the issue (e.g., increase PHP memory)
3. Re-run `encrypt:database encrypt` – it's idempotent and will skip already-GCM rows
   (Because they have `<ENC>` suffix and decrypt successfully)

---

## Support

If you encounter issues not covered here:

1. Check the logs: `var/log/prod.log` or `var/log/dev.log`
2. Search existing [GitHub issues](https://github.com/frizquierdo/EncryptBundle/issues)
3. Open a new issue with:
   - Bundle version
   - PHP version
   - Database type & size
   - Steps to reproduce
   - Error messages (sanitized)

---

## Appendix: Technical Details

### Format Specifications

#### CBC Format (pre-v2.1)

```
base64encode( IV(16 bytes) + ciphertext(pkcs7 padded) ) + '<ENC>'
Total size: ~ (16 + len(plaintext) + padding) * 4/3 + 5
```

#### GCM Format (v2.1+)

```
base64encode( IV(12 bytes) + TAG(16 bytes) + ciphertext(no padding) ) + '<ENC>'
Total size: ~ (12 + 16 + len(plaintext)) * 4/3 + 5
```

### Cipher Comparison

| Property | AES-256-CBC | AES-256-GCM |
|----------|-------------|-------------|
| Mode | Cipher Block Chaining | Galois/Counter Mode |
| IV Size | 16 bytes | 12 bytes (optimal) |
| Authentication | None (external HMAC needed) | Built-in (16-byte tag) |
| Padding | PKCS7 required | No padding (stream-like) |
| Integrity Check | ❌ | ✅ |
| Oracle Resistance | ❌ Vulnerable | ✅ Resistant |
| NIST Recommendation | ❌ Deprecated for new designs | ✅ Recommended |

---

**Document Version**: 1.0  
**Last Updated**: 2026-05-10  
**Applies To**: psolutions/encrypt-bundle v2.1.0+
