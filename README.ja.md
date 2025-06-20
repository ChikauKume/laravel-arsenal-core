# LAC (Laravel Arsenal Core)

LAC（Laravel Arsenal Core）は、自動スキャフォールディング、同期、コード生成ツールを使用してLaravel開発を標準化し加速させるためのツールキットです。

## 概要

LACは、Laravel開発における繰り返し作業を自動化し、標準パターンに従ったコードを生成する包括的なツールセットです。これにより保守性の高いシステム開発が可能になります。

## 🚀 v2.0の新機能 - 双方向データベース設計
LAC v2.0では、PlantUML ER図とLaravelマイグレーション間の画期的な双方向同期を導入しました：
・設計優先: PlantUMLでデータベーススキーマを視覚的に作成し、マイグレーションを生成
・コード優先: マイグレーションを作成し、最新のER図を自動生成

```
ER図 → マイグレーション
php artisan lac:gen-migration

# マイグレーション → ER図  
php artisan lac:gen-diagram
```

## 機能

- **スキャフォールディング**: モデル、コントローラー、サービス、リクエスト、ビュー、ファクトリー、シーダーを単一のコマンドで生成
- **ルート同期**: コントローラーに基づいたWeb/APIルートの自動生成・同期
- **マイグレーションとExcel連携**: マイグレーションファイルに基づくExcelテンプレート生成とデータインポート
- **モデルリレーション同期**: マイグレーションファイルからモデルのリレーションを自動生成
- **バリデーション同期**: データベーススキーマに基づいたリクエストクラスのバリデーションルール自動生成

## LACによる開発ワークフロー

LACは以下のような開発ワークフローを効率化します：

**オプション1: 設計優先アプローチ**
1. PlantUMLでデータベーススキーマを設計
2. `lac:gen-migration`でマイグレーションを生成
3. `lac:scaffold`でリソースを生成
4. `lac:sync-routes`でルートを自動生成
5. `lac:sync-model-rel`でモデルリレーションを更新
6. `lac:sync-validations`でバリデーションルールを生成

**オプション2: コード優先アプローチ**
1. マイグレーションファイルを手動で作成
2. `lac:gen-diagram`でドキュメント用のER図を生成
3. `lac:scaffold`でリソースを生成
4. 上記の手順4-6を続行

**テストデータ管理**
1. `lac:db-template`でExcelテンプレートを生成
2. Excelファイルにテストデータを入力
3. ファイルを`storage/app/db/imports`ディレクトリに配置
4. `lac:db-import`でデータをインポート

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

### スキャフォールディング

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


### ルート同期

コントローラーに基づいてルートを自動生成：

```bash
php artisan lac:sync-routes
```

オプション：
- `--web`: Webルートのみ同期
- `--api`: APIルートのみ同期
  
https://github.com/user-attachments/assets/2a9c4957-e9c6-4105-bd3c-7a331510ce9b


### Excelテンプレート生成

データベースのテーブル構造に基づいてExcelテンプレートを生成：

```bash
php artisan lac:db-template
```

https://github.com/user-attachments/assets/a0244e67-a281-4cd7-bbbf-a810e34f28c6


### Excelからのデータインポート

Excelファイルからデータベースにデータをインポート：
データを入力したExcelファイルを`storage/app/db/imports`ディレクトリに配置してください
処理済みのExcelファイルは自動的に`storage/app/db/processed`ディレクトリに移動されます

```bash
php artisan lac:db-import
```

https://github.com/user-attachments/assets/1a96d376-97df-42c5-8abe-474527b2a9d3

### モデルリレーション同期

マイグレーションファイルに基づいてモデルのリレーションを自動生成：

```bash
php artisan lac:sync-model-rel
```

特定のモデルのみ同期：

```bash
php artisan lac:sync-model-rel User
```

https://github.com/user-attachments/assets/6ff284dc-2fe4-4260-a9b7-4056007615b6


### バリデーション同期

マイグレーションに基づいてリクエストクラスのバリデーションルールを生成：

```bash
php artisan lac:sync-validations
```

特定のテーブルのみを対象とする：

```bash
php artisan lac:sync-validations --tables=users
```

https://github.com/user-attachments/assets/c7eebc48-0a6e-4f20-942e-3add216ee9d4

## 双方向ER図同期

### PlantUMLからマイグレーション生成
PlantUMLでデータベースを視覚的に設計し、Laravelマイグレーションを生成：

```
php artisan lac:gen-migration
```
.pumlファイルをstorage/app/db/diagrams/ディレクトリに配置してください。
デフォルトで検索されるファイル: schema.puml、er.puml、diagram.puml

⚠️ 重要: 完全な双方向同期を保証するため、PlantUML図に定義されていない既存のマイグレーションファイルは削除されます。削除前に確認プロンプトが表示されます。
マイグレーションからPlantUML生成

### マイグレーションファイルからER図生成
```
php artisan lac:gen-diagram
```
https://github.com/user-attachments/assets/98e616a9-54c6-447e-950d-7f3089263ae7

storage/app/db/diagrams/generated/配下に作成されたERが格納されます。

## アーキテクチャ設計

LACは以下の設計原則に従っています：

- **サービスクラス**: ビジネスロジックはサービスクラスに集約
- **リポジトリパターン**: データアクセスはモデルとコントローラーから分離
- **スリムなコントローラー**: コントローラーはリクエスト検証とサービス呼び出しに専念 (ビジネスロジックはサービスファイルに集約)
- **体系的なディレクトリ構造**: ファイルは一貫した構造で整理
  

*注意: 状況の変化に応じて変更される可能性があります。

## ライセンス

MITライセンスの下で公開されています。詳細は[LICENSE](https://github.com/ChikauKume/laravel-arsenal-core?tab=MIT-1-ov-file)ファイルを参照してください。

## 作成者

- **[Chikau Kume](https://github.com/ChikauKume)**
 
バグ報告、機能リクエスト、プルリクエストなどがございましたら是非共有お願いいたします。
