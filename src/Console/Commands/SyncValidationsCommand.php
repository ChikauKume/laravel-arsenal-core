<?php

namespace Lac\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SyncValidationsCommand extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lac:sync-validations
                            {--force : Overwrite existing rules without confirmation}
                            {--tables= : Comma-separated list of specific tables to process}
                            {--type=both : Request type to sync (store, update, both)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize validation rules in request classes based on database schema';

    /**
     * The filesystem instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;

    /**
     * Column types that should be excluded from validation rules.
     *
     * @var array
     */
    protected $excludedColumns = [
        'id', 'created_at', 'updated_at', 'deleted_at'
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
        $this->info('Starting Validation Rules Synchronization...');

        try {
            // Parse migrations to get tables
            $tables = $this->getTablesToProcess();
            
            if (empty($tables)) {
                $this->warn('No tables found for rule generation.');
                return Command::SUCCESS;
            }

            $bar = $this->output->createProgressBar(count($tables));
            $bar->start();

            $processedCount = 0;
            $errorCount = 0;

            foreach ($tables as $table) {
                try {
                    $this->processTable($table);
                    $processedCount++;
                } catch (\Exception $e) {
                    $this->newLine();
                    $this->error("Error processing table {$table}: " . $e->getMessage());
                    if ($this->option('verbose')) {
                        $this->error($e->getTraceAsString());
                    }
                    $errorCount++;
                }
                
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            $this->info("Validation Rules Synchronization completed:");
            $this->info("- Processed tables: {$processedCount}");
            
            if ($errorCount > 0) {
                $this->warn("- Tables with errors: {$errorCount}");
                return Command::FAILURE;
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error during validation synchronization: ' . $e->getMessage());
            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    /**
     * Get the list of tables to process.
     *
     * @return array
     */
    protected function getTablesToProcess() {
        // Check if specific tables were requested
        $specifiedTables = $this->option('tables');
        if (!empty($specifiedTables)) {
            return explode(',', $specifiedTables);
        }

        // Use the migrations directory to find table names
        $migrationFiles = $this->files->glob(database_path('migrations/*.php'));
        $tables = [];
        
        foreach ($migrationFiles as $file) {
            $content = $this->files->get($file);
            
            // Extract table names from Schema::create statements
            if (preg_match_all('/Schema::create\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,/s', $content, $matches)) {
                foreach ($matches[1] as $table) {
                    // Filter out Laravel system tables
                    if (!in_array($table, ['migrations', 'failed_jobs', 'password_resets', 'personal_access_tokens'])) {
                        $tables[] = $table;
                    }
                }
            }
        }
        
        // Remove duplicates and sort
        $tables = array_unique($tables);
        sort($tables);
        
        return $tables;
    }

    /**
     * Process a single table to generate validation rules.
     *
     * @param string $table
     * @return void
     */
    protected function processTable($table) {
        // Convert table name to model name (singular, studly case)
        $modelName = Str::studly(Str::singular($table));
        $this->line("\nProcessing table: {$table} -> Model: {$modelName}");

        // Parse migration files to extract column info for this table
        $columns = $this->getColumnsFromMigrations($table);
        
        if (empty($columns)) {
            $this->warn("No applicable columns found in table {$table}");
            return;
        }

        // Generate validation rules
        $rules = $this->generateValidationRules($columns, $table);
        
        // Update the request files
        $this->updateRequestFiles($modelName, $rules);
    }
    
    /**
     * Extract column information from migration files for a specific table.
     *
     * @param string $tableName
     * @return array
     */
    protected function getColumnsFromMigrations($tableName) {
        $migrationFiles = $this->files->glob(database_path('migrations/*.php'));
        $columnDefinitions = [];
        
        foreach ($migrationFiles as $file) {
            $content = $this->files->get($file);
            
            // Look for create table statements for this specific table
            if (preg_match('/Schema::create\s*\(\s*[\'"]' . preg_quote($tableName, '/') . '[\'"].*?\{(.*?)\}\s*\)\s*;/s', $content, $matches)) {
                $tableDefinition = $matches[1];
                
                // Extract column definitions with method chains
                $lines = explode(';', $tableDefinition);
                foreach ($lines as $line) {
                    // Skip empty lines
                    $line = trim($line);
                    if (empty($line)) {
                        continue;
                    }
                    
                    // Basic column definition
                    if (preg_match('/\$table->(\w+)\s*\(\s*[\'"]([^\'"]+)[\'"](?:,\s*([^)]+))?\)/', $line, $matches)) {
                        $type = $matches[1];
                        $name = $matches[2];
                        $parameters = isset($matches[3]) ? trim($matches[3]) : null;
                        
                        // Skip timestamps and standard columns
                        if ($type === 'timestamps' || $type === 'softDeletes' || 
                            in_array($name, $this->excludedColumns)) {
                            continue;
                        }
                        
                        $columnDefinitions[$name] = [
                            'type' => $type,
                            'required' => true, // Default to required
                            'default' => null,
                            'length' => null,
                            'constraints' => [],
                            'enum_values' => [], // Added: Array to store enum values
                            'decimal_precision' => null,
                            'decimal_scale' => null,
                        ];
                        
                        // Extract string length parameter
                        if ($type === 'string' && !empty($parameters)) {
                            $columnDefinitions[$name]['length'] = (int) trim($parameters);
                        }
                        
                        // Extract char length parameter
                        if ($type === 'char' && !empty($parameters)) {
                            $columnDefinitions[$name]['length'] = (int) trim($parameters);
                        }
                        
                        // Extract decimal precision and scale
                        if (($type === 'decimal' || $type === 'unsignedDecimal') && !empty($parameters)) {
                            if (preg_match('/(\d+)\s*,\s*(\d+)/', $parameters, $decimalMatches)) {
                                $columnDefinitions[$name]['decimal_precision'] = (int) $decimalMatches[1];
                                $columnDefinitions[$name]['decimal_scale'] = (int) $decimalMatches[2];
                            }
                        }
                        
                        // Extract enum values - improved regex for extracting enum values
                        if ($type === 'enum' && !empty($parameters)) {
                            // Array format enum values [val1, val2, ...]
                            if (preg_match('/\[\s*(.*?)\s*\]/', $parameters, $arrayMatch)) {
                                preg_match_all('/[\'"]([^\'"]+)[\'"]/', $arrayMatch[1], $valueMatches);
                                if (!empty($valueMatches[1])) {
                                    $columnDefinitions[$name]['enum_values'] = $valueMatches[1];
                                }
                            } 
                            // Alternative formats also considered
                            elseif (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $parameters, $valueMatches)) {
                                // First value might be the column name, so skip it
                                array_shift($valueMatches[1]);
                                if (!empty($valueMatches[1])) {
                                    $columnDefinitions[$name]['enum_values'] = $valueMatches[1];
                                }
                            }
                        }
                        
                        // Parse method chains
                        $this->parseMethodChains($line, $name, $columnDefinitions);
                    }
                }
                
                // Extract foreign key constraints separately
                $this->extractForeignKeyConstraints($tableDefinition, $columnDefinitions);
            }
        }
        
        return $columnDefinitions;
    }
    
    /**
     * Extract foreign key constraints from table definition.
     *
     * @param string $tableDefinition
     * @param array &$columnDefinitions
     * @return void
     */
    protected function extractForeignKeyConstraints($tableDefinition, &$columnDefinitions) {
        // Look for foreign key constraints
        $pattern = '/\$table->foreign\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)\s*->references\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)\s*->on\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/';
        preg_match_all($pattern, $tableDefinition, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $columnName = $match[1];
            $referencedColumn = $match[2];
            $referencedTable = $match[3];
            
            if (isset($columnDefinitions[$columnName])) {
                $columnDefinitions[$columnName]['constraints'][] = "exists:{$referencedTable},{$referencedColumn}";
            }
        }
    }
    
    /**
     * Parse method chains from a line for a column definition.
     *
     * @param string $line
     * @param string $columnName
     * @param array &$columnDefinitions
     * @return void
     */
    protected function parseMethodChains($line, $columnName, &$columnDefinitions) {
        // Check for nullable()
        if (preg_match('/->nullable\s*\(\s*(?:true|false)?\s*\)/', $line)) {
            $columnDefinitions[$columnName]['required'] = false;
        }
        
        // Check for default()
        if (preg_match('/->default\s*\(\s*(.*?)\s*\)/', $line, $matches)) {
            $columnDefinitions[$columnName]['default'] = trim($matches[1]);
        }
        
        // Check for unique()
        if (preg_match('/->unique\s*\(\s*\)/', $line)) {
            $columnDefinitions[$columnName]['constraints'][] = 'unique';
        }
        
        // Check for min()
        if (preg_match('/->min\s*\(\s*(\d+)\s*\)/', $line, $matches)) {
            $columnDefinitions[$columnName]['constraints'][] = 'min:' . $matches[1];
        }
        
        // Check for max()
        if (preg_match('/->max\s*\(\s*(\d+)\s*\)/', $line, $matches)) {
            $columnDefinitions[$columnName]['constraints'][] = 'max:' . $matches[1];
        }
        
        // Check for unsigned() - only applies to numeric types
        if (preg_match('/->unsigned\s*\(\s*\)/', $line)) {
            $columnDefinitions[$columnName]['constraints'][] = 'min:0';
        }
        
        // Check for index() - this doesn't directly translate to validation but is useful for foreign keys
        if (preg_match('/->index\s*\(\s*\)/', $line)) {
            $columnDefinitions[$columnName]['isIndex'] = true;
        }
        
        // Improved date constraint detection
        // before(now()) pattern - better now() function detection
        if (preg_match('/->before\s*\(\s*now\s*\(\s*\)\s*\)/', $line)) {
            $columnDefinitions[$columnName]['constraints'][] = 'before:now';
        }
        
        // after(now()) pattern
        if (preg_match('/->after\s*\(\s*now\s*\(\s*\)\s*\)/', $line)) {
            $columnDefinitions[$columnName]['constraints'][] = 'after:now';
        }
        
        // Date string before
        if (preg_match('/->before\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $line, $matches)) {
            $date = $matches[1];
            $columnDefinitions[$columnName]['constraints'][] = "before:{$date}";
        }
        
        // Date string after
        if (preg_match('/->after\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $line, $matches)) {
            $date = $matches[1];
            $columnDefinitions[$columnName]['constraints'][] = "after:{$date}";
        }
        
        // Improved regex pattern handling
        if (preg_match('/->regex\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $line, $matches)) {
            $pattern = $matches[1];
            
            // Simplified regex escaping - just save the raw pattern
            if (substr($pattern, 0, 1) === '/' && substr($pattern, -1) === '/') {
                // Pattern already has / delimiters, use as is
                $columnDefinitions[$columnName]['constraints'][] = "regex:" . $pattern;
            } else {
                // Add delimiters
                $columnDefinitions[$columnName]['constraints'][] = "regex:/{$pattern}/";
            }
        }
        
        // Check for email validation by name convention if not already set
        if ($columnName === 'email' && !in_array('email', $columnDefinitions[$columnName]['constraints'])) {
            $columnDefinitions[$columnName]['constraints'][] = 'email';
        }
    }

    /**
     * Generate validation rules based on column details.
     *
     * @param array $columnDetails
     * @param string $tableName
     * @return array
     */
    protected function generateValidationRules($columnDetails, $tableName) {
        $rules = [];

        foreach ($columnDetails as $column => $details) {
            $columnRules = [];

            // Required rule
            if ($details['required']) {
                $columnRules[] = 'required';
            } else {
                $columnRules[] = 'nullable';
            }

            // Type-based rules
            switch ($details['type']) {
                case 'string':
                    $columnRules[] = 'string';
                    if ($details['length'] && !in_array('max:' . $details['length'], $details['constraints'])) {
                        $columnRules[] = 'max:' . $details['length'];
                    }
                    break;
                
                case 'char':
                    $columnRules[] = 'string';
                    if ($details['length']) {
                        $columnRules[] = 'size:' . $details['length'];
                    }
                    break;
                
                case 'integer':
                case 'bigInteger':
                case 'unsignedBigInteger':
                case 'unsignedInteger':
                case 'tinyInteger': // Added: Support for small integer types
                case 'unsignedTinyInteger':
                case 'smallInteger':
                case 'unsignedSmallInteger':
                case 'mediumInteger':
                case 'unsignedMediumInteger':
                    $columnRules[] = 'integer';
                    break;
                
                case 'boolean':
                    $columnRules[] = 'boolean';
                    break;
                
                case 'float':
                case 'double':
                    $columnRules[] = 'numeric';
                    break;
                
                case 'decimal':
                case 'unsignedDecimal':
                    $columnRules[] = 'numeric';
                    // Include decimal precision in validation
                    if (isset($details['decimal_scale']) && $details['decimal_scale'] !== null) {
                        $columnRules[] = "decimal:{$details['decimal_scale']}";
                    }
                    break;
                
                case 'date':
                    $columnRules[] = 'date';
                    break;
                
                case 'dateTime':
                case 'timestamp':
                    $columnRules[] = 'date_format:Y-m-d H:i:s';
                    break;
                
                case 'time':
                    $columnRules[] = 'date_format:H:i:s';
                    break;
                
                case 'year':
                    $columnRules[] = 'integer';
                    $columnRules[] = 'digits:4';
                    break;
                
                case 'json':
                    $columnRules[] = 'json';
                    break;
                
                case 'text':
                case 'mediumText':
                case 'longText':
                    $columnRules[] = 'string';
                    break;
                
                case 'enum':
                    $columnRules[] = 'string';
                    // Add validation rule for enum values
                    if (!empty($details['enum_values'])) {
                        $columnRules[] = 'in:' . implode(',', $details['enum_values']);
                    }
                    break;
                
                case 'ipAddress':
                    $columnRules[] = 'ip';
                    break;
                
                case 'macAddress':
                    $columnRules[] = 'regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/';
                    break;
                
                case 'uuid':
                    $columnRules[] = 'uuid';
                    break;
            }

            // Add constraints from method chains
            foreach ($details['constraints'] as $constraint) {
                // Avoid duplicate min:0 constraints
                if ($constraint === 'min:0' && in_array('min:0', $columnRules)) {
                    continue;
                }
                
                // Resolve conflicting min constraints (prefer higher value)
                if (preg_match('/^min:(\d+)$/', $constraint, $matches)) {
                    $minValue = (int)$matches[1];
                    $hasHigherMin = false;
                    
                    foreach ($columnRules as $i => $existingRule) {
                        if (preg_match('/^min:(\d+)$/', $existingRule, $existingMatches)) {
                            $existingMinValue = (int)$existingMatches[1];
                            if ($existingMinValue > $minValue) {
                                $hasHigherMin = true;
                                break;
                            } elseif ($existingMinValue < $minValue) {
                                // Remove lower value min constraint
                                unset($columnRules[$i]);
                            }
                        }
                    }
                    
                    if (!$hasHigherMin) {
                        $columnRules[] = $constraint;
                    }
                } else {
                    // For other constraints, just check for duplicates
                    if (!in_array($constraint, $columnRules)) {
                        $columnRules[] = $constraint;
                    }
                }
            }

            // Special column name based additional rules
            if (Str::endsWith($column, '_id') && !array_filter($columnRules, function($rule) {
                return Str::startsWith($rule, 'exists:');
            })) {
                // Foreign key constraints
                $referencedTable = Str::plural(Str::beforeLast($column, '_id'));
                $migrationFiles = $this->files->glob(database_path('migrations/*.php'));
                $tableExists = false;
                
                foreach ($migrationFiles as $file) {
                    $content = $this->files->get($file);
                    if (preg_match('/Schema::create\s*\(\s*[\'"]' . preg_quote($referencedTable, '/') . '[\'"]/', $content)) {
                        $tableExists = true;
                        break;
                    }
                }
                
                if ($tableExists) {
                    $columnRules[] = "exists:{$referencedTable},id";
                }
            } 
            // Special data type validations
            elseif ($column === 'email' && !array_filter($columnRules, function($rule) {
                return Str::startsWith($rule, 'email');
            })) {
                $columnRules[] = 'email:rfc,dns';
            } 
            elseif ($column === 'password' && !array_filter($columnRules, function($rule) {
                return Str::startsWith($rule, 'min:');
            })) {
                $columnRules[] = 'min:8';
            } 
            elseif (in_array($column, ['url', 'link', 'website'])) {
                $columnRules[] = 'url';
            } 
            elseif ($column === 'uuid' && !in_array('uuid', $columnRules)) {
                // UUID validation
                $columnRules[] = 'uuid';
            }
            elseif (in_array($column, ['ip_address', 'last_login_ip', 'ip']) && !in_array('ip', $columnRules)) {
                // IP address validation
                $columnRules[] = 'ip';
            }
            elseif (in_array($column, ['mac_address', 'device_mac']) && 
                    !array_filter($columnRules, function($rule) {
                        return Str::startsWith($rule, 'regex:');
                    })) {
                // MAC address validation
                $columnRules[] = 'regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/';
            }
            elseif (in_array($column, ['phone', 'telephone', 'phone_number'])) {
                $columnRules[] = 'regex:/^([0-9\s\-\+\(\)]*)$/';
            }
            
            // Add unique constraint if needed
            if (in_array('unique', $columnRules)) {
                // Replace the simple 'unique' with proper table reference
                $uniqueIndex = array_search('unique', $columnRules);
                if ($uniqueIndex !== false) {
                    $columnRules[$uniqueIndex] = "unique:{$tableName},{$column}";
                }
            }
            
            // Fix regex patterns - collect all regex rules
            $regexRules = [];
            foreach ($columnRules as $i => $rule) {
                if (Str::startsWith($rule, 'regex:')) {
                    $regexRules[] = $rule;
                    unset($columnRules[$i]);
                }
            }

            // Add only the first regex rule if multiple exist
            if (!empty($regexRules)) {
                // Clean up any problematic regex syntax
                $firstRegex = $regexRules[0];
                // Fix double escaped slashes pattern: '/\/...\/\/' -> '/.../'
                $cleanRegex = preg_replace('/regex:\/+\\\\\/(.+?)\\\\\/\/+/', 'regex:/\\1/', $firstRegex);
                // If that didn't work, just simplify the pattern
                if ($cleanRegex === $firstRegex) {
                    $cleanRegex = preg_replace('/regex:\/+([^\/]+)\/+/', 'regex:/\\1/', $firstRegex);
                }
                $columnRules[] = $cleanRegex;
            }
            
            // Remove duplicate rules and reindex array
            $columnRules = array_values(array_unique($columnRules));
            
            $rules[$column] = implode('|', $columnRules);
        }

        return $rules;
    }

    /**
     * Update the request files with generated rules.
     *
     * @param string $modelName
     * @param array $rules
     * @return void
     */
    protected function updateRequestFiles($modelName, $rules) {
        // リクエストタイプを取得
        $requestType = $this->option('type');
        
        // 標準のリクエストパス
        $standardRequestDir = app_path('Http/Requests/' . $modelName);
        $standardStoreRequestPath = $standardRequestDir . '/StoreRequest.php';
        $standardUpdateRequestPath = $standardRequestDir . '/UpdateRequest.php';

        // 標準のパスをチェック
        $storeRequestPath = $this->files->exists($standardStoreRequestPath) 
            ? $standardStoreRequestPath : null;

        $updateRequestPath = $this->files->exists($standardUpdateRequestPath)
            ? $standardUpdateRequestPath : null;

        // StoreRequestファイルを処理
        if (($requestType == 'store' || $requestType == 'both')) {
            if (!$storeRequestPath) {
                // ファイルが存在しない場合は作成
                $this->createStoreRequestFile($modelName, $rules);
            } else {
                // ファイルが存在する場合は更新
                $storeUpdated = $this->updateRequestFile($storeRequestPath, $rules);
                if ($storeUpdated) {
                    $this->info("Updated Store request rules for {$modelName}");
                }
            }
        }

        // UpdateRequestファイルを処理
        if (($requestType == 'update' || $requestType == 'both')) {
            // UpdateRequest用にrequiredをsometimesに変換
            $updateRules = array_map(function ($rule) {
                return str_replace('required', 'sometimes', $rule);
            }, $rules);
            
            if (!$updateRequestPath) {
                // ファイルが存在しない場合は作成
                $this->createUpdateRequestFile($modelName, $updateRules);
            } else {
                // ファイルが存在する場合は更新
                $updateUpdated = $this->updateRequestFile($updateRequestPath, $updateRules);
                if ($updateUpdated) {
                    $this->info("Updated Update request rules for {$modelName}");
                }
            }
        }
    }

    /**
     * Create a store request file
     *
     * @param string $modelName
     * @param array $rules
     * @return void
     */
    protected function createStoreRequestFile($modelName, $rules) {
        // 標準のHttpディレクトリ下にリクエストファイルを作成
        $requestDir = app_path('Http/Requests/' . $modelName);
        
        // ディレクトリが存在しなければ作成
        if (!$this->files->isDirectory($requestDir)) {
            $this->files->makeDirectory($requestDir, 0755, true);
        }
        
        $storeRequestPath = $requestDir . '/StoreRequest.php';
        
        try {
            // StoreRequestファイルを作成（スタブを使用）
            $storeContent = $this->generateRequestFile($modelName, 'Store', $rules);
            $this->files->put($storeRequestPath, $storeContent);
            $this->info("Created Store request file for {$modelName} in Http/Requests/{$modelName}");
        } catch (\Exception $e) {
            $this->error("Failed to create Store request file: " . $e->getMessage());
            // エラーは記録するが、処理は続行する
        }
    }

    /**
     * Create an update request file
     *
     * @param string $modelName
     * @param array $rules
     * @return void
     */
    protected function createUpdateRequestFile($modelName, $rules) {
        // 標準のHttpディレクトリ下にリクエストファイルを作成
        $requestDir = app_path('Http/Requests/' . $modelName);
        
        // ディレクトリが存在しなければ作成
        if (!$this->files->isDirectory($requestDir)) {
            $this->files->makeDirectory($requestDir, 0755, true);
        }
        
        $updateRequestPath = $requestDir . '/UpdateRequest.php';
        
        try {
            // UpdateRequestファイルを作成（スタブを使用）
            $updateContent = $this->generateRequestFile($modelName, 'Update', $rules);
            $this->files->put($updateRequestPath, $updateContent);
            $this->info("Created Update request file for {$modelName} in Http/Requests/{$modelName}");
        } catch (\Exception $e) {
            $this->error("Failed to create Update request file: " . $e->getMessage());
            // エラーは記録するが、処理は続行する
        }
    }

    /**
     * リクエストファイルの内容を生成する
     *
     * @param string $modelName
     * @param string $requestType
     * @param array $rules
     * @return string
     */
    protected function generateRequestFile($modelName, $requestType, $rules) {
        try {
            // スタブファイルを取得
            $stub = $this->getStub('request');
            
            // スタブファイルのプレースホルダを置換
            $namespace = "App\\Http\\Requests\\{$modelName}";
            
            $content = str_replace(
                ['{{ namespace }}', '{{ class }}'],
                [$namespace, "{$requestType}Request"],
                $stub
            );
            
            // ルール配列を構築
            $rulesCode = "[\n";
            foreach ($rules as $field => $rule) {
                $rulesCode .= "            '{$field}' => '{$rule}',\n";
            }
            $rulesCode .= "        ]";
            
            // ルールの配列を挿入
            $pattern = '/return\s*\[\s*.*?\s*\]\s*;/s';
            $content = preg_replace($pattern, "return {$rulesCode};", $content);
            
            return $content;
        } catch (\Exception $e) {
            $this->error("Error generating request file: " . $e->getMessage());
            throw $e; // 呼び出し元で処理できるように例外を再スロー
        }
    }

    /**
     * Update a single request file with generated rules.
     *
     * @param string $path
     * @param array $rules
     * @return bool Whether the file was updated
     */
    protected function updateRequestFile($path, $rules) {
        $content = $this->files->get($path);

        // Find the rules method
        $pattern = '/public\s+function\s+rules\s*\(\s*\)\s*\{(.*?)\s*return\s*(.*?);/s';
        
        if (preg_match($pattern, $content, $matches)) {
            // Check if there are already rules defined (not empty or just comments)
            $existingRules = $matches[2];
            $hasExistingRules = false;
            
            // Check if there are actual rules defined (not just an empty array)
            if (preg_match('/\[\s*([^\]]+)\s*\]/', $existingRules, $ruleMatch)) {
                $ruleContent = $ruleMatch[1];
                // Remove comments and whitespace to check if there's any real content
                $cleanedContent = preg_replace('/\/\/.*$/m', '', $ruleContent);
                $cleanedContent = trim($cleanedContent);
                $hasExistingRules = !empty($cleanedContent) && $cleanedContent !== '//';
            }
            
            // If there are existing rules and --force is not set, ask for confirmation
            if ($hasExistingRules && !$this->option('force')) {
                $className = basename($path, '.php');
                $modelName = basename(dirname($path));
                
                if (!$this->confirm("The {$className} for {$modelName} already has validation rules. Do you want to overwrite them?")) {
                    $this->info("Skipped updating rules for {$className}.");
                    return false;
                }
            }
            
            // Generate the rules array as PHP code
            $rulesCode = "[\n";
            foreach ($rules as $field => $rule) {
                $rulesCode .= "            '{$field}' => '{$rule}',\n";
            }
            $rulesCode .= "        ]";

            // Replace the existing rules array with the new one
            $updatedContent = preg_replace(
                $pattern,
                "public function rules()\n    {\n        return {$rulesCode};",
                $content
            );

            // Save the updated file
            $this->files->put($path, $updatedContent);
            return true;
        } else {
            $this->warn("Could not find rules method in {$path}");
            return false;
        }
    }

    /**
     * Get the stub file content.
     *
     * @param string $type
     * @return string
     * @throws \Exception
     */
    protected function getStub(string $type): string {
        // スタブファイルのパスを探索
        $stubPaths = [
            // パッケージ内のstubsディレクトリを探索
            base_path('packages/lac/stubs/' . $type . '.stub'),
            // アプリ内のstubsディレクトリを探索
            base_path('stubs/' . $type . '.stub'),
            // コマンドファイルからの相対パスで探索
            dirname(__DIR__, 3) . '/stubs/' . $type . '.stub',
            dirname(__DIR__, 2) . '/stubs/' . $type . '.stub',
            dirname(__DIR__) . '/stubs/' . $type . '.stub',
        ];
        
        foreach ($stubPaths as $stubPath) {
            if ($this->files->exists($stubPath)) {
                return $this->files->get($stubPath);
            }
        }
        
        $this->error("Stub file not found: {$type}.stub");
        $this->warn("Please create the necessary stub files in the stubs directory.");
        
        throw new \Exception("Stub file '{$type}.stub' not found.");
    }
}