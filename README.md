# Laravel Arsenal Core (LAC)

Laravel Arsenal Core (LAC) is a toolkit for standardizing and accelerating Laravel development with automated scaffolding, synchronization, and code generation tools.

🇺🇸 [English (current)](https://github.com/ChikauKume/laravel-arsenal-core/blob/main/README.md) | 
🇯🇵 [日本語](https://github.com/ChikauKume/laravel-arsenal-core/blob/main/README.ja.md)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lac/toolkit.svg)](https://packagist.org/packages/lac/toolkit)
<!-- [![Total Downloads](https://img.shields.io/packagist/dt/lac/toolkit.svg)](https://packagist.org/packages/lac/toolkit) -->
[![License](https://img.shields.io/packagist/l/lac/toolkit.svg?v=202405)](https://packagist.org/packages/lac/toolkit)

## Overview

LAC is a comprehensive toolset that automates repetitive tasks in Laravel development and generates code that follows standardized patterns, resulting in maintainable systems.

## Features

- **Scaffolding**: Generate models, controllers, services, requests, views, factories, and seeders with a single command
- **Route Synchronization**: Automatic generation and synchronization of web/API routes based on controllers
- **Migration and Excel Integration**: Generate Excel templates based on migration files and import data
- **Model Relation Synchronization**: Automatically generate model relationships from migration files
- **Validation Synchronization**: Automatically generate validation rules for request classes based on database schema

## Development Workflow with LAC

LAC enhances your development workflow:

1. Generate models, controllers, services, requests, and views with `lac:scaffold`
2. Auto-generate and synchronize routes with `lac:sync-routes`
3. Manually edit migration files to adjust database schema as needed
4. Update model relationships with `lac:sync-model-rel`
5. Generate and update validation rules with `lac:sync-validations`
6. Generate Excel templates with `lac:db-template`
7. Place Excel files with your data in storage/app/db/imports directory
8. Import test data from Excel with `lac:db-import`

## Installation

Install LAC via Composer:

```bash
composer require lac/toolkit --dev
```

LAC will be automatically registered through Laravel's service provider auto-discovery.

## Requirements

- PHP 8.2 or higher
- Laravel 10.0 or higher
- PhpSpreadsheet 4.2 or higher

## Usage

### Scaffolding

Generate a complete CRUD resource with a single command:

```bash
php artisan lac:scaffold User
```

Generate multiple resources at once:

```bash
php artisan lac:scaffold User Post Comment
```

Options:

- `--hard-delete`: Disable soft delete functionality
- `--force`: Overwrite existing files
- `--no-view`: Skip generating view files

### Route Synchronization

Automatically generate routes based on controllers:

```bash
php artisan lac:sync-routes
```

Synchronize API routes only:

```bash
php artisan lac:sync-routes --api
```

Synchronize web routes only:

```bash
php artisan lac:sync-routes --web
```

### Excel Template Generation

Generate Excel templates based on database table structure:

```bash
php artisan lac:db-template
```

Target specific tables only:

```bash
php artisan lac:db-template --table=users
```

### Data Import from Excel

Import data from Excel files into the database:
Place Excel files with your data in storage/app/db/imports directory
Processed Excel files are automatically moved to storage/app/db/processed directory

```bash
php artisan lac:db-import
```

Import specific tables only:

```bash
php artisan lac:db-import --table=users
```

### Model Relation Synchronization

Automatically generate model relationships based on migration files:

```bash
php artisan lac:sync-model-rel
```

Synchronize specific models only:

```bash
php artisan lac:sync-model-rel User
```

### Validation Synchronization

Generate validation rules for request classes based on database schema:

```bash
php artisan lac:sync-validations
```

Target specific tables only:

```bash
php artisan lac:sync-validations --tables=users,posts
```

## Architecture Design

LAC follows these design principles:

- **Service Classes**: Business logic is centralized in service classes
- **Repository Pattern**: Data access is separated from models and controllers
- **Slim Controllers**: Controllers focus on request validation and service calls
- **Systematic Directory Structure**: Files are organized in a consistent structure

## Roadmap

Features planned for future releases:

- Bidirectional auto-sync between ER diagram and migration files
- Catalog of frequently used functionality (CRUD, search, authentication, etc.)
- Catalog of UI/UX components (buttons, forms, modals, etc.)

*Note: This roadmap represents our current plans but may change based on feedback, priorities, and changing circumstances.

## License

Released under the MIT License. See the [LICENSE](https://github.com/ChikauKume/laravel-arsenal-core?tab=MIT-1-ov-file) file for details.

## Author

- **Chikau Kume** - *Developer / Project Manager* - [GitHub](https://github.com/ChikauKume)

## Contributing

Contributions are welcome, including bug reports, feature requests, and pull requests.