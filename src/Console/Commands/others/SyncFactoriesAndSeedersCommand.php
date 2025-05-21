<?php

namespace Lac\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

class SyncFactoriesAndSeedersCommand extends Command {
/**
 * The name and signature of the console command.
 *
 * @var string
 */
/**
 * The name and signature of the console command.
 *
 * @var string
 */
    protected $signature = 'lac:sync-factory-seeder     
                    {--force : Overwrite existing files without confirmation}
                    {--auto-fix : Automatically fix SoftDeletes inconsistencies by adjusting models to match tables}';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize factories and seeders based on migrations and models, with automatic SoftDeletes consistency checking';
    /**
     * The filesystem instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;

    /**
     * Models with generated factories
     *
     * @var array
     */
    protected $modelsWithFactories = [];

    /**
     * Maintains a mapping of models to their dependencies
     *
     * @var array
     */
    protected $modelDependencies = [];

    /**
     * Store SoftDeletes inconsistencies found during checking
     *
     * @var array
     */
    protected $softDeletesInconsistencies = [];

    /**
     * Faker instance for generating data
     *
     * @var \Faker\Generator
     */
    protected $faker;

    /**
     * Mapping of column types to faker methods.
     *
     * @var array
     */
    protected $typeMap = [
        // String types
        'string' => [
            'email' => '$this->faker->safeEmail()',
            'name' => '$this->faker->name()',
            'first_name' => '$this->faker->firstName()',
            'last_name' => '$this->faker->lastName()', 
            'phone' => '$this->faker->phoneNumber()',
            'password' => '$this->faker->password()',
            'url' => '$this->faker->url()',
            'address' => '$this->faker->address()',
            'city' => '$this->faker->city()',
            'state' => '$this->faker->state()',
            'country' => '$this->faker->country()',
            'zip' => '$this->faker->postcode()',
            'title' => '$this->faker->sentence(3)',
            'slug' => '$this->faker->slug()',
            'username' => '$this->faker->userName()',
            'default' => '$this->faker->sentence(4)',
        ],
        'char' => [
            'default' => '$this->faker->randomLetter()'
        ],
        'text' => [
            'description' => '$this->faker->paragraph()',
            'content' => '$this->faker->paragraphs(3, true)',
            'bio' => '$this->faker->paragraph()',
            'default' => '$this->faker->paragraphs(2, true)',
        ],
        
        // Numeric types
        'integer' => [
            'year' => '$this->faker->year()',
            'age' => '$this->faker->numberBetween(18, 80)',
            'count' => '$this->faker->numberBetween(0, 1000)',
            'default' => '$this->faker->randomNumber()',
        ],
        'bigInteger' => [
            'default' => '$this->faker->randomNumber(8)',
        ],
        'float' => [
            'price' => '$this->faker->randomFloat(2, 10, 1000)',
            'amount' => '$this->faker->randomFloat(2, 10, 1000)',
            'default' => '$this->faker->randomFloat(2, 0, 100)',
        ],
        'decimal' => [
            'price' => '$this->faker->randomFloat(2, 10, 1000)',
            'amount' => '$this->faker->randomFloat(2, 10, 1000)',
            'default' => '$this->faker->randomFloat(2, 0, 100)',
        ],
        'double' => [
            'default' => '$this->faker->randomFloat(2, 0, 100)',
        ],
        
        // Boolean
        'boolean' => [
            'is_active' => '$this->faker->boolean()',
            'active' => '$this->faker->boolean()',
            'published' => '$this->faker->boolean()',
            'default' => '$this->faker->boolean()',
        ],
        
        // Date/Time
        'date' => [
            'birth_date' => '$this->faker->date()',
            'start_date' => '$this->faker->date()',
            'end_date' => '$this->faker->date()',
            'default' => '$this->faker->date()',
        ],
        'datetime' => [
            'default' => '$this->faker->dateTime()'
        ],
        'timestamp' => [
            'default' => '$this->faker->dateTime()'
        ],
        'time' => [
            'default' => '$this->faker->time()'
        ],
        
        // JSON
        'json' => [
            'default' => '$this->faker->json()',
        ],
        'jsonb' => [
            'default' => '$this->faker->json()',
        ],
        
        // UUID
        'uuid' => [
            'default' => '$this->faker->uuid()',
        ],
        
        // IP
        'ipAddress' => [
            'default' => '$this->faker->ipv4()',
        ],
        
        // Miscellaneous
        'enum' => [
            'default' => '$this->faker->randomElement({ENUM_VALUES})',
        ],
    ];

    /**
     * Create a new command instance.
     *
     * @param  \Illuminate\Filesystem\Filesystem  $files
     * @return void
     */
    public function __construct(Filesystem $files) {
        parent::__construct();
        $this->files = $files;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle() {
        // デバッグ情報：環境変数と設定の確認
        $this->info("ENV APP_FAKER_LOCALE: " . env('APP_FAKER_LOCALE', 'not set'));
        $this->info("Config app.faker_locale before: " . config('app.faker_locale'));
        
        // ロケールの設定（常にconfigから取得）
        $locale = config('app.faker_locale', 'ja_JP');
        $this->info("Using faker locale from config: {$locale}");
        
        // 設定を強制的に上書き
        config(['app.faker_locale' => $locale]);
        
        // Fakerインスタンスを再生成して強制的にロケールを適用
        app()->forgetInstance(\Faker\Generator::class);
        app()->singleton(\Faker\Generator::class, function () use ($locale) {
            $faker = \Faker\Factory::create($locale);
            
            // 日本語の場合、特別なプロバイダーを追加
            if ($locale === 'ja_JP') {
                $faker->addProvider(new \Faker\Provider\ja_JP\Person($faker));
                $faker->addProvider(new \Faker\Provider\ja_JP\Address($faker));
                $faker->addProvider(new \Faker\Provider\ja_JP\PhoneNumber($faker));
                $faker->addProvider(new \Faker\Provider\ja_JP\Company($faker));
                $faker->addProvider(new \Faker\Provider\ja_JP\Text($faker));
            }
            
            return $faker;
        });
        
        // ローカルインスタンスも保持（カスタムプロバイダー用）
        $this->faker = app(\Faker\Generator::class);
        
        // 設定確認
        $this->info("Config app.faker_locale after: " . config('app.faker_locale'));
        
        // 日本語パターンを登録
        $this->registerJapaneseFakerProviders();
        $this->info('=== Starting factory and seeder synchronization ===');
        
        // Step 0: Always check SoftDeletes consistency
        $this->newLine();
        $checkResult = $this->checkSoftDeletesConsistency();
        
        // Warn but proceed if inconsistencies found
        if ($checkResult !== Command::SUCCESS) {
            // 注意書きの追加: マイグレーション（テーブル）を正としてモデルを修正する方針について
            $this->line("<fg=cyan>IMPORTANT NOTE:</> The auto-fix function treats database structure (migrations) as the source of truth.");
            $this->line("<fg=cyan>Models will be adjusted to match their corresponding tables - adding or removing SoftDeletes trait as needed.</>");
            $this->line("<fg=cyan>This approach is safer than modifying database structure, especially in production environments.</>");
            $this->newLine();
            
            // Check if auto-fix option is enabled
            $autoFix = $this->option('auto-fix');
            
            if ($autoFix) {
                $this->info("Auto-fix enabled: Adjusting models to match their corresponding database tables...");
                $this->fixModelSoftDeletesConsistencies($this->softDeletesInconsistencies);
                $this->info("<fg=green>Models have been updated to match their corresponding database tables.</>");
                return Command::SUCCESS;
            } else {
                // ここで自動修正の確認を挿入
                if ($this->confirm('Do you want to automatically fix these inconsistencies by adjusting the models to match their tables?', true)) {
                    $this->fixModelSoftDeletesConsistencies($this->softDeletesInconsistencies);
                    $this->info("<fg=green>Models have been updated to match their corresponding database tables.</>");
                    return Command::SUCCESS;
                }
                
                // 自動修正しない場合、このまま進めるかを確認
                if (!$this->confirm('SoftDeletes inconsistencies found. Do you want to proceed anyway?', false)) {
                    $this->warn('Operation aborted by user.');
                    return Command::FAILURE;
                }
                $this->warn('Proceeding despite SoftDeletes inconsistencies...');
            }
        }
        
        // Step 1: Sync factories
        $this->newLine();
        $this->info('Step 1: Synchronizing factories from migrations');
        $this->line('-------------------------------------------');
        
        $factoryResult = $this->syncFactories();
        
        if ($factoryResult !== Command::SUCCESS) {
            $this->error('Factory synchronization failed. Aborting seeder synchronization.');
            return Command::FAILURE;
        }
        
        if (empty($this->modelsWithFactories)) {
            $this->warn('No factories were created or updated. Skipping seeder synchronization.');
            return Command::SUCCESS;
        }
        
        // Step 2: Sync seeders
        $this->newLine();
        $this->info('Step 2: Synchronizing seeders from factories');
        $this->line('-------------------------------------------');
        
        $seederResult = $this->syncSeeders();
        
        if ($seederResult !== Command::SUCCESS) {
            $this->error('Seeder synchronization failed.');
            return Command::FAILURE;
        }
        
        // 生成されたファクトリーに日本語対応を追加
        $this->newLine();
        $this->info('Adding Japanese localization to generated factories...');
        foreach ($this->modelsWithFactories as $modelName) {
            $factoryPath = database_path('factories/' . $modelName . 'Factory.php');
            if ($this->files->exists($factoryPath)) {
                $content = $this->files->get($factoryPath);
                
                // ファクトリーから不正なsetLocaleメソッドの呼び出しを削除
                if (strpos($content, '$this->faker->setLocale') !== false) {
                    $pattern = '/\s*\/\/\s*日本語ロケールを設定\s*\n\s*\$this->faker->setLocale\(.*?\);\s*\n/';
                    $content = preg_replace($pattern, "\n", $content);
                    $this->files->put($factoryPath, $content);
                    $this->info("  - Removed invalid setLocale call from {$modelName}Factory");
                }
                
                // 日本語アノテーションのみ追加
                if (strpos($content, '// Japanese language support') === false) {
                    $pattern = '/public function definition\(\): array\s*{\s*/';
                    $replacement = "public function definition(): array\n    {\n        // Japanese language support - data will be generated in Japanese\n        \n        ";
                    $content = preg_replace($pattern, $replacement, $content);
                    
                    $this->files->put($factoryPath, $content);
                    $this->info("  - Added Japanese language annotation to {$modelName}Factory");
                }
            }
        }
        
        $this->newLine();
        $this->info('=== Factory and seeder synchronization completed successfully ===');
        $this->info('You can now run "php artisan migrate:fresh --seed" to apply migrations and seed data.');
        
        return Command::SUCCESS;
    }

    /**
     * Register custom Faker providers for Japanese format data.
     *
     * @return void
     */
    protected function registerJapaneseFakerProviders(): void {
        // 日本語対応のタイプマップを追加
        $this->typeMap['string'] = array_merge($this->typeMap['string'], [
            // 日本語名前関連
            'name_ja' => '$this->faker->name()',         // 日本語の氏名
            'first_name_ja' => '$this->faker->firstName()', // 日本語の名
            'last_name_ja' => '$this->faker->lastName()',  // 日本語の姓
            // 住所関連
            'address_ja' => '$this->faker->address()',      // 日本語の住所
            'city_ja' => '$this->faker->city()',           // 日本語の市区町村
            'prefecture' => '$this->faker->prefecture()',   // 都道府県
            'postal_code' => '$this->faker->postcode()',    // 郵便番号
            'zipcode' => '$this->faker->postcode()',
            // 電話番号
            'tel' => '$this->faker->phoneNumber()',
            'mobile' => '$this->faker->phoneNumber()',
            // その他
            'company' => '$this->faker->company()',         // 会社名
            'company_ja' => '$this->faker->company()',      // 日本語の会社名
            'title_ja' => '$this->faker->realText(20)',     // 日本語のタイトル
        ]);
        
        $this->typeMap['text'] = array_merge($this->typeMap['text'], [
            'description_ja' => '$this->faker->realText(100)',  // 日本語の説明
            'content_ja' => '$this->faker->realText(200)',      // 日本語のコンテンツ
            'bio_ja' => '$this->faker->realText(150)',          // 日本語のプロフィール
        ]);
        
        // カスタムプロバイダーを追加（日本特有のデータフォーマット用）
        if (!isset($this->faker)) {
            $this->faker = app(\Faker\Generator::class);
        }
        
        // 日本の郵便番号フォーマット (例: 123-4567)
        $this->faker->addProvider(new class($this->faker) {
            protected $faker;
            
            public function __construct($faker) {
                $this->faker = $faker;
            }
            
            public function japanesePostalCode() {
                return sprintf('%03d-%04d', 
                    $this->faker->numberBetween(1, 999), 
                    $this->faker->numberBetween(1, 9999)
                );
            }
            
            public function japaneseMobilePhone() {
                $formats = [
                    '090-####-####',
                    '080-####-####',
                    '070-####-####',
                ];
                
                return $this->faker->numerify($this->faker->randomElement($formats));
            }
            
            public function prefecture() {
                $prefectures = [
                    '北海道', '青森県', '岩手県', '宮城県', '秋田県', '山形県', '福島県',
                    '茨城県', '栃木県', '群馬県', '埼玉県', '千葉県', '東京都', '神奈川県',
                    '新潟県', '富山県', '石川県', '福井県', '山梨県', '長野県', '岐阜県',
                    '静岡県', '愛知県', '三重県', '滋賀県', '京都府', '大阪府', '兵庫県',
                    '奈良県', '和歌山県', '鳥取県', '島根県', '岡山県', '広島県', '山口県',
                    '徳島県', '香川県', '愛媛県', '高知県', '福岡県', '佐賀県', '長崎県',
                    '熊本県', '大分県', '宮崎県', '鹿児島県', '沖縄県'
                ];
                
                return $this->faker->randomElement($prefectures);
            }
        });
    }

    /**
     * Check for SoftDeletes inconsistencies between models and database tables.
     *
     * @return int
     */
    protected function checkSoftDeletesConsistency(): int {
        $this->info('Step 0: Checking SoftDeletes consistency between models and tables');
        $this->line('-------------------------------------------');
        
        // Get all model files
        $modelPath = app_path('Models');
        if (!$this->files->isDirectory($modelPath)) {
            $this->warn("Models directory not found: {$modelPath}");
            return Command::FAILURE;
        }
        
        $modelFiles = $this->files->glob($modelPath . '/*.php');
        $inconsistencies = [];
        $syntaxErrors = [];
        
        foreach ($modelFiles as $file) {
            $fileName = basename($file);
            $modelName = str_replace('.php', '', $fileName);
            
            // Read the model file content
            $content = $this->files->get($file);
            
            // Check for syntax errors in arrays
            if ($this->hasSyntaxErrors($content, $modelName)) {
                $syntaxErrors[] = $modelName;
                $this->fixModelSyntaxErrors($modelName, $content, $file);
                // Re-read the content after fixing
                $content = $this->files->get($file);
            }
            
            // Check if the model uses SoftDeletes
            $usesSoftDeletes = strpos($content, 'use Illuminate\Database\Eloquent\SoftDeletes') !== false &&
                            (strpos($content, 'use SoftDeletes') !== false || 
                                preg_match('/use\s+.*SoftDeletes.*?;/s', $content));
            
            // Get table name from model
            $tableName = $this->getTableNameFromModel($content, $modelName);
            
            // Check if the table exists and has deleted_at column
            $tableHasDeletedAt = $this->tableHasDeletedAtColumn($tableName);
            
            // Check for inconsistencies
            if ($usesSoftDeletes && !$tableHasDeletedAt) {
                $inconsistencies[] = [
                    'model' => $modelName,
                    'table' => $tableName,
                    'issue' => 'Model uses SoftDeletes but table has no deleted_at column',
                    'fix_type' => 'remove_soft_deletes_from_model'
                ];
            } elseif (!$usesSoftDeletes && $tableHasDeletedAt) {
                $inconsistencies[] = [
                    'model' => $modelName,
                    'table' => $tableName,
                    'issue' => 'Table has deleted_at column but model doesn\'t use SoftDeletes',
                    'fix_type' => 'add_soft_deletes_to_model'
                ];
            }
        }
        
        // Store inconsistencies in a class property for access in other methods
        $this->softDeletesInconsistencies = $inconsistencies;
        
        // Report syntax errors that were fixed
        if (!empty($syntaxErrors)) {
            $this->newLine();
            $this->info("<fg=yellow>Fixed syntax errors in the following models:</>");
            foreach ($syntaxErrors as $model) {
                $this->line(" - {$model}");
            }
        }
        
        // Report inconsistencies
        if (empty($inconsistencies)) {
            $this->info("<fg=green>All models and tables are consistent with SoftDeletes usage.</>");
        } else {
            $this->warn('Found ' . count($inconsistencies) . ' SoftDeletes inconsistencies:');
            
            foreach ($inconsistencies as $index => $inconsistency) {
                $this->error(
                    ($index + 1) . ". Model: <fg=white>{$inconsistency['model']}</>, " .
                    "Table: <fg=white>{$inconsistency['table']}</>, " .
                    "Issue: <fg=white>{$inconsistency['issue']}</>"
                );
                
                // Suggest fixes based on issue type
                if ($inconsistency['fix_type'] === 'remove_soft_deletes_from_model') {
                    $this->line("   <fg=yellow>Suggestion:</> Either:");
                    $this->line("   1. Remove SoftDeletes from the model {$inconsistency['model']}");
                    $this->line("   2. Add deleted_at column to table {$inconsistency['table']} with migration:");
                    $this->line("      <fg=blue>php artisan make:migration add_soft_deletes_to_{$inconsistency['table']} --table={$inconsistency['table']}</>");
                } else {
                    $this->line("   <fg=yellow>Suggestion:</> Either:");
                    $this->line("   1. Add SoftDeletes trait to model {$inconsistency['model']}");
                    $this->line("   2. Remove deleted_at column from table {$inconsistency['table']} if not needed");
                }
                
                $this->newLine();
            }
            
            // 注意書きの追加: マイグレーション（テーブル）を正としてモデルを修正する方針について
            $this->line("<fg=cyan>IMPORTANT NOTE:</> The auto-fix function treats database structure (migrations) as the source of truth.");
            $this->line("<fg=cyan>Models will be adjusted to match their corresponding tables - adding or removing SoftDeletes trait as needed.</>");
            $this->line("<fg=cyan>This approach is safer than modifying database structure, especially in production environments.</>");
            $this->newLine();
            
            // Check if auto-fix option is enabled
            $autoFix = $this->option('auto-fix');
            
            if ($autoFix) {
                $this->info("Auto-fix enabled: Adjusting models to match their corresponding database tables...");
                $this->fixModelSoftDeletesConsistencies($this->softDeletesInconsistencies);
                $this->info("<fg=green>Models have been updated to match their corresponding database tables.</>");
                return Command::SUCCESS;
            } else {
                // ここで自動修正の確認を挿入
                if ($this->confirm('Do you want to automatically fix these inconsistencies by adjusting the models to match their tables?', true)) {
                    $this->fixModelSoftDeletesConsistencies($this->softDeletesInconsistencies);
                    $this->info("<fg=green>Models have been updated to match their corresponding database tables.</>");
                    return Command::SUCCESS;
                }
                
                // 自動修正しない場合、このまま進めるかを確認
                if (!$this->confirm('SoftDeletes inconsistencies found. Do you want to proceed anyway?', false)) {
                    $this->warn('Operation aborted by user.');
                    return Command::FAILURE;
                }
                $this->warn('Proceeding despite SoftDeletes inconsistencies...');
            }
        }
        
        return !empty($inconsistencies) ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Check if a model file has syntax errors in array definitions
     * 
     * @param string $content Model file content
     * @param string $modelName Model name
     * @return bool Whether syntax errors were found
     */
    protected function hasSyntaxErrors(string $content, string $modelName): bool {
        // Check for common syntax errors in arrays
        $hasErrors = false;
        
        // Check for key-less entries like => 'datetime' in arrays
        if (preg_match('/\[\s*|\,\s*=>\s*[\'"][^\'"]+[\'"]/', $content)) {
            $hasErrors = true;
        }
        
        // Check for array definitions with mismatched brackets
        if (preg_match_all('/protected\s+\$(\w+)\s*=\s*\[/s', $content, $starts) && 
            preg_match_all('/\]\s*;/s', $content, $ends)) {
            if (count($starts[0]) !== count($ends[0])) {
                $hasErrors = true;
            }
        }
        
        // Check for trailing commas before closing array
        if (preg_match('/,\s*\]\s*;/', $content)) {
            // This is valid PHP syntax, so not an error
        }
        
        return $hasErrors;
    }

    /**
     * Fix syntax errors in model file
     * 
     * @param string $modelName Model name
     * @param string $content Model file content
     * @param string $filePath Path to model file
     * @return void
     */
    protected function fixModelSyntaxErrors(string $modelName, string $content, string $filePath): void {
        $this->info("Fixing syntax errors in model: {$modelName}");
        
        // Fix arrays with key-less entries like => 'datetime'
        $content = preg_replace('/(\[\s*|\,\s*)=>\s*[\'"][^\'"]+[\'"](\s*\,|\s*\])/', '$1$2', $content);
        
        // Fix arrays with missing closing bracket
        if (preg_match_all('/protected\s+\$(\w+)\s*=\s*\[(.*?)(?:\]\s*;|\s*(?:protected|public|private|function))/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $property = $match[1];
                $arrayContent = $match[2];
                
                // If doesn't end with ]; add it
                if (!preg_match('/\]\s*;$/', $match[0])) {
                    $content = str_replace(
                        $match[0],
                        "protected \${$property} = [{$arrayContent}\n    ];{$match[0]}", // Using {$match[0]} to reference what comes after
                        $content
                    );
                }
            }
        }
        
        // Fix array indentation and formatting
        if (preg_match_all('/protected\s+\$(\w+)\s*=\s*\[(.*?)\]\s*;/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $property = $match[1];
                $arrayContent = $match[2];
                
                // Clean up and format array content
                $formattedContent = $this->formatArrayContent($arrayContent);
                
                // Replace old content with formatted content
                $content = str_replace(
                    $match[0],
                    "protected \${$property} = [\n{$formattedContent}\n    ];",
                    $content
                );
            }
        }
        
        // Save fixed content
        $this->files->put($filePath, $content);
    }

    /**
     * Format array content for proper indentation
     * 
     * @param string $content The array content without brackets
     * @return string Formatted content
     */
    protected function formatArrayContent(string $content): string {
        // Split into items
        $items = preg_split('/\s*,\s*/', trim($content));
        $formattedItems = [];
        
        foreach ($items as $item) {
            $item = trim($item);
            if (empty($item)) continue;
            
            // Format array item with proper indentation
            $formattedItems[] = "        {$item}";
        }
        
        return implode(",\n", $formattedItems);
    }

    /**
     * Fix model SoftDeletes inconsistencies based on database table structure.
     *
     * @param array $inconsistencies
     * @return void
     */
    protected function fixModelSoftDeletesConsistencies(array $inconsistencies): void {
        foreach ($inconsistencies as $issue) {
            $modelName = $issue['model'];
            $modelPath = app_path('Models/' . $modelName . '.php');
            
            if (!$this->files->exists($modelPath)) {
                $this->error("Model file not found: {$modelPath}");
                continue;
            }
            
            $content = $this->files->get($modelPath);
            
            if ($issue['fix_type'] === 'remove_soft_deletes_from_model') {
                // Remove SoftDeletes from model
                $this->removeSoftDeletesFromModel($modelName, $content, $modelPath);
            } elseif ($issue['fix_type'] === 'add_soft_deletes_to_model') {
                // Add SoftDeletes to model
                $this->addSoftDeletesToModel($modelName, $content, $modelPath);
            }
        }
    }

    /**
     * Remove SoftDeletes trait from model.
     *
     * @param string $modelName
     * @param string $content
     * @param string $modelPath
     * @return void
     */
    protected function removeSoftDeletesFromModel(string $modelName, string $content, string $modelPath): void {
        // Remove use Illuminate\Database\Eloquent\SoftDeletes;
        $content = preg_replace('/use\s+Illuminate\\\\Database\\\\Eloquent\\\\SoftDeletes\s*;(\r?\n)?/i', '', $content);
        
        // Remove use SoftDeletes;
        $content = preg_replace('/\s*use\s+SoftDeletes\s*;(\r?\n)?/i', '', $content);
        
        // Remove use statements that include SoftDeletes with other traits
        $content = preg_replace('/use\s+.*?SoftDeletes.*?;(\r?\n)?/i', '', $content);
        
        // Remove deleted_at from $guarded array
        $content = preg_replace("/'deleted_at'\s*,?\s*/", '', $content);
        $content = preg_replace("/,\s*'deleted_at'/", '', $content);
        
        // Enhanced pattern to fix any incomplete $casts array entries with missing keys
        // This catches cases like: => 'datetime' without a key
        $content = preg_replace("/,\s*=>\s*['\"]\w+['\"](\s*,|\s*\])/", '$1', $content);
        $content = preg_replace("/,\s*=>\s*['\"]\w+['\"](\s*;)/", '$1', $content);
        $content = preg_replace("/(\[\s*)=>\s*['\"]\w+['\"](\s*,|\s*\])/", '$1$2', $content);
        
        // Remove deleted_at from $casts array
        $content = preg_replace("/'deleted_at'\s*=>\s*'datetime'\s*,?\s*/", '', $content);
        $content = preg_replace("/,\s*'deleted_at'\s*=>\s*'datetime'/", '', $content);
        
        // Clean up empty arrays or trailing commas in arrays
        $content = preg_replace('/\[\s*,/m', '[', $content);
        $content = preg_replace('/,\s*\]/m', ']', $content);
        
        // Fix array formatting - detect property indentation and format closing bracket
        preg_match_all('/^(\s+)protected\s+\$(?:guarded|casts)/m', $content, $matches);
        if (!empty($matches[1][0])) {
            $indent = $matches[1][0];
            
            // Format array with consistent indentation
            $content = preg_replace('/(\s+\'[^\']+\'\s*(?:=>|,).*?)(\s*\]\s*;)/sm', "$1\n{$indent}];", $content);
            
            // Remove empty lines before closing bracket
            $content = preg_replace('/\n\s*\n(\s+)\]/m', "\n$1]", $content);
        }
        
        // Syntax validation for arrays
        if (preg_match_all('/protected\s+\$(\w+)\s*=\s*\[(.*?)\]\s*;/s', $content, $arrayMatches, PREG_SET_ORDER)) {
            foreach ($arrayMatches as $match) {
                $property = $match[1];
                $arrayContent = $match[2];
                
                // Check for any remaining syntax errors in arrays
                if (preg_match('/=>\s*[\'"][^\'"]+[\'"]\s*$/', $arrayContent)) {
                    // Add trailing comma to the last item in array if missing
                    $content = preg_replace(
                        '/(=>\s*[\'"][^\'"]+[\'"]\s*)(\]\s*;)/', 
                        '$1,$2', 
                        $content
                    );
                }
                
                // Check for orphaned => expressions
                if (preg_match('/=>\s*[\'"][^\'"]+[\'"]/', $arrayContent) && !preg_match('/[\'"][^\'"]+[\'"]\s*=>\s*/', $arrayContent)) {
                    // There's a => value but no key - remove this entry
                    $content = preg_replace(
                        '/(\[\s*|\,\s*)=>\s*[\'"][^\'"]+[\'"](\s*\,|\s*\]\s*;)/', 
                        '$1$2', 
                        $content
                    );
                }
            }
        }
        
        // Save updated model file
        $this->files->put($modelPath, $content);
        $this->info("Removed SoftDeletes from model: {$modelName}");
    }

    /**
     * Add SoftDeletes trait to model.
     *
     * @param string $modelName
     * @param string $content
     * @param string $modelPath
     * @return void
     */
    protected function addSoftDeletesToModel(string $modelName, string $content, string $modelPath): void {
        // Add use statement for SoftDeletes if not present
        if (strpos($content, 'use Illuminate\Database\Eloquent\SoftDeletes;') === false) {
            $pattern = '/(namespace.*?;.*?)(use|\s*class)/s';
            $replacement = '$1use Illuminate\\Database\\Eloquent\\SoftDeletes;' . PHP_EOL . '$2';
            $content = preg_replace($pattern, $replacement, $content);
        }
        
        // Add use SoftDeletes; to class if not present
        if (strpos($content, 'use SoftDeletes;') === false) {
            $pattern = '/(class\s+' . preg_quote($modelName) . '.*?{)/s';
            $replacement = '$1' . PHP_EOL . '    use SoftDeletes;' . PHP_EOL;
            $content = preg_replace($pattern, $replacement, $content);
        }
        
        // Add deleted_at to $guarded array if exists
        if (preg_match('/protected\s+\$guarded\s*=\s*\[(.*?)\]\s*;/s', $content, $matches)) {
            $guarded = $matches[1];
            // Only add if not already present
            if (strpos($guarded, "'deleted_at'") === false) {
                $hasItems = trim($guarded) !== '';
                $newGuarded = trim($guarded);
                if ($hasItems && substr($newGuarded, -1) !== ',') {
                    $newGuarded .= ',';
                }
                $newGuarded .= PHP_EOL . "        'deleted_at'";
                $content = str_replace($guarded, $newGuarded, $content);
            }
        }
        
        // Add deleted_at to $casts array if exists
        if (preg_match('/protected\s+\$casts\s*=\s*\[(.*?)\]\s*;/s', $content, $matches)) {
            $casts = $matches[1];
            // Only add if not already present
            if (strpos($casts, "'deleted_at'") === false) {
                $hasItems = trim($casts) !== '';
                $newCasts = trim($casts);
                if ($hasItems && substr($newCasts, -1) !== ',') {
                    $newCasts .= ',';
                }
                $newCasts .= PHP_EOL . "        'deleted_at' => 'datetime'";
                $content = str_replace($casts, $newCasts, $content);
            }
        }
        
        // Save updated model file
        $this->files->put($modelPath, $content);
        $this->info("Added SoftDeletes to model: {$modelName}");
    }

    /**
     * Extract table name from model content.
     *
     * @param string $content
     * @param string $modelName
     * @return string
     */
    protected function getTableNameFromModel(string $content, string $modelName): string {
        // Check if table name is explicitly defined
        if (preg_match('/protected\s+\$table\s*=\s*[\'"]([a-zA-Z0-9_]+)[\'"]/i', $content, $matches)) {
            return $matches[1];
        }
        
        // Otherwise, use plural form of model name
        return Str::plural(Str::snake($modelName));
    }

    /**
     * Check if a table has a deleted_at column.
     *
     * @param string $tableName
     * @return bool
     */
    protected function tableHasDeletedAtColumn(string $tableName): bool {
        // Ensure the table exists
        if (!Schema::hasTable($tableName)) {
            return false;
        }
        
        return Schema::hasColumn($tableName, 'deleted_at');
    }
    
    /**
     * Synchronize factories based on migrations.
     *
     * @return int
     */
    protected function syncFactories(): int {
        $migrations = $this->getMigrationFiles();
        
        if (empty($migrations)) {
            $this->warn('No migration files found to synchronize factories.');
            return Command::FAILURE;
        }
        
        $factoriesPath = database_path('factories');
        
        // Create factories directory if it doesn't exist
        if (!$this->files->isDirectory($factoriesPath)) {
            $this->files->makeDirectory($factoriesPath, 0755, true);
            $this->info("Created factories directory: {$factoriesPath}");
        }
        
        $factoryStubPath = $this->getFactoryStubPath();
        
        $factoriesCreated = 0;
        $factoriesUpdated = 0;
        $factoriesSkipped = 0;
        
        // Ask for batch confirmation if --force is not set and there are existing factories
        $alwaysOverwrite = $this->option('force');
        $neverOverwrite = false;
        $askForEach = !$alwaysOverwrite;
        
        // Check how many existing factories would need to be overwritten
        $existingFactories = [];
        foreach ($migrations as $tableName => $columns) {
            $modelName = Str::studly(Str::singular($tableName));
            $factoryPath = $factoriesPath . '/' . $modelName . 'Factory.php';
            
            if ($this->files->exists($factoryPath)) {
                $existingFactories[] = $modelName;
            }
        }
        
        // Build dependency map from migrations
        $this->buildDependencyMap($migrations);
        
        // If there are existing factories and not using --force, ask for batch confirmation
        if (!empty($existingFactories) && !$alwaysOverwrite) {
            $this->line("Found " . count($existingFactories) . " existing factories that could be overwritten:");
            foreach ($existingFactories as $index => $model) {
                $this->line(" - " . ($index + 1) . ". {$model}Factory.php");
            }
            
            $batchChoice = $this->choice(
                'How would you like to handle existing factories?',
                [
                    'ask' => 'Ask for each factory',
                    'all' => 'Overwrite all',
                    'none' => 'Skip all existing factories',
                ],
                'ask'
            );
            
            if ($batchChoice === 'all') {
                $alwaysOverwrite = true;
                $askForEach = false;
            } elseif ($batchChoice === 'none') {
                $neverOverwrite = true;
                $askForEach = false;
            }
        }
        
        foreach ($migrations as $tableName => $columns) {
            // Generate model name from table name (singular)
            $modelName = Str::studly(Str::singular($tableName));
            $factoryPath = $factoriesPath . '/' . $modelName . 'Factory.php';
            
            // Check if factory already exists
            if ($this->files->exists($factoryPath)) {
                if ($neverOverwrite) {
                    $this->line("<fg=yellow>Skipping</> factory for {$modelName} (exists)");
                    $factoriesSkipped++;
                    $this->modelsWithFactories[] = $modelName; // Still include in seeders
                    continue;
                }
                
                if (!$alwaysOverwrite && $askForEach) {
                    $this->line("\nExisting factory for <fg=green>{$modelName}</> found.");
                    
                    if (!$this->confirm("Do you want to overwrite the existing factory for {$modelName}?", false)) {
                        $this->line("<fg=yellow>Skipping</> factory for {$modelName}");
                        $factoriesSkipped++;
                        $this->modelsWithFactories[] = $modelName; // Still include in seeders
                        continue;
                    }
                }
                
                // Create new factory or update existing one
                $factoryContent = $this->generateFactoryContent($modelName, $columns, $factoryStubPath);
                $this->files->put($factoryPath, $factoryContent);
                
                $factoriesUpdated++;
                $this->modelsWithFactories[] = $modelName;
                $this->info("<fg=green>Updated</> factory for {$modelName}");
            } else {
                // Create new factory
                $factoryContent = $this->generateFactoryContent($modelName, $columns, $factoryStubPath);
                $this->files->put($factoryPath, $factoryContent);
                
                $factoriesCreated++;
                $this->modelsWithFactories[] = $modelName;
                $this->info("<fg=green>Created</> factory for {$modelName}");
            }
        }
        
        $this->newLine();
        $this->info("<fg=blue>Factory synchronization summary:</>");
        $this->info("<fg=green>- Created:</> {$factoriesCreated}");
        $this->info("<fg=green>- Updated:</> {$factoriesUpdated}");
        $this->info("<fg=yellow>- Skipped:</> {$factoriesSkipped}");
        $this->info("<fg=blue>- Total models with factories:</> " . count($this->modelsWithFactories));
        
        return Command::SUCCESS;
    }
    
    /**
     * Build a dependency map from migrations to assist with sorting.
     *
     * @param array $migrations
     * @return void
     */
    protected function buildDependencyMap(array $migrations): void {
        $this->modelDependencies = [];
        
        foreach ($migrations as $tableName => $columns) {
            $modelName = Str::studly(Str::singular($tableName));
            $dependencies = [];
            
            foreach ($columns as $columnName => $columnDetails) {
                if ($columnDetails['is_foreign'] ?? false) {
                    $foreignTable = $columnDetails['foreign_table'] ?? '';
                    if (!empty($foreignTable)) {
                        $foreignModel = Str::studly(Str::singular($foreignTable));
                        $dependencies[] = $foreignModel;
                    }
                }
            }
            
            $this->modelDependencies[$modelName] = array_unique($dependencies);
        }
        
        // Log dependency map if verbose
        if ($this->option('verbose')) {
            $this->info('Model dependency map:');
            foreach ($this->modelDependencies as $model => $dependencies) {
                $this->line("  - {$model} depends on: " . implode(', ', $dependencies ?: ['none']));
            }
        }
    }
    
    /**
     * Synchronize seeders from factories.
     *
     * @return int
     */
    protected function syncSeeders(): int {
        if (empty($this->modelsWithFactories)) {
            $this->warn('No models with factories found to create seeders for.');
            return Command::FAILURE;
        }
        
        $seedersPath = database_path('seeders');
        
        // Create seeders directory if it doesn't exist
        if (!$this->files->isDirectory($seedersPath)) {
            $this->files->makeDirectory($seedersPath, 0755, true);
            $this->info("Created seeders directory: {$seedersPath}");
        }
        
        $seederStubPath = $this->getSeederStubPath();
        $databaseSeederStubPath = $this->getDatabaseSeederStubPath();
        
        $seedersCreated = 0;
        $seedersUpdated = 0;
        $seedersSkipped = 0;
        
        // Ask for batch confirmation if --force is not set
        $alwaysOverwrite = $this->option('force');
        $neverOverwrite = false;
        $askForEach = !$alwaysOverwrite;
        
        // Check how many existing seeders would need to be overwritten
        $existingSeeders = [];
        foreach ($this->modelsWithFactories as $model) {
            $seederPath = $seedersPath . '/' . $model . 'Seeder.php';
            
            if ($this->files->exists($seederPath)) {
                $existingSeeders[] = $model;
            }
        }
        
        // If there are existing seeders and not using --force, ask for batch confirmation
        if (!empty($existingSeeders) && !$alwaysOverwrite) {
            $this->line("Found " . count($existingSeeders) . " existing seeders that could be overwritten:");
            foreach ($existingSeeders as $index => $model) {
                $this->line(" - " . ($index + 1) . ". {$model}Seeder.php");
            }
            
            $batchChoice = $this->choice(
                'How would you like to handle existing seeders?',
                [
                    'ask' => 'Ask for each seeder',
                    'all' => 'Overwrite all',
                    'none' => 'Skip all existing seeders',
                ],
                'ask'
            );
            
            if ($batchChoice === 'all') {
                $alwaysOverwrite = true;
                $askForEach = false;
            } elseif ($batchChoice === 'none') {
                $neverOverwrite = true;
                $askForEach = false;
            }
        }
        
        // Sort models by dependency for proper seeding order
        $sortedModels = $this->sortModelsByDependency($this->modelsWithFactories);
        
        // Store models with created/updated seeders for DatabaseSeeder
        $modelsWithSeeders = [];
        
        foreach ($sortedModels as $model) {
            $seederPath = $seedersPath . '/' . $model . 'Seeder.php';
            
            // Check if seeder already exists
            if ($this->files->exists($seederPath)) {
                if ($neverOverwrite) {
                    $this->line("<fg=yellow>Skipping</> seeder for {$model} (exists)");
                    $seedersSkipped++;
                    $modelsWithSeeders[] = $model; // Still include in DatabaseSeeder
                    continue;
                }
                
                if (!$alwaysOverwrite && $askForEach) {
                    $this->line("\nExisting seeder for <fg=green>{$model}</> found.");
                    
                    if (!$this->confirm("Do you want to overwrite the existing seeder for {$model}?", false)) {
                        $this->line("<fg=yellow>Skipping</> seeder for {$model}");
                        $seedersSkipped++;
                        $modelsWithSeeders[] = $model; // Still include in DatabaseSeeder
                        continue;
                    }
                }
                
                // Create new seeder or update existing one
                $seederContent = $this->generateSeederContent($model, $seederStubPath);
                $this->files->put($seederPath, $seederContent);
                
                $seedersUpdated++;
                $modelsWithSeeders[] = $model;
                $this->info("<fg=green>Updated</> seeder for {$model}");
            } else {
                // Create new seeder
                $seederContent = $this->generateSeederContent($model, $seederStubPath);
                $this->files->put($seederPath, $seederContent);
                
                $seedersCreated++;
                $modelsWithSeeders[] = $model;
                $this->info("<fg=green>Created</> seeder for {$model}");
            }
        }
        
        // Update DatabaseSeeder
        if (!empty($modelsWithSeeders)) {
            $this->updateDatabaseSeeder($modelsWithSeeders, $databaseSeederStubPath);
        }
        
        $this->newLine();
        $this->info("<fg=blue>Seeder synchronization summary:</>");
        $this->info("<fg=green>- Created:</> {$seedersCreated}");
        $this->info("<fg=green>- Updated:</> {$seedersUpdated}");
        $this->info("<fg=yellow>- Skipped:</> {$seedersSkipped}");
        $this->info("<fg=blue>- Total models with seeders:</> " . count($modelsWithSeeders));
        
        return Command::SUCCESS;
    }
    
    /**
     * Get the path to the factory stub file.
     *
     * @return string
     */
    protected function getFactoryStubPath(): string {
        $stubPath = base_path('packages/lac/stubs/factory.stub');
        
        if (!$this->files->exists($stubPath)) {
            $this->error("Factory stub file not found: {$stubPath}");
            $this->error("Please ensure the stub file exists at the correct location.");
            exit(1);
        }
        
        return $stubPath;
    }
    
    /**
     * Get the path to the seeder stub file.
     *
     * @return string
     */
    protected function getSeederStubPath(): string {
        $stubPath = base_path('packages/lac/stubs/seeder.stub');
        
        if (!$this->files->exists($stubPath)) {
            $this->error("Seeder stub file not found: {$stubPath}");
            $this->error("Please ensure the stub file exists at the correct location.");
            exit(1);
        }
        
        return $stubPath;
    }
    
    /**
     * Get the path to the database seeder stub file.
     *
     * @return string
     */
    protected function getDatabaseSeederStubPath(): string {
        $stubPath = base_path('packages/lac/stubs/database-seeder.stub');
        
        if (!$this->files->exists($stubPath)) {
            $this->error("Database seeder stub file not found: {$stubPath}");
            $this->error("Please ensure the stub file exists at the correct location.");
            exit(1);
        }
        
        return $stubPath;
    }
    
    /**
     * Get migration files and parse their column definitions.
     *
     * @return array
     */
    protected function getMigrationFiles(): array {
        $migrationsPath = database_path('migrations');
        
        if (!$this->files->isDirectory($migrationsPath)) {
            $this->error("Migrations directory not found: {$migrationsPath}");
            return [];
        }
        
        $migrationFiles = $this->files->glob($migrationsPath . '/*.php');
        $tables = [];
        
        foreach ($migrationFiles as $file) {
            $content = $this->files->get($file);
            $fileName = basename($file);
            
            // Only process create_*_table migrations
            if (preg_match('/^\d+_\d+_\d+_\d+_create_(.+)_table\.php$/', $fileName, $matches)) {
                $tableName = $matches[1];
                
                // Parse column definitions
                $columns = $this->parseColumnDefinitions($content, $tableName);
                
                if (!empty($columns)) {
                    $tables[$tableName] = $columns;
                    $this->line("Found table definition: <fg=green>{$tableName}</> with <fg=green>" . count($columns) . "</> columns");
                }
            }
        }
        
        return $tables;
    }
    
    /**
     * Parse column definitions from migration content.
     *
     * @param string $content
     * @param string $tableName
     * @return array
     */
    protected function parseColumnDefinitions(string $content, string $tableName): array {
        $columns = [];
        
        // Extract the Schema::create block
        if (preg_match('/Schema::create\s*\(\s*[\'"]' . preg_quote($tableName, '/') . '[\'"].*?{(.*?)}\s*\)\s*;/s', $content, $matches)) {
            $schemaBlock = $matches[1];
            
            // Extract each column definition
            preg_match_all('/\$table->([a-zA-Z]+)\s*\(\s*[\'"]([a-zA-Z0-9_]+)[\'"].*?\)/', $schemaBlock, $columnMatches, PREG_SET_ORDER);
            
            foreach ($columnMatches as $match) {
                $type = $match[1];
                $name = $match[2];
                
                // Skip standard columns
                if (in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                    continue;
                }
                
                // Check for foreign keys (assumed to be *_id pattern)
                if (preg_match('/_id$/', $name)) {
                    // Foreign key: check if there's a specific foreign method
                    $foreignTable = Str::plural(substr($name, 0, -3));
                    
                    // Check for constraints to get actual table name
                    if (preg_match('/foreign\([\'"]' . preg_quote($name, '/') . '[\'"]\).*?references\([\'"]id[\'"]\).*?on\([\'"]([a-zA-Z0-9_]+)[\'"]\)/s', $schemaBlock, $foreignMatches)) {
                        $foreignTable = $foreignMatches[1];
                    }
                    
                    // Check for enum values
                    $enumValues = [];
                    if ($type === 'enum') {
                        if (preg_match('/enum\s*\(\s*[\'"]' . preg_quote($name, '/') . '[\'"],\s*\[(.*?)\]/s', $schemaBlock, $enumMatch)) {
                            $enumValuesRaw = $enumMatch[1];
                            preg_match_all('/[\'"]([^\'"]+)[\'"]/', $enumValuesRaw, $enumValuesMatches);
                            $enumValues = $enumValuesMatches[1];
                        }
                    }
                    
                    // Check for nullable
                    $nullable = preg_match('/[\'"]' . preg_quote($name, '/') . '[\'"].*?nullable\s*\(/s', $schemaBlock) ||
                              preg_match('/->([a-zA-Z]+)\s*\(\s*[\'"]' . preg_quote($name, '/') . '[\'"].*?\)->nullable\s*\(/s', $schemaBlock);
                    
                    $columns[$name] = [
                        'type' => $type,
                        'nullable' => $nullable,
                        'enum_values' => $enumValues,
                        'is_foreign' => true,
                        'foreign_table' => $foreignTable,
                    ];
                } else {
                    // Regular column
                    // Check for enum values
                    $enumValues = [];
                    if ($type === 'enum') {
                        if (preg_match('/enum\s*\(\s*[\'"]' . preg_quote($name, '/') . '[\'"],\s*\[(.*?)\]/s', $schemaBlock, $enumMatch)) {
                            $enumValuesRaw = $enumMatch[1];
                            preg_match_all('/[\'"]([^\'"]+)[\'"]/', $enumValuesRaw, $enumValuesMatches);
                            $enumValues = $enumValuesMatches[1];
                        }
                    }
                    
                    // Check for nullable
                    $nullable = preg_match('/[\'"]' . preg_quote($name, '/') . '[\'"].*?nullable\s*\(/s', $schemaBlock) ||
                              preg_match('/->([a-zA-Z]+)\s*\(\s*[\'"]' . preg_quote($name, '/') . '[\'"].*?\)->nullable\s*\(/s', $schemaBlock);
                    
                    $columns[$name] = [
                        'type' => $type,
                        'nullable' => $nullable,
                        'enum_values' => $enumValues,
                        'is_foreign' => false,
                    ];
                }
            }
        }
        
        return $columns;
    }
    
    /**
     * Generate factory content based on model name and columns.
     *
     * @param string $modelName
     * @param array $columns
     * @param string $stubPath
     * @return string
     */
    protected function generateFactoryContent(string $modelName, array $columns, string $stubPath): string {
        $stub = $this->files->get($stubPath);
        
        // Generate factory fields
        $factoryFields = $this->generateFactoryFields($modelName, $columns);
        
        // Create a copy of the stub for processing
        $content = $stub;
        
        // Replace all placeholders in the content
        $replacements = [
            '{{ namespace }}' => 'Database\\Factories',
            '{{ modelNamespace }}' => 'App\\Models',
            '{{ model }}' => $modelName,
            '{{ class }}' => $modelName . 'Factory',
        ];
        
        foreach ($replacements as $placeholder => $value) {
            $content = str_replace($placeholder, $value, $content);
        }
        
        // Replace the remaining {{ model }} that might be in property definitions
        // This is important for lines like: protected $model = {{ model }}::class;
        $content = str_replace('{{ model }}', $modelName, $content);
        
        // Handle the factory fields - either replace the placeholder or the entire return array
        if (strpos($stub, '{{ fakerFields }}') !== false) {
            // Template uses placeholder - replace it
            $content = str_replace('{{ fakerFields }}', $factoryFields, $content);
        } else {
            // Template has hard-coded fields - replace the entire array block
            $pattern = '/return\s*\[\s*.*?\s*\];/s';
            $replacement = "return [\n{$factoryFields}\n        ];";
            $content = preg_replace($pattern, $replacement, $content);
        }
        
        // モデルにHasFactoryトレイトが含まれていることを確認
        $this->ensureModelHasFactoryTrait($modelName);
        
        return $content;
    }

    /**
     * Ensure that the model has the HasFactory trait
     *
     * @param string $modelName
     * @return void
     */
    protected function ensureModelHasFactoryTrait(string $modelName): void {
        $modelPath = app_path('Models/' . $modelName . '.php');
        
        if (!$this->files->exists($modelPath)) {
            $this->warn("Model file not found: {$modelPath}");
            return;
        }
        
        $content = $this->files->get($modelPath);
        
        // Check if HasFactory trait is imported
        $hasImport = strpos($content, 'use Illuminate\Database\Eloquent\Factories\HasFactory;') !== false;
        
        // Check if HasFactory trait is used in the class
        $usesTrait = preg_match('/\s+use\s+HasFactory\s*;/m', $content);
        
        // Check and add both import and usage if needed
        if (!$hasImport || !$usesTrait) {
            $this->warn("Adding HasFactory trait to {$modelName} model...");
            
            // Add import if missing
            if (!$hasImport) {
                $pattern = '/(namespace.*?;.*?)(use|\s*class)/s';
                $replacement = '$1use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;' . PHP_EOL . '$2';
                $content = preg_replace($pattern, $replacement, $content);
            }
            
            // Add trait usage if missing
            if (!$usesTrait) {
                $pattern = '/(class\s+' . preg_quote($modelName) . '.*?{)/s';
                $replacement = '$1' . PHP_EOL . '    use HasFactory; // Added automatically by factory generator' . PHP_EOL;
                $content = preg_replace($pattern, $replacement, $content);
            }
            
            // Save the modified file
            $this->files->put($modelPath, $content);
            $this->info("<fg=green>Updated</> {$modelName} model with HasFactory trait");
        }
    }
    
    /**
     * Generate factory field definitions based on columns.
     *
     * @param string $currentModel
     * @param array $columns
     * @return string
     */
    protected function generateFactoryFields(string $currentModel, array $columns): string {
        $fields = [];
        
        // 強制的に日本語モードを有効化
        $isJapanese = true;
        
        // デバッグ情報
        if ($this->option('verbose')) {
            $this->line("Generating factory fields with Japanese mode: " . ($isJapanese ? 'ON' : 'OFF'));
            $this->line("Sample name for debug: " . $this->faker->name);
        }
        
        foreach ($columns as $name => $details) {
            $type = $details['type'];
            $nullable = $details['nullable'];
            $enumValues = $details['enum_values'];
            $isForeign = $details['is_foreign'] ?? false;
            $foreignTable = $details['foreign_table'] ?? null;
            
            if ($isForeign) {
                // This is a foreign key, reference an existing model
                $relatedModel = Str::studly(Str::singular($foreignTable));
                
                // Always use existing records when possible to avoid circular dependencies
                if ($nullable) {
                    // For nullable foreign keys, use existing IDs or null
                    $fields[] = "            '{$name}' => function() {
                            try {
                                // Safety check - build query after checking schema
                                \$query = \\App\\Models\\{$relatedModel}::query();
                                
                                // Check if table exists and has deleted_at column
                                \$hasDeletedAt = \\Schema::hasColumn('{$foreignTable}', 'deleted_at');
                                if (\$hasDeletedAt) {
                                    \$query->whereNull('deleted_at');
                                }
                                
                                \$ids = \$query->pluck('id')->toArray();
                                return !empty(\$ids) ? \$this->faker->optional(0.7)->randomElement(\$ids) : null;
                            } catch (\\Exception \$e) {
                                // Return null when error occurs (nullable)
                                return null;
                            }
                        },";
                } else {
                    // For required foreign keys, always use existing IDs when available
                    $fields[] = "            '{$name}' => function() {
                            try {
                                // Safety check - build query after checking schema
                                \$query = \\App\\Models\\{$relatedModel}::query();
                                
                                // Check if table exists and has deleted_at column
                                \$hasDeletedAt = \\Schema::hasColumn('{$foreignTable}', 'deleted_at');
                                if (\$hasDeletedAt) {
                                    \$query->whereNull('deleted_at');
                                }
                                
                                \$ids = \$query->pluck('id')->toArray();
                                return !empty(\$ids) ? \$this->faker->randomElement(\$ids) : \\App\\Models\\{$relatedModel}::factory()->create()->id;
                            } catch (\\Exception \$e) {
                                // Create new model when error occurs
                                return \\App\\Models\\{$relatedModel}::factory()->create()->id;
                            }
                        },";
                }
            } else {
                // Regular field - 強制的に日本語パターンを優先
                $fakerMethod = $this->getFakerMethodForColumn($name, $type, $enumValues, $isJapanese);
                
                // Add nullable handling if needed
                if ($nullable) {
                    $fields[] = "            '{$name}' => \$this->faker->optional(0.8)->randomElement([null, {$fakerMethod}]),";
                } else {
                    $fields[] = "            '{$name}' => {$fakerMethod},";
                }
            }
        }
        
        return implode("\n", $fields);
    }

    /**
     * Get the appropriate faker method for a given column.
     *
     * @param string $name
     * @param string $type
     * @param array $enumValues
     * @param bool $isJapanese
     * @return string
     */
    protected function getFakerMethodForColumn(string $name, string $type, array $enumValues = [], bool $isJapanese = false): string {
        // 特定のカラム名に対しては、タイプに関わらず日本語データを生成
        
        // タイトル関連（文章・タイトル）
        if (preg_match('/(title|題名|タイトル|見出し)/i', $name)) {
            return '$this->faker->realText(30)';
        }
        
        // コンテンツ関連（内容・テキスト）
        if (preg_match('/(content|text|description|内容|説明|詳細|コンテンツ)/i', $name)) {
            return '$this->faker->realText(200)';
        }
        
        // 名前関連
        if (preg_match('/(name|氏名|名前)/i', $name)) {
            return '$this->faker->name()';
        }
        
        // メールアドレス
        if (preg_match('/(email|mail|メール)/i', $name)) {
            return '$this->faker->safeEmail()';
        }
        
        // 住所関連
        if (preg_match('/(address|住所)/i', $name)) {
            return '$this->faker->address()';
        }
        
        // 電話番号
        if (preg_match('/(phone|tel|電話|携帯)/i', $name)) {
            return '$this->faker->phoneNumber()';
        }
        
        // 会社名
        if (preg_match('/(company|会社|企業)/i', $name)) {
            return '$this->faker->company()';
        }
        
        // 日付・時間
        if (preg_match('/(date|time|日付|時間)/i', $name)) {
            return '$this->faker->dateTimeThisYear()->format(\'Y-m-d H:i:s\')';
        }
        
        // ここからは通常の型ベースの判定
        // Normalize type
        $type = Str::camel($type);
        
        // If we don't have a mapping for this type, use text as fallback
        if (!isset($this->typeMap[$type])) {
            $type = 'string';
        }
        
        // Check if we have a specific method for this column name
        foreach ($this->typeMap[$type] as $namePattern => $method) {
            if ($namePattern === 'default') {
                continue;
            }
            
            if (strpos($name, $namePattern) !== false) {
                // Handle enum values
                if ($type === 'enum' && !empty($enumValues)) {
                    $enumValuesString = "['" . implode("', '", $enumValues) . "']";
                    $method = str_replace('{ENUM_VALUES}', $enumValuesString, $method);
                }
                
                return $method;
            }
        }
        
        // 日本語モードが有効で文字列型の場合は、デフォルトでリアルテキストを使用
        if ($isJapanese && ($type === 'string' || $type === 'text')) {
            return '$this->faker->realText(100)';
        }
        
        // Use default method for this type
        $method = $this->typeMap[$type]['default'];
        
        // Handle enum values
        if ($type === 'enum' && !empty($enumValues)) {
            $enumValuesString = "['" . implode("', '", $enumValues) . "']";
            $method = str_replace('{ENUM_VALUES}', $enumValuesString, $method);
        }
        
        return $method;
    }
        
    /**
     * Check if creating a factory dependency would create a circular reference.
     *
     * @param string $model
     * @param string $dependency
     * @return bool
     */
    protected function wouldCreateCircularDependency(string $model, string $dependency): bool {
        // If dependency directly depends on the current model, it's a circular reference
        if (in_array($model, $this->modelDependencies[$dependency] ?? [])) {
            return true;
        }
        
        // Check for indirect circular dependencies
        $visited = [$model];
        $queue = [$dependency];
        
        while (!empty($queue)) {
            $current = array_shift($queue);
            
            if (in_array($current, $visited)) {
                continue;
            }
            
            $visited[] = $current;
            
            foreach ($this->modelDependencies[$current] ?? [] as $childDependency) {
                if ($childDependency === $model) {
                    return true;
                }
                
                if (!in_array($childDependency, $visited)) {
                    $queue[] = $childDependency;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Sort models by dependency (to ensure proper seeding order).
     *
     * @param array $models
     * @return array
     */
    protected function sortModelsByDependency(array $models): array {
        // Define model dependencies (models that should be seeded first)
        $commonBaseModels = ['User', 'Role', 'Permission', 'Category', 'Tag'];
        
        // Create a new array with prioritized models first
        $sortedModels = [];
        $remainingModels = $models;
        
        // First add base models that exist in our models array
        foreach ($commonBaseModels as $baseModel) {
            $key = array_search($baseModel, $remainingModels);
            if ($key !== false) {
                $sortedModels[] = $baseModel;
                unset($remainingModels[$key]);
            }
        }
        
        // Then add remaining models based on their dependencies
        $this->addModelsInDependencyOrder($remainingModels, $sortedModels);
        
        // If we have any missing models, add them at the end
        foreach (array_diff($models, $sortedModels) as $model) {
            $sortedModels[] = $model;
        }
        
        // Log the sorted models if verbose
        if ($this->option('verbose')) {
            $this->info('Models sorted by dependency:');
            foreach ($sortedModels as $index => $model) {
                $this->line("  " . ($index + 1) . ". {$model}");
            }
        }
        
        return $sortedModels;
    }
    
    /**
     * Add models to sorted array in order of dependencies
     *
     * @param array $models Models to sort
     * @param array &$sortedModels Reference to sorted models array
     * @param array $visitedModels Visited models to detect cycles
     * @return void
     */
    protected function addModelsInDependencyOrder(array $models, array &$sortedModels, array $visitedModels = []): void {
        foreach ($models as $model) {
            // Skip if already in sorted list or currently being visited (cycle detection)
            if (in_array($model, $sortedModels) || in_array($model, $visitedModels)) {
                continue;
            }
            
            $visitedModels[] = $model;
            $dependencies = $this->modelDependencies[$model] ?? [];
            
            // First, add all dependencies
            $pendingDeps = array_diff($dependencies, $sortedModels, $visitedModels);
            if (!empty($pendingDeps)) {
                $this->addModelsInDependencyOrder($pendingDeps, $sortedModels, $visitedModels);
            }
            
            // Then add the model if not already added
            if (!in_array($model, $sortedModels)) {
                $sortedModels[] = $model;
            }
        }
    }
    
    /**
     * Generate seeder content for a model.
     *
     * @param string $model
     * @param string $stubPath
     * @return string
     */
    protected function generateSeederContent(string $model, string $stubPath): string {
        $stub = $this->files->get($stubPath);
        
        // Create a copy of the stub for processing
        $content = $stub;
        
        // Get record count from config or default to 10
        $count = Config::get('app.seeder_count', 10);
        
        // Get model variable name (camelCase version of model name)
        $modelVariable = lcfirst($model);
        
        // Replace placeholders in stub
        $replacements = [
            '{{ model }}' => $model,
            '{{ class }}' => $model . 'Seeder',
            '{{ modelVariable }}' => $modelVariable,
            '{{ count }}' => $count,
        ];
        
        foreach ($replacements as $placeholder => $value) {
            $content = str_replace($placeholder, $value, $content);
        }
        
        // Make sure variable declaration is correct
        $pattern = "/\s+{$modelVariable}Count = \d+;/";
        $replacement = "        \${$modelVariable}Count = {$count};";
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $replacement, $content);
        }
        
        // Replace hardcoded factory count
        $pattern = "/{$model}::factory\(\d+\)/";
        $replacement = "{$model}::factory({$count})";
        $content = preg_replace($pattern, $replacement, $content);
        
        return $content;
    }
    
    /**
     * Update the DatabaseSeeder.php file with model seeders.
     *
     * @param array $models
     * @param string $databaseSeederStubPath
     * @return void
     */
    protected function updateDatabaseSeeder(array $models, string $databaseSeederStubPath): void {
        $databaseSeederPath = database_path('seeders/DatabaseSeeder.php');
        
        // If DatabaseSeeder stub exists, use it as a template
        $stub = $this->files->get($databaseSeederStubPath);
        
        // Generate seeder calls
        $seederCalls = '';
        foreach ($models as $model) {
            $seederCalls .= "            {$model}Seeder::class,\n";
        }
        
        // Replace placeholder in stub
        $content = str_replace('{{ seederCalls }}', $seederCalls, $stub);
        
        // If DatabaseSeeder already exists, ask for confirmation before overwriting
        if ($this->files->exists($databaseSeederPath)) {
            if (!$this->option('force') && !$this->confirm("DatabaseSeeder already exists. Do you want to overwrite it?", false)) {
                $this->line("<fg=yellow>Skipping</> DatabaseSeeder update");
                return;
            }
        }
        
        // Create or update DatabaseSeeder
        $this->files->put($databaseSeederPath, $content);
        $this->info("<fg=green>Updated</> DatabaseSeeder with " . count($models) . " seeders");
    }
}