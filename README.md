# Laravel Arsenal Core (LAC)

Laravel Arsenal Core (LAC) is a toolkit for standardizing and accelerating Laravel development with automated scaffolding, synchronization, and code generation tools.

🇺🇸 [English (current)](https://github.com/ChikauKume/laravel-arsenal-core/blob/main/README.md) | 
🇯🇵 [日本語](https://github.com/ChikauKume/laravel-arsenal-core/blob/main/README.ja.md)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lac/toolkit.svg)](https://packagist.org/packages/lac/toolkit)
[![License](https://img.shields.io/packagist/l/lac/toolkit.svg?v=202405)](https://packagist.org/packages/lac/toolkit)
<!-- [![Total Downloads](https://img.shields.io/packagist/dt/lac/toolkit.svg)](https://packagist.org/packages/lac/toolkit) -->

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

### 1.Scaffolding

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

https://github.com/user-attachments/assets/21d4f6eb-2140-4bc0-8013-473a367243b9

### 2.Route Synchronization

Automatically generate routes based on controllers:

```bash
php artisan lac:sync-routes
```

Options:
- `--web`: Web routes only
- `--api`: API routes only

https://github.com/user-attachments/assets/2a9c4957-e9c6-4105-bd3c-7a331510ce9b


### 3.Excel Template Generation

Generate Excel templates based on database table structure:

```bash
php artisan lac:db-template
```

https://github.com/user-attachments/assets/a0244e67-a281-4cd7-bbbf-a810e34f28c6


### 4.Data Import from Excel

Import data from Excel files into the database:
Place Excel files with your data in storage/app/db/imports directory
Processed Excel files are automatically moved to storage/app/db/processed directory

```bash
php artisan lac:db-import
```

https://github.com/user-attachments/assets/1a96d376-97df-42c5-8abe-474527b2a9d3

### 5.Model Relation Synchronization

Automatically generate model relationships based on migration files:

```bash
php artisan lac:sync-model-rel
```

Synchronize specific models only:

```bash
php artisan lac:sync-model-rel User
```
https://github.com/user-attachments/assets/6ff284dc-2fe4-4260-a9b7-4056007615b6

### 6.Validation Synchronization

Generate validation rules for request classes based on database schema:

```bash
php artisan lac:sync-validations
```

Target specific tables only:

```bash
php artisan lac:sync-validations --tables=users
```

https://github.com/user-attachments/assets/c7eebc48-0a6e-4f20-942e-3add216ee9d4

## Architecture Design

LAC follows these design principles:

- **Service Classes**: Business logic is centralized in service classes
- **Repository Pattern**: Data access is separated from models and controllers
- **Slim Controllers**: Controllers focus on request validation and service calls
- **Systematic Directory Structure**: Files are organized in a consistent structure

## License

Released under the MIT License. See the [LICENSE](https://github.com/ChikauKume/laravel-arsenal-core?tab=MIT-1-ov-file) file for details.

## Author
- [Chikau Kume](https://github.com/ChikauKume)

Contributions are welcome, including bug reports, feature requests, and pull requests.
