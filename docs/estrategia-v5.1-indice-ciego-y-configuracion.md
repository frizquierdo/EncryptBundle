# Estrategia v5.1: índice ciego y configuración sin receta

Versión objetivo: **5.1.0** (menor, retrocompatible: no cambia el formato cifrado ni la API existente).

## 1. Problema

1. **Búsqueda sobre campos cifrados.** `#[Encrypted]` usa AES-256-GCM con IV aleatorio: el mismo valor produce
   un texto cifrado distinto cada vez. Un campo cifrado no admite `WHERE campo = ?` ni índice `UNIQUE`.
   Caso real: buscar una cuenta por email cifrado (formulario «olvidé mi contraseña») y exigir email único.
2. **La configuración no se instala.** `composer require psolutions/encrypt-bundle` no crea
   `config/packages/psolutions_encrypt.yaml` ni la variable `PSOLUTIONS_ENCRYPT_KEY`:
   - Flex **ignora** `extra.symfony.recipes` del `composer.json` de una dependencia; solo lee los endpoints que
     declara el `composer.json` raíz del proyecto (`extra.symfony.endpoint`). La receta de `recipe/` nunca se aplica.
   - `encrypt_key` no tiene valor por defecto: sin el archivo, el bundle arranca sin clave.

## 2. Índice ciego (blind index)

Junto al campo cifrado se guarda una huella determinista **no reversible**: `HMAC-SHA256(normalizar(valor), clave)`.
Esa columna sí admite igualdad y unicidad. Solo sirve para coincidencia exacta (no `LIKE`, rangos ni orden).

### 2.1 API

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

- `source`: propiedad origen de la misma entidad (normalmente cifrada, aunque no es obligatorio).
- `normalizer`: `BlindIndex::NONE` (por defecto) | `BlindIndex::LOWER_TRIM` | FQCN de una clase que implemente
  `BlindIndexNormalizerInterface` (servicio).
- Origen `null` o vacío tras normalizar → huella `null` (no choca con el índice único).
- La propiedad de la huella **no** lleva `#[Encrypted]` (se valida y se lanza `EncryptException` si la lleva).

Servicio para consultas: `BlindIndexer::hash(string $entityClass, string $property, ?string $value): ?string`
(aplica el normalizador declarado en esa propiedad). Uso:

```php
$repo->findOneBy(['emailHash' => $indexer->hash(Usuario::class, 'emailHash', $email)]);
```

### 2.2 Cálculo

- En `DoctrineEncryptListener::onFlush`, **antes** de cifrar, para cada entidad insertada o actualizada: se recalculan
  sus huellas a partir del valor en claro y, si cambian, se fija el valor y se llama a
  `recomputeSingleEntityChangeSet()`. Así una sola escritura deja coherentes el campo cifrado y su huella,
  sin listeners en la aplicación.
- Metadatos (qué propiedades son huella, origen, normalizador) por reflexión sobre los metadatos de Doctrine,
  igual que `getEncryptedFields()`, y cacheados por clase.
- Respeta `is_disabled`: con el cifrado desactivado las huellas **se siguen calculando** (no dependen del cifrado).

### 2.3 Clave

- Opción nueva `blind_index_key`. Si no se define, se **deriva** de `encrypt_key` con
  `hash_hkdf('sha256', $encryptKey, 32, 'psolutions-encrypt-bundle:blind-index')`: nunca la misma clave para cifrar
  y para huellas.
- Cambiar la clave invalida todas las huellas → hay que reconstruirlas (§2.4).
- **Clave propia recomendada** cuando se prevea rotar `encrypt_key`: con la derivada, rotar la clave de cifrado
  obliga también a reconstruir las huellas; con una propia, cada clave rota por separado.
  - `encrypt:genkey --blind-index`: genera una clave de 256 bits para huellas (reutiliza el comando existente).
  - `encrypt:install --blind-index-key`: añade `PSOLUTIONS_BLIND_INDEX_KEY` al `.env` y escribe
    `blind_index_key: '%env(PSOLUTIONS_BLIND_INDEX_KEY)%'` en el archivo de configuración.
- La huella revela igualdad entre filas (dos filas con el mismo email tienen la misma huella). Es la contrapartida
  aceptada; se documenta en la ayuda.

### 2.4 Comando

`bin/console encrypt:blind-index:rebuild [--connection=default ...] [--entity=FQCN] [--batch-size=200] [--dry-run]`

- `--connection` recibe **nombres de entity manager** de Doctrine (no de conexión DBAL) y es repetible. Por defecto,
  los de la opción `connections` del bundle.
- Recorre por lotes las entidades con `#[BlindIndex]`, descifra, recalcula y escribe solo las que cambian.
  `--entity` incluye también las subclases.
- Detecta **colisiones** (misma huella en una columna única) antes de escribir y las lista
  (entidad, ids) sin abortar a medias: con `--dry-run` solo informa; sin él, falla si hay colisiones y no escribe nada.
- Uso: altas de índice sobre datos existentes y rotación de `blind_index_key`.
- Una columna se considera única por `unique: true` en el mapeo o por un `UniqueConstraint`/índice único de una sola
  columna. Con herencia JOINED, la huella y su origen deben estar en la misma tabla (si no, `EncryptException`).

Motor común (`Maintenance\DatabaseRewriter`, sobre DBAL y sin ciclo de vida de entidades), usado por `rebuild`,
`rotate-key` y el paso de huellas de `encrypt:database`:

1. **Escaneo** de solo lectura: descifra con la clave actual, calcula las huellas nuevas y detecta errores y
   colisiones. Si hay alguno, termina sin escribir nada.
2. **Escritura** en una transacción por entity manager, abiertas todas a la vez.
3. **Verificación** dentro de las mismas transacciones: todo lo escrito se descifra con la clave destino y da el
   mismo valor en claro (huella SHA-256 por fila), y cada huella coincide. Luego commit de todas o rollback de todas
   (también con `--dry-run`).

### 2.5 Rotación de claves en un solo paso

Principio: **cada operación que cambia una clave deja los datos y las huellas coherentes en el mismo paso**. No se
encadenan comandos a mano: un paso olvidado deja datos indescifrables o huellas que no encuentran nada.

`bin/console encrypt:rotate-key [--encrypt-key=NEW] [--blind-index-key=NEW] [--connection=default] [--batch-size=200] [--dry-run] [--write-env[=.env.local]]`

- Una opción sin valor (`--encrypt-key`, `--blind-index-key`) genera la clave nueva; con valor, usa ese.
- **Sin ninguna opción, rota todas las claves en uso**: `encrypt_key` y, si es propia, `blind_index_key`. Con la
  clave de huella derivada, rotar `encrypt_key` rota también la de huella (es la misma operación) y
  `--blind-index-key` falla remitiendo a `encrypt:install --blind-index-key`. Con la clave propia, cada opción rota
  solo la suya:
  - solo `--blind-index-key` → reconstruye huellas; no toca los datos cifrados;
  - solo `--encrypt-key` → vuelve a cifrar; las huellas no cambian.
- Usa el motor común (§2.4): escaneo, escritura y verificación, con **todas las conexiones en transacciones abiertas
  a la vez** y confirmadas juntas tras verificar. La clave es compartida: una conexión rotada y otra no dejaría datos
  indescifrables. Cualquier fallo → rollback de todas y la BD queda exactamente como estaba.
- El cifrador nuevo se crea con un dispatcher propio, para que ningún listener de `LOAD_KEY` sustituya la clave
  nueva. Si la aplicación da la clave mediante un listener de `EncryptKeyEvents::LOAD_KEY`, el comando se niega a
  rotar.
- Tras el commit, escribe las claves nuevas en el archivo de entorno (`--write-env`, por defecto `.env`) o las muestra
  con la orden de ponerlas; hasta que se pongan, la aplicación no descifra. Por eso `--write-env` es el uso
  recomendado y el comando avisa de vaciar la caché (`cache:clear`) y reiniciar los workers.
  - Escribe la variable en el archivo indicado **y en cada uno de** `.env`, `.env.local`, `.env.<env>` y
    `.env.<env>.local` que ya la defina, para que un archivo de mayor precedencia no conserve la clave antigua.
  - Si la variable es una variable de entorno real (no viene de Dotenv según `SYMFONY_DOTENV_VARS`), avisa de
    actualizarla allí.
  - Con `--write-env` no imprime las claves; si falla la escritura, las imprime y devuelve error.
- `--dry-run`: hace todo en la transacción y la revierte; informa de filas, colisiones y errores.
- Exige confirmación interactiva (o `--no-interaction`) y recomienda una copia de seguridad previa.

Integración en los comandos existentes:

- `encrypt:database encrypt` reconstruye las huellas al terminar (opción `--no-blind-index` para omitirlo);
  `decrypt` vacía las huellas solo si se pide (`--clear-blind-index`), porque siguen siendo válidas.
- `encrypt:genkey` advierte que una clave generada **no se aplica sola** y remite a `encrypt:rotate-key`; con la
  clave de huella derivada, añade que rotar la de cifrado reconstruye las huellas.
- El procedimiento manual de varios comandos deja de documentarse como vía de rotación.

## 3. Configuración sin depender de la receta

1. **Valores por defecto funcionales** en `PSolutionsEncryptBundle::configure()`:
   `encrypt_key` → `'%env(default::PSOLUTIONS_ENCRYPT_KEY)%'`; `blind_index_key` → `null` (derivada). Con solo la
   variable de entorno, el bundle funciona sin archivo en `config/packages/`. El procesador `default::` resuelve una
   variable inexistente a `null`: la aplicación arranca igualmente y el error llega al primer cifrado (punto 4), en
   lugar de un `EnvNotFoundException` al construir el contenedor.
   El parámetro `psolutions_encrypt.blind_index_key_derived` se calcula en `loadExtension()` (sin
   `symfony/expression-language`) y lo usa `encrypt:genkey` para su aviso.
2. **Comando `encrypt:install`**: escribe `config/packages/psolutions_encrypt.yaml` (si no existe; `--force` para
   sobrescribir) y añade el bloque `###> psolutions/encrypt-bundle ###` con una clave generada a `.env`
   (o `.env.local` con `--local`) si la variable no está definida ni en `.env` ni en `.env.local`. Con
   `--blind-index-key` hace lo mismo con `PSOLUTIONS_BLIND_INDEX_KEY` y activa `blind_index_key` en el yaml
   existente: descomenta la línea de la plantilla o la inserta tras `encrypt_key` (§2.3). Idempotente.
3. **Receta corregida** en `recipe/` (bloque `env` con el nombre de paquete correcto y `blind_index_key` comentado),
   para quien declare el endpoint en su proyecto:

   ```json
   "extra": { "symfony": { "endpoint": [
       "https://api.github.com/repos/frizquierdo/EncryptBundle/contents/recipe/index.json",
       "flex://defaults"
   ] } }
   ```

   Se quita `extra.symfony.recipes` del `composer.json` del bundle (no tiene efecto).
   Las fuentes son `recipe/manifest.json` y `recipe/config/`; `php recipe/build.php` genera el formato que lee Flex
   (`recipe/index.json` y `recipe/psolutions.encrypt-bundle.5.1.json`). Un test comprueba que la receta compilada,
   sus fuentes y la plantilla de `encrypt:install` coinciden.
4. Aviso claro si la clave falta o es `change_me!`: `EncryptException` al primer cifrado, con el mensaje que indica
   `encrypt:genkey` / `encrypt:install`.
5. `symfony/yaml` pasa a `require`: el bundle carga `config/services.yaml` y hasta ahora dependía de que la
   aplicación la tuviera instalada.
6. El listener recibe `BlindIndexer` como 4.º argumento opcional y autowired (sin argumento con nombre en
   `services.yaml`), para que un `listener_class` propio sin ese parámetro siga funcionando.

## 3 bis. Fallos previos corregidos en 5.1.0

Aparecieron al poner en marcha la suite de tests, que no se ejecutaba porque faltaba el autoload:

1. **Grave.** Al insertar una entidad con dos o más campos `#[Encrypted]`, todos menos el último se guardaban **en
   claro**. Tras cada `recomputeSingleEntityChangeSet()`, Doctrine toma como original el valor cifrado; al restaurar
   el valor en claro, el recompute del campo siguiente devolvía el claro al changeset. Ahora el valor en claro
   restaurado también se fija como original (`setOriginalEntityProperty`). Arreglo para datos existentes:
   `encrypt:database encrypt` (solo cifra lo que aún no está cifrado).
2. `encryptor_class` se ignoraba: no se pasaba a la fábrica del cifrador.
3. `encrypt:database` dejaba de recorrer entidades al encontrar la primera tabla compartida por herencia de tabla
   única (`return` en lugar de `continue`), y usaba el método deprecado `getReflectionProperties()`.
4. `OpenSslEncryptor::decrypt()`: el base64 no se decodificaba en modo estricto y el aviso de datos CBC antiguos
   (anteriores a v2.1) nunca podía aparecer. Ahora se da en datos demasiado cortos con espacio para un IV de 16
   bytes y, cuando falla GCM, si la longitud es compatible con CBC.
5. Faltaba `phpunit.xml.dist` (bootstrap del autoload, suites `unit` e `integration`).

## 4. Archivos

| Archivo | Cambio |
| --- | --- |
| `src/Attributes/BlindIndex.php` | nuevo |
| `src/BlindIndex/BlindIndexer.php`, `BlindIndexNormalizerInterface.php`, `LowerTrimNormalizer.php` | nuevos |
| `src/EventListener/DoctrineEncryptListener.php` | calcular huellas en `onFlush` antes de cifrar |
| `src/Command/BlindIndexRebuildCommand.php`, `src/Command/InstallCommand.php` | nuevos |
| `src/Command/GenKeyCommand.php` | opción `--blind-index` y aviso de rotación |
| `src/Command/RotateKeyCommand.php` | nuevo (§2.5) |
| `src/Command/EncryptDatabaseCommand.php` | reconstruir huellas tras `encrypt`; fallos §3 bis.3 |
| `src/Maintenance/DatabaseRewriter.php`, `RewriteReport.php` | nuevos: motor común (§2.4) |
| `src/Util/EnvFile.php` | nuevo: edición de `.env` (install y rotate-key) |
| `src/EventListener/DoctrineEncryptListener.php` | además, fallo §3 bis.1 |
| `src/Encryptors/OpenSslEncryptor.php`, `EncryptorFactory.php` | aviso `change_me!`, clave nula; fallo §3 bis.4 |
| `src/PSolutionsEncryptBundle.php` | defaults de `encrypt_key`, opción `blind_index_key`, parámetros `connections` y `blind_index_key_derived`, autoconfiguración de normalizadores |
| `config/services.yaml` | registrar servicios y comandos; pasar `encryptor_class` (§3 bis.2) |
| `recipe/` | receta corregida, `build.php`, `index.json` y receta compilada |
| `composer.json` | quitar `extra.symfony.recipes`; añadir `symfony/yaml` |
| `phpunit.xml.dist` | nuevo |
| `README.md`, `CHANGELOG.md`, `CLAUDE.md` | ayuda y 5.1.0 |

## 5. Tests

- Unit: `BlindIndexer` (determinismo, normalizadores, `null`/vacío, clave derivada ≠ clave de cifrado,
  `blind_index_key` explícita).
- Listener: insert y update calculan la huella con el valor en claro; cambiar el origen la actualiza;
  `is_disabled` sigue calculándola; `#[BlindIndex]` + `#[Encrypted]` en la misma propiedad → excepción.
- Comandos: `genkey --blind-index` (clave válida de 256 bits); `install --blind-index-key` (variable y opción en
  el yaml, sin duplicar); `rebuild` (lotes, `--dry-run`, colisiones sin escritura parcial); `install` (crea, no sobrescribe sin
  `--force`, no duplica la variable).
- `rotate-key`: datos y huellas coherentes tras rotar cada clave por separado y juntas; fallo a mitad (excepción
  inyectada) → BD sin cambios; colisión → rollback; `--dry-run` no escribe; `--write-env` reemplaza la variable.
- `encrypt:database encrypt` deja las huellas calculadas.
- Bundle sin archivo de configuración + variable de entorno → cifra y descifra.
- Regresión §3 bis.1: insert con dos campos cifrados los cifra ambos y un segundo flush no reescribe la fila.

Los tests de integración usan SQLite en memoria (`tests/Fixtures/OrmTestCase.php`) y se saltan sin `pdo_sqlite`.
Suite completa: `docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/simple-phpunit`.

## 6. Adopción en una aplicación existente

1. `composer require psolutions/encrypt-bundle:^5.1`.
   Después, `encrypt:database encrypt` para cifrar los campos que el fallo §3 bis.1 haya dejado en claro
   (copia de seguridad antes).
2. Añadir la columna de la huella (`length: 64`, `nullable`, única) con `#[BlindIndex(source: ..., normalizer: ...)]`
   y su migración. Si la aplicación ya calculaba una huella propia (servicio + listener de Doctrine + clave en el
   entorno), se eliminan: el listener del bundle la calcula en el `flush` y la clave se deriva de `encrypt_key`
   (o se usa `blind_index_key`).
3. Las búsquedas usan `BlindIndexer::hash(Entidad::class, 'propiedad', $valor)`.
4. `encrypt:blind-index:rebuild --dry-run`, resolver las colisiones que liste (filas que comparten el mismo valor
   normalizado en una columna única) y ejecutar sin `--dry-run`.
