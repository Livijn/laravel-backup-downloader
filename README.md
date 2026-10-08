# laravel-backup-downloader

[![Latest Version on Packagist](https://img.shields.io/packagist/v/livijn/laravel-backup-downloader.svg?style=flat-square)](https://packagist.org/packages/livijn/laravel-backup-downloader)
[![Total Downloads](https://img.shields.io/packagist/dt/livijn/laravel-backup-downloader.svg?style=flat-square)](https://packagist.org/packages/livijn/laravel-backup-downloader)

Downloads the backups from the [laravel-backup](https://github.com/spatie/laravel-backup)

## Installation

You can install the package via composer:

```bash
composer require livijn/laravel-backup-downloader
```

## Usage

```php
php artisan backup:download
```

```php
php artisan backup:import
```

`backup:download` reuses the local SQL dump when the selected backup, SQL entry, and local file metadata are unchanged. Use `php artisan backup:download --force` to fetch it again. Only the requested SQL entry is extracted, and a failed download leaves the previous dump available while returning a failure exit code.

`backup:import` skips `views` inserts by default because that table is usually large and disposable in local imports.
Use `--skip=` to import every table, or `--skip=views,telescope_entries` to skip multiple tables.

To build eligible secondary indexes after loading the data:

```bash
php artisan backup:import --defer-indexes
```

This mode keeps PRIMARY, UNIQUE and foreign-key-supporting indexes during the load, then rebuilds the deferred indexes before completing. It preserves row data and leaves unsupported dump formats unchanged. SQL errors, including failed index rebuilds, stop the import before migrations run. It can be combined with `--skip`.

Both commands report elapsed time for their data processing phases.

### Testing

```bash
composer test
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

### Security

If you discover any security related issues, please email ouff@live.se instead of using the issue tracker.

## Credits

-   [Fredrik Livijn](https://github.com/livijn)
-   [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Laravel Package Boilerplate

This package was generated using the [Laravel Package Boilerplate](https://laravelpackageboilerplate.com).
