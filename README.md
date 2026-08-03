<img alt="schemafox.png" src="https://github.com/bhamner/schema-fox/blob/main/schemafox.png?raw=true"  width="150">

# schema-fox

A Laravel package that builds an HTML form from a model's MySQL table schema, so you do not have to hand-write every field.

**Requirements**
- PHP 8.1+
- **Laravel 10.x or 11.x** (requires `illuminate/database`, `illuminate/http`, `illuminate/session`, and `illuminate/support` `^10.0|^11.0`)
- **MySQL or MariaDB only** (`information_schema` + `COLUMN_TYPE` for enum/set)

| Laravel | Supported |
|---------|-----------|
| 10.x | Yes |
| 11.x | Yes |
| 9.x and earlier | No |
| 12.x+ | Not declared yet |

## What it maps

| Schema signal | Input |
|---------------|--------|
| Integer primary key | Hidden input |
| Integer / numeric columns | Number input (type-appropriate min/max) |
| Integer foreign keys | Select of related rows (id → label) |
| `tinyint(1)` | Checkbox (`0`/`1`) |
| `varchar` / `char` / `text` by name (`password`, `email`, `image`/`logo`/`photo`, `url`, …) | Typed inputs / file |
| Long text (`max_length > 300`, medium/longtext) | Textarea |
| `enum` | Select |
| `set` | Checkbox group (`name[]`) |
| Dates | `date` / `time` / `datetime-local` |
| Blobs / binary | File |
| Unknown types | Text input fallback |

Laravel `created_at` / `updated_at` / `deleted_at` columns are omitted. Eloquent `$hidden` attributes are omitted.

## Installation

Add this repo to your app `composer.json`:

```json
"repositories": [{
    "type": "vcs",
    "url": "https://github.com/bhamner/schema-fox"
}]
```

Then require it:

```sh
composer require bhamner/schema-fox:dev-main
```

## Usage

1. Add the trait to a model:

```php
use Bhamner\SchemaFox\SchemaFox;

class MyModel extends Model
{
    use SchemaFox;

    // Optional: column used as the label for FK selects (default: name)
    public string $schemaFoxDisplayColumn = 'name';
}
```

2. Render the form (library output is HTML-escaped):

```php
{!! \App\Models\MyModel::buildForm(
    url: route('users.store'),
    values: null,
    method: 'post',
    files: false
) !!}
```

For edit forms, pass model attributes:

```php
{!! \App\Models\User::buildForm(
    route('users.update', $user),
    $user->toArray(),
    'put'
) !!}
```

- If `$values` is `null`, old input is used when present (validation redirect), otherwise fields are empty.
- `put` / `patch` / `delete` are submitted as POST with a `_method` spoof field.
- Set `$files = true` when the form includes file inputs so `enctype="multipart/form-data"` is added.

### Other helpers

```php
MyModel::getMap();                 // column metadata from information_schema
MyModel::getInputs($values);       // mapped column objects with ->input HTML
MyModel::clearSchemaCache();       // flush cached schema (e.g. after migrations in tests)
```

## Security model

- All generated attribute values, labels, comments, option text, and validation messages are escaped with `htmlspecialchars`.
- `getSchema()` uses bound parameters and rejects table/schema identifiers outside `[A-Za-z0-9_]`.
- CSRF `_token` is included on every form.
- Password fields are never prefilled.
- **You still must authorize** who can render and submit these forms. Hidden primary keys and FK option lists are not an access-control layer.
- Host apps must validate uploads (type, size, storage) when file fields are used.
- Prefer not to expose `getMap()` / raw schema to untrusted clients.

`{!! !!}` is appropriate only because the package escapes its own output. Do not pass attacker-controlled HTML as `$url`.

## Limitations

- MySQL/MariaDB only (not Postgres/SQLite).
- FK selects assume a display column (`name` by default, or `$schemaFoxDisplayColumn`) and load at most 500 rows, ordered by that column. Soft-deleted rows are excluded when `deleted_at` exists.
- Schema metadata is cached in-process per connection/database/table for the request lifecycle (and until `clearSchemaCache()`).
- Generated markup is plain HTML (no CSS framework). Treat styling and UX as your responsibility.
- This package builds UI only; validation, authorization, and persistence stay in your app.

## Development

```sh
composer install
composer test
```

## License

GPL-3.0-only
