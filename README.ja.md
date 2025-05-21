# LAC (Laravel Arsenal Core)

LAC（Laravel Arsenal Core）は、自動スキャフォールディング、同期、コード生成ツールを使用してLaravel開発を標準化し加速させるためのツールキットです。

## 概要

LACは、Laravel開発における繰り返し作業を自動化し、標準パターンに従ったコードを生成する包括的なツールセットです。これにより保守性の高いシステム開発が可能になります。

## 機能

- **スキャフォールディング**: モデル、コントローラー、サービス、リクエスト、ビュー、ファクトリー、シーダーを単一のコマンドで生成
- **ルート同期**: コントローラーに基づいたWeb/APIルートの自動生成・同期
- **マイグレーションとExcel連携**: マイグレーションファイルに基づくExcelテンプレート生成とデータインポート
- **モデルリレーション同期**: マイグレーションファイルからモデルのリレーションを自動生成
- **バリデーション同期**: データベーススキーマに基づいたリクエストクラスのバリデーションルール自動生成

## LACによる開発ワークフロー

LACは以下のような開発ワークフローを効率化します：

1. `lac:scaffold`でモデル、コントローラー、サービス、リクエスト、ビューを生成
2. `lac:sync-routes`でルートを自動生成・同期
3. 必要に応じてマイグレーションファイルを手動で調整
4. `lac:sync-model-rel`でモデル間のリレーションを更新
5. `lac:sync-validations`でバリデーションルールを生成・更新
6. `lac:db-template`でExcelテンプレートを生成
7. データを入力したExcelファイルを`storage/app/db/imports`ディレクトリに配置
8. `lac:db-import`でExcelからデータをインポート

## インストール

Composerを使用してLACをインストールできます：

```bash
composer require lac/toolkit --dev
```

Laravelのサービスプロバイダー自動検出機能により、LACは自動的に登録されます。

## 必要要件

- PHP 8.2以上
- Laravel 10.0以上
- PhpSpreadsheet 4.2以上

## 使い方

### 1.スキャフォールディング

1つのコマンドで完全なCRUDリソースを生成します：

```bash
php artisan lac:scaffold User
```

複数のリソースを同時に生成：

```bash
php artisan lac:scaffold User Post Comment
```

オプション：
- `--hard-delete`: ソフトデリート機能を無効にする
- `--force`: 既存のファイルを上書きする
- `--no-view`: ビューファイルの生成をスキップする

https://github.com/user-attachments/assets/21d4f6eb-2140-4bc0-8013-473a367243b9


### 2.ルート同期

コントローラーに基づいてルートを自動生成：

```bash
php artisan lac:sync-routes
```

オプション：
- `--web`: Webルートのみ同期
- `--api`: APIルートのみ同期
  
https://github.com/user-attachments/assets/2a9c4957-e9c6-4105-bd3c-7a331510ce9b


### 3.Excelテンプレート生成

データベースのテーブル構造に基づいてExcelテンプレートを生成：

```bash
php artisan lac:db-template
```

https://github.com/user-attachments/assets/a0244e67-a281-4cd7-bbbf-a810e34f28c6


### 4.Excelからのデータインポート

Excelファイルからデータベースにデータをインポート：
データを入力したExcelファイルを`storage/app/db/imports`ディレクトリに配置してください
処理済みのExcelファイルは自動的に`storage/app/db/processed`ディレクトリに移動されます

```bash
php artisan lac:db-import
```

https://github.com/user-attachments/assets/7a47fd84-dc90-4478-83f7-fb4bcc0885a4


### 5.モデルリレーション同期

マイグレーションファイルに基づいてモデルのリレーションを自動生成：

```bash
php artisan lac:sync-model-rel
```

特定のモデルのみ同期：

```bash
php artisan lac:sync-model-rel User
```

### 6.バリデーション同期

マイグレーションに基づいてリクエストクラスのバリデーションルールを生成：

```bash
php artisan lac:sync-validations
```

特定のテーブルのみを対象とする：

```bash
php artisan lac:sync-validations --tables=users,posts
```

## アーキテクチャ設計

LACは以下の設計原則に従っています：

- **サービスクラス**: ビジネスロジックはサービスクラスに集約
- **リポジトリパターン**: データアクセスはモデルとコントローラーから分離
- **スリムなコントローラー**: コントローラーはリクエスト検証とサービス呼び出しに専念 (ビジネスロジックはサービスファイルに集約)
- **体系的なディレクトリ構造**: ファイルは一貫した構造で整理

## 今後の予定

今後追加予定の機能：

- ERとマイグレーションファイルの自動同期
- よく使用する機能のカタログ（CRUD、検索、認証など）
- UI/UXコンポーネントのカタログ（ボタン、フォーム、モーダルなど）

*注意: 状況の変化に応じて変更される可能性があります。

## ライセンス

MITライセンスの下で公開されています。詳細は[LICENSE](https://github.com/ChikauKume/laravel-arsenal-core?tab=MIT-1-ov-file)ファイルを参照してください。

## 作成者

- **[Chikau Kume](https://github.com/ChikauKume)**
 
バグ報告、機能リクエスト、プルリクエストなどがございましたら是非共有お願いいたします。
