<?php

namespace Lac\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;

class SyncModelRelCommand extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lac:sync-model-rel {model? : Specific model name to process (omit for all models)} {--models= : Path to models directory (default: app/Models)} {--force : Overwrite existing relations}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate model relationships based on migration files';

    /**
     * The filesystem instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;

    /**
     * Relations collection
     *
     * @var array
     */
    protected $relations = [];

    /**
     * Table to model mapping
     *
     * @var array
     */
    protected $tableToModel = [];

    /**
     * Pivot tables collection
     *
     * @var array
     */
    protected $pivotTables = [];

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
        $this->info('Synchronizing relationships from migrations...');

        // Get specific model if provided
        $targetModel = $this->argument('model');
        if ($targetModel) {
            $targetModel = Str::studly($targetModel);
            $this->info("Processing only model: {$targetModel}");
        }

        // Get model and migration directory paths
        $modelPath = $this->option('models') ?: app_path('Models');
        $migrationPath = database_path('migrations');
        
        // Derive namespace from model path
        $namespace = $this->deriveNamespaceFromPath($modelPath);

        // Validate directories exist
        if (!$this->files->isDirectory($modelPath)) {
            $this->error("Models directory not found: {$modelPath}");
            return Command::FAILURE;
        }

        if (!$this->files->isDirectory($migrationPath)) {
            $this->error("Migrations directory not found: {$migrationPath}");
            return Command::FAILURE;
        }

        // Create mapping between table names and model names
        $this->createTableToModelMapping($modelPath, $namespace, $targetModel);

        if (empty($this->tableToModel)) {
            if ($targetModel) {
                $this->error("Model '{$targetModel}' not found in {$modelPath}");
            } else {
                $this->error("No models found in {$modelPath}");
            }
            return Command::FAILURE;
        }

        // Analyze migrations to discover relationships
        $this->analyzeMigrations($migrationPath);

        // Update models with found relationships
        $this->updateModelsWithRelations($modelPath);

        $this->info('Model Relationship synchronization completed!');
        return Command::SUCCESS;
    }
    
    /**
     * Derive namespace from model path
     *
     * @param string $modelPath
     * @return string
     */
    protected function deriveNamespaceFromPath($modelPath) {
        $appPath = app_path();
        
        if (Str::startsWith($modelPath, $appPath)) {
            $relativePath = trim(Str::after($modelPath, $appPath), '/\\');
            return 'App\\' . str_replace('/', '\\', $relativePath);
        }
        
        return 'App\\Models';
    }

    /**
     * Create mapping between table names and model names
     *
     * @param string $modelPath
     * @param string $namespace
     * @param string|null $targetModel
     * @return void
     */
    protected function createTableToModelMapping($modelPath, $namespace, $targetModel = null) {
        $this->info('Creating table to model mapping...');
        $modelFiles = $this->files->glob($modelPath . '/*.php');

        foreach ($modelFiles as $modelFile) {
            $modelName = pathinfo($modelFile, PATHINFO_FILENAME);
            
            if ($targetModel && $modelName !== $targetModel) {
                continue;
            }
            
            if (!$this->isValidModelFile($modelFile, $modelName)) {
                $this->warn("Skipping file {$modelName}.php - not a valid model");
                continue;
            }
            
            $modelClass = $namespace . '\\' . $modelName;

            try {
                if (!class_exists($modelClass)) {
                    throw new \Exception("Class {$modelClass} does not exist");
                }
                
                $model = new $modelClass();
                $tableName = $model->getTable();
                $this->tableToModel[$tableName] = $modelName;
                $this->info("Mapped table {$tableName} to model {$modelName}");
            } catch (\Exception $e) {
                $tableName = Str::snake(Str::pluralStudly($modelName));
                $this->tableToModel[$tableName] = $modelName;
                $this->info("Guessed mapping of table {$tableName} to model {$modelName}");
            }
        }
    }

    /**
     * Check if a file contains a valid model class
     * 
     * @param string $filePath
     * @param string $expectedClassName
     * @return bool
     */
    protected function isValidModelFile($filePath, $expectedClassName) {
        try {
            $content = $this->files->get($filePath);
            
            // Check for namespace (App\Models or just App for User model)
            if (!preg_match('/namespace\s+App(\\\\Models)?/i', $content)) {
                return false;
            }
            
            // Check for class definition extending Model or Authenticatable
            $classPattern = '/class\s+' . preg_quote($expectedClassName) . '\s+extends\s+(Model|Authenticatable)/i';
            if (!preg_match($classPattern, $content)) {
                return false;
            }
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Analyze migration files to discover relationships
     *
     * @param string $migrationPath
     * @return void
     */
    protected function analyzeMigrations($migrationPath) {
        $migrationFiles = $this->files->glob($migrationPath . '/*.php');

        // First pass: identify potential pivot tables
        foreach ($migrationFiles as $migrationFile) {
            $this->identifyPivotTables($migrationFile);
        }

        // Second pass: search for regular foreign key constraints
        foreach ($migrationFiles as $migrationFile) {
            $this->analyzeFile($migrationFile);
        }
    }

    /**
     * Identify potential pivot tables from migration files
     *
     * @param string $filePath
     * @return void
     */
    protected function identifyPivotTables($filePath) {
        $content = $this->files->get($filePath);
        $fileName = basename($filePath);

        // Extract table name
        if (preg_match('/create(?:_or_replace)?_(.+?)_table/i', $fileName, $matches)) {
            $tableName = $matches[1];
        } elseif (preg_match('/Schema::create\([\'"](.+?)[\'"]/i', $content, $matches)) {
            $tableName = $matches[1];
        } else {
            return;
        }

        // Skip system tables
        $skipTables = [
            'migrations', 
            'password_resets', 
            'password_reset_tokens',
            'personal_access_tokens', 
            'failed_jobs', 
            'jobs', 
            'cache', 
            'sessions',
            'job_batches'
        ];
        
        if (in_array($tableName, $skipTables)) {
            return;
        }

        // Check if this might be a pivot table (has exactly two foreign keys)
        $foreignKeyCount = 0;
        $foreignTables = [];

        // Define patterns for foreign key detection
        $patterns = [
            // $table->foreignId('user_id')->constrained() pattern
            '/foreignId\([\'"](.+?)[\'"]\)(?:->constrained\((?:[\'"](.+?)[\'"]\))?)?/i',
            
            // $table->foreign('user_id')->references('id')->on('users') pattern
            '/foreign\([\'"](.+?)[\'"]\)->references\([\'"](.+?)[\'"]\)->on\([\'"](.+?)[\'"]\)/i',
            
            // $table->foreignIdFor(User::class) pattern
            '/foreignIdFor\((.+?)::class\)/i'
        ];

        foreach ($patterns as $index => $pattern) {
            $matches = [];
            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $foreignKeyCount++;
                    
                    switch ($index) {
                        case 0: // foreignId pattern
                            $columnName = $match[1];
                            $foreignTable = isset($match[2]) ? $match[2] : $this->guessTableFromColumn($columnName);
                            break;
                        
                        case 1: // foreign->references->on pattern
                            $columnName = $match[1];
                            $foreignTable = $match[3];
                            break;
                        
                        case 2: // foreignIdFor pattern
                            $modelClass = $match[1];
                            $modelName = class_basename($modelClass);
                            $foreignTable = Str::snake(Str::pluralStudly($modelName));
                            break;
                    }
                    
                    $foreignTables[] = $foreignTable;
                }
            }
        }

        // Check if this is likely a pivot table (exactly 2 foreign keys)
        if ($foreignKeyCount === 2) {
            $this->info("Detected potential pivot table: {$tableName}");
            $this->pivotTables[$tableName] = [
                'related_tables' => $foreignTables
            ];
        }
    }

    /**
     * Analyze a single migration file
     *
     * @param string $filePath
     * @return void
     */
    protected function analyzeFile($filePath) {
        $content = $this->files->get($filePath);
        $fileName = basename($filePath);

        // Extract table name
        if (preg_match('/create(?:_or_replace)?_(.+?)_table/i', $fileName, $matches)) {
            $tableName = $matches[1];
        } elseif (preg_match('/Schema::create\([\'"](.+?)[\'"]/i', $content, $matches)) {
            $tableName = $matches[1];
        } else {
            return;
        }

        // Skip if this is a pivot table - we'll handle it separately
        if (isset($this->pivotTables[$tableName])) {
            return;
        }

        // Search for foreign key constraints
        $this->extractForeignKeys($content, $tableName);
    }

    /**
     * Extract foreign key constraints
     *
     * @param string $content
     * @param string $tableName
     * @return void
     */
    protected function extractForeignKeys($content, $tableName) {
        // Skip system tables
        $skipTables = [
            'migrations', 
            'password_resets', 
            'password_reset_tokens',
            'personal_access_tokens', 
            'failed_jobs', 
            'jobs', 
            'cache', 
            'sessions',
            'job_batches'
        ];
        
        if (in_array($tableName, $skipTables)) {
            return;
        }
        
        // Skip if this is a pivot table
        if (isset($this->pivotTables[$tableName])) {
            return;
        }
        
        // Foreign key constraint patterns
        $patterns = [
            // $table->foreignId('user_id')->constrained() pattern
            '/foreignId\([\'"](.+?)[\'"]\)(?:->constrained\((?:[\'"](.+?)[\'"]\))?)?/i',
            
            // $table->foreign('user_id')->references('id')->on('users') pattern
            '/foreign\([\'"](.+?)[\'"]\)->references\([\'"](.+?)[\'"]\)->on\([\'"](.+?)[\'"]\)/i',
            
            // $table->foreignIdFor(User::class) pattern
            '/foreignIdFor\((.+?)::class\)/i'
        ];

        foreach ($patterns as $index => $pattern) {
            switch ($index) {
                case 0: // foreignId pattern
                    if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $match) {
                            $columnName = $match[1];
                            $foreignTable = isset($match[2]) ? $match[2] : $this->guessTableFromColumn($columnName);
                            $this->addRelation($tableName, $foreignTable, $columnName);
                        }
                    }
                    break;
                
                case 1: // foreign->references->on pattern
                    if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $match) {
                            $columnName = $match[1];
                            $foreignTable = $match[3];
                            $this->addRelation($tableName, $foreignTable, $columnName);
                        }
                    }
                    break;
                
                case 2: // foreignIdFor pattern
                    if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $match) {
                            $modelClass = $match[1];
                            $modelName = class_basename($modelClass);
                            $foreignTable = Str::snake(Str::pluralStudly($modelName));
                            $columnName = Str::snake($modelName) . '_id';
                            $this->addRelation($tableName, $foreignTable, $columnName);
                        }
                    }
                    break;
            }
        }
    }

    /**
     * Guess table name from column name
     *
     * @param string $columnName
     * @return string
     */
    protected function guessTableFromColumn($columnName) {
        if (Str::endsWith($columnName, '_id')) {
            $baseName = Str::beforeLast($columnName, '_id');
            return Str::plural($baseName);
        }
        
        return $columnName;
    }

    /**
     * Add relation to our relations collection
     *
     * @param string $childTable
     * @param string $parentTable
     * @param string $foreignKey
     * @return void
     */
    protected function addRelation($childTable, $parentTable, $foreignKey) {
        // Skip relations for pivot tables
        if (isset($this->pivotTables[$childTable])) {
            return;
        }
        
        // Skip self-referencing relations
        if ($childTable === $parentTable) {
            return;
        }
        
        // Skip system tables
        $skipTables = [
            'migrations', 
            'password_resets', 
            'password_reset_tokens',
            'personal_access_tokens', 
            'failed_jobs', 
            'jobs', 
            'cache', 
            'sessions',
            'job_batches'
        ];
        
        if (in_array($childTable, $skipTables) || in_array($parentTable, $skipTables)) {
            return;
        }
        
        // Add hasMany relation to parent table
        if (!isset($this->relations[$parentTable])) {
            $this->relations[$parentTable] = ['hasMany' => []];
        }
        
        if (!isset($this->relations[$parentTable]['hasMany'])) {
            $this->relations[$parentTable]['hasMany'] = [];
        }
        
        // Check for duplicate relationships
        $alreadyExists = false;
        foreach ($this->relations[$parentTable]['hasMany'] as $relation) {
            if ($relation['table'] === $childTable && $relation['foreignKey'] === $foreignKey) {
                $alreadyExists = true;
                break;
            }
        }
        
        if (!$alreadyExists) {
            $this->relations[$parentTable]['hasMany'][] = [
                'table' => $childTable,
                'foreignKey' => $foreignKey
            ];
        }
        
        // Add belongsTo relation to child table
        if (!isset($this->relations[$childTable])) {
            $this->relations[$childTable] = ['belongsTo' => []];
        }
        
        if (!isset($this->relations[$childTable]['belongsTo'])) {
            $this->relations[$childTable]['belongsTo'] = [];
        }
        
        // Check for duplicate relationships
        $alreadyExists = false;
        foreach ($this->relations[$childTable]['belongsTo'] as $relation) {
            if ($relation['table'] === $parentTable && $relation['foreignKey'] === $foreignKey) {
                $alreadyExists = true;
                break;
            }
        }
        
        if (!$alreadyExists) {
            $this->relations[$childTable]['belongsTo'][] = [
                'table' => $parentTable,
                'foreignKey' => $foreignKey
            ];
        }
    }

    /**
     * Fix model structure issues
     *
     * @param string $modelFile
     * @return void
     */
    protected function validateAndFixModelStructure($modelFile)  {
        $content = $this->files->get($modelFile);
        $modelName = pathinfo($modelFile, PATHINFO_FILENAME);
        
        // 修正1: メソッドの閉じ括弧が欠けている場合を修正
        $pattern = '/public\s+function\s+([a-zA-Z0-9_]+)\s*\(\)\s*\{.*?return\s+\$this->.*?;(\s*)(?!\s*\})/s';
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $methodName = $match[1];
                $pattern = '/(public\s+function\s+' . preg_quote($methodName) . '\s*\(\)\s*\{.*?return\s+\$this->.*?;)(\s*)(?!\s*\})/s';
                $replacement = '$1$2' . "\n    }";
                $content = preg_replace($pattern, $replacement, $content);
            }
            
            $this->files->put($modelFile, $content);
        }
        
        // 修正2: クラスの最後の閉じ括弧のインデントを修正
        if (preg_match('/\}\s*$/', $content)) {
            $content = preg_replace('/\s*\}\s*$/', "\n}", $content);
            $this->files->put($modelFile, $content);
        }
        
        // 他の構造チェックと修正...
        // [その他の既存のコード]
        
        // 最終チェック: すべての修正が適用された後に再度インデントを確認
        $finalContent = $this->files->get($modelFile);
        if (preg_match('/\s+\}\s*$/', $finalContent)) {
            $finalContent = preg_replace('/\s+\}\s*$/', "\n}", $finalContent);
            $this->files->put($modelFile, $finalContent);
        }
    }

    /**
     * Process many-to-many relations from pivot tables
     *
     * @param string $modelPath
     * @return int Number of many-to-many relations added
     */
    protected function processManyToManyRelations($modelPath) {
        if ($this->argument('model') && $this->option('force')) {
            return 0;
        }
        
        $addedRelations = 0;

        foreach ($this->pivotTables as $pivotTable => $pivotInfo) {
            $relatedTables = $pivotInfo['related_tables'];
            
            if (count($relatedTables) !== 2) {
                continue;
            }

            $table1 = $relatedTables[0];
            $table2 = $relatedTables[1];

            if (!isset($this->tableToModel[$table1]) || !isset($this->tableToModel[$table2])) {
                continue;
            }

            $targetModel = $this->argument('model');
            if ($targetModel) {
                if ($this->tableToModel[$table1] === $targetModel) {
                    $added = $this->addManyToManyRelation($modelPath, $table1, $table2, $pivotTable);
                    $addedRelations += $added;
                } elseif ($this->tableToModel[$table2] === $targetModel) {
                    $added = $this->addManyToManyRelation($modelPath, $table2, $table1, $pivotTable);
                    $addedRelations += $added;
                }
            } else {
                $added1 = $this->addManyToManyRelation($modelPath, $table1, $table2, $pivotTable);
                $added2 = $this->addManyToManyRelation($modelPath, $table2, $table1, $pivotTable);
                $addedRelations += ($added1 + $added2);
            }
        }
        
        return $addedRelations;
    }

    /**
     * Add a many-to-many relation to a model
     *
     * @param string $modelPath
     * @param string $sourceTable
     * @param string $targetTable
     * @param string $pivotTable
     * @return int 1 if relation was added, 0 otherwise
     */
    protected function addManyToManyRelation($modelPath, $sourceTable, $targetTable, $pivotTable) {
        if (!isset($this->tableToModel[$sourceTable]) || !isset($this->tableToModel[$targetTable])) {
            return 0;
        }

        $sourceModel = $this->tableToModel[$sourceTable];
        $targetModel = $this->tableToModel[$targetTable];
        $sourceModelFile = $modelPath . '/' . $sourceModel . '.php';

        if (!$this->files->exists($sourceModelFile)) {
            return 0;
        }

        $content = $this->files->get($sourceModelFile);
        
        // Check for existing relations
        $existingRelations = $this->getExistingRelations($content);
        
        // Generate method name
        $methodName = Str::camel(Str::plural($targetTable));
        
        // Skip if relation already exists and not forcing overwrite
        if (isset($existingRelations[$methodName]) && !$this->option('force')) {
            return 0;
        }
        
        // Generate relation method
        $relationMethod = $this->generateBelongsToManyMethod($methodName, $targetModel, $pivotTable);
        
        // Insert or update the relation in the model file
        $updatedContent = $this->insertOrUpdateRelationsIntoModel($content, [$methodName => $relationMethod], $existingRelations);
        $this->files->put($sourceModelFile, $updatedContent);
        
        $action = isset($existingRelations[$methodName]) ? "Updated" : "Added";
        $this->info("  - {$action} belongsToMany relation {$methodName}() to {$sourceModel}");
        return 1;
    }

    /**
     * Update a single model file with relations
     *
     * @param string $modelFile
     * @param array $relationTypes
     * @return int Number of relations added
     */
    protected function updateModelFile($modelFile, $relationTypes) {
        $content = $this->files->get($modelFile);
        $modelName = pathinfo($modelFile, PATHINFO_FILENAME);
        $addedRelations = 0;
        
        $this->info("Updating model {$modelName}...");
        
        // Check for existing relations
        $existingRelations = $this->getExistingRelations($content);
        
        // Create relation methods
        $newRelations = [];
        
        // belongsTo relations
        if (isset($relationTypes['belongsTo'])) {
            foreach ($relationTypes['belongsTo'] as $relation) {
                $relatedTable = $relation['table'];
                $foreignKey = $relation['foreignKey'];
                
                if (!isset($this->tableToModel[$relatedTable])) {
                    continue;
                }
                
                $relatedModel = $this->tableToModel[$relatedTable];
                $methodName = Str::camel(Str::singular($relatedTable));
                
                // Skip if relation already exists and not forcing overwrite
                if (isset($existingRelations[$methodName]) && !$this->option('force')) {
                    continue;
                }
                
                $relationMethod = $this->generateBelongsToMethod($methodName, $relatedModel, $foreignKey);
                $newRelations[$methodName] = $relationMethod;
                $addedRelations++;
            }
        }
        
        // hasMany relations
        if (isset($relationTypes['hasMany'])) {
            foreach ($relationTypes['hasMany'] as $relation) {
                $relatedTable = $relation['table'];
                $foreignKey = $relation['foreignKey'];
                
                if (!isset($this->tableToModel[$relatedTable])) {
                    continue;
                }
                
                $relatedModel = $this->tableToModel[$relatedTable];
                $methodName = Str::camel(Str::plural($relatedTable));
                
                // Skip if relation already exists and not forcing overwrite
                if (isset($existingRelations[$methodName]) && !$this->option('force')) {
                    continue;
                }
                
                $relationMethod = $this->generateHasManyMethod($methodName, $relatedModel, $foreignKey);
                $newRelations[$methodName] = $relationMethod;
                $addedRelations++;
            }
        }
        
        // Update model file
        if (!empty($newRelations)) {
            $updatedContent = $this->insertOrUpdateRelationsIntoModel($content, $newRelations, $existingRelations);
            $this->files->put($modelFile, $updatedContent);
            $this->info("Updated model {$modelName}");
        }

        return $addedRelations;
    }

    /**
     * Get existing relation methods from model content
     *
     * @param string $content
     * @return array
     */
    protected function getExistingRelations($content)  {
        $relations = [];
        
        // Extract the entire class content
        preg_match('/class\s+[a-zA-Z0-9_]+.*?\{(.*)\}/s', $content, $classContentMatch);
        
        if (!isset($classContentMatch[1])) {
            return $relations;
        }
        
        $classContent = $classContentMatch[1];
        
        // Detect all public methods
        preg_match_all('/\s*public\s+function\s+([a-zA-Z0-9_]+)\s*\(\)\s*\{/s', $classContent, $matches);
        
        if (isset($matches[1])) {
            foreach ($matches[1] as $index => $methodName) {
                $methodStartPos = strpos($classContent, $matches[0][$index]);
                
                // Find next method or class end
                $nextMethodPos = PHP_INT_MAX;
                if (isset($matches[0][$index + 1])) {
                    $nextPos = strpos($classContent, $matches[0][$index + 1], $methodStartPos);
                    if ($nextPos !== false) {
                        $nextMethodPos = $nextPos;
                    }
                }
                
                $endClassPos = strrpos($classContent, '}');
                $methodEndPos = min($nextMethodPos, $endClassPos);
                
                $methodContent = substr($classContent, $methodStartPos, $methodEndPos - $methodStartPos);
                
                // Check if method is a relation
                $isRelation = preg_match('/\$this->(belongsTo|hasMany|hasOne|belongsToMany|morphTo|morphMany|morphOne|morphToMany|morphedByMany|hasManyThrough|hasOneThrough)/i', $methodContent);
                
                $relations[$methodName] = [
                    'content' => $methodContent,
                    'is_relation' => $isRelation
                ];
            }
        }
        
        return $relations;
    }

    /**
     * Insert or update relation methods in model class
     *
     * @param string $content
     * @param array $relations
     * @param array $existingRelations
     * @return string
     */
    protected function insertOrUpdateRelationsIntoModel($content, $relations, $existingRelations) {
        $force = $this->option('force');
        
        // Check and fix brace balance
        $openBraces = substr_count($content, '{');
        $closeBraces = substr_count($content, '}');
        
        if ($openBraces !== $closeBraces) {
            if (preg_match('/^(.*?)(\s*\}\s*)$/s', $content, $matches)) {
                $contentBeforeLastBrace = $matches[1];
                $lastBraceWithWhitespace = $matches[2];
                $lastBraceWithWhitespace = preg_replace('/\}\s*\}+/', '}', $lastBraceWithWhitespace);
                $content = $contentBeforeLastBrace . $lastBraceWithWhitespace;
            }
        }
        
        // Find the closing bracket of the class
        $lastBracePos = strrpos($content, '}');
        
        if ($lastBracePos === false) {
            $lastBracePos = strlen($content);
            $content = $content . "\n}";
        }
        
        // Process relations
        foreach ($relations as $methodName => $methodCode) {
            if (isset($existingRelations[$methodName]) && $force) {
                // Replace existing method - find the complete method including docblock
                $pattern = '/\s*\/\*\*(?:[^*]|\*(?!\/))*\*\/\s*public\s+function\s+' . preg_quote($methodName) . '\s*\(\)\s*\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s';
                $content = preg_replace($pattern, "\n\n" . $methodCode, $content, 1);
            } 
            elseif (!isset($existingRelations[$methodName])) {
                // Add new method
                $content = substr_replace($content, "\n\n" . $methodCode, $lastBracePos, 0);
                $lastBracePos += strlen("\n\n" . $methodCode);
            }
        }
        
        // Remove duplicate empty docblocks
        $content = preg_replace('/(\s*\/\*\*\s*\*[^*\/]*\*\/\s*){2,}/', '$1', $content);
        
        // Final brace check - ensure final brace is properly indented with no space
        $content = preg_replace('/\s*\}\s*$/', "\n}", $content);
        
        // Check for any stray closures or braces
        $newOpenBraces = substr_count($content, '{');
        $newCloseBraces = substr_count($content, '}');
        
        if ($newOpenBraces !== $newCloseBraces) {
            if ($newOpenBraces > $newCloseBraces) {
                $diff = $newOpenBraces - $newCloseBraces;
                $content .= str_repeat("\n}", $diff);
            } 
            elseif ($newCloseBraces > $newOpenBraces) {
                $content = preg_replace('/(\}\s*\}+)$/', "\n}", $content);
            }
        }
        
        return $content;
    }


    /**
    * Generate belongsTo relation method
    *
    * @param string $methodName
    * @param string $relatedModel
    * @param string $foreignKey
    * @return string
    */
   protected function generateBelongsToMethod($methodName, $relatedModel, $foreignKey)  {
       return "    /**
    * Relationship to {$relatedModel} model
    *
    * @return \\Illuminate\\Database\\Eloquent\\Relations\\BelongsTo
    */
   public function {$methodName}() {
       return \$this->belongsTo(\\App\\Models\\{$relatedModel}::class, '{$foreignKey}');
   }";
   }

   /**
    * Generate hasMany relation method
    *
    * @param string $methodName
    * @param string $relatedModel
    * @param string $foreignKey
    * @return string
    */
   protected function generateHasManyMethod($methodName, $relatedModel, $foreignKey)  {
       return "    /**
    * Relationship to {$relatedModel} collection
    *
    * @return \\Illuminate\\Database\\Eloquent\\Relations\\HasMany
    */
   public function {$methodName}() {
       return \$this->hasMany(\\App\\Models\\{$relatedModel}::class, '{$foreignKey}');
   }";
   }

   /**
    * Generate belongsToMany relation method
    *
    * @param string $methodName
    * @param string $relatedModel
    * @param string $pivotTable
    * @return string
    */
   protected function generateBelongsToManyMethod($methodName, $relatedModel, $pivotTable)  {
       return "    /**
    * Many-to-many relationship to {$relatedModel} collection
    *
    * @return \\Illuminate\\Database\\Eloquent\\Relations\\BelongsToMany
    */
   public function {$methodName}() {
       return \$this->belongsToMany(\\App\\Models\\{$relatedModel}::class, '{$pivotTable}');
   }";
   }

    /**
     * Update models with the relations we found
     *
     * @param string $modelPath
     * @return void
     */
    protected function updateModelsWithRelations($modelPath)  {
        $targetModel = $this->argument('model');
        $force = $this->option('force');
        $relationCount = 0;

        // Process regular relations (hasMany, belongsTo)
        foreach ($this->relations as $tableName => $relationTypes) {
            // Skip pivot tables
            if (isset($this->pivotTables[$tableName])) {
                continue;
            }
            
            if (!isset($this->tableToModel[$tableName])) {
                continue;
            }

            $modelName = $this->tableToModel[$tableName];

            if ($targetModel && $modelName !== $targetModel) {
                continue;
            }

            $modelFile = $modelPath . '/' . $modelName . '.php';

            if (!$this->files->exists($modelFile)) {
                continue;
            }

            $addedRelations = $this->updateModelFile($modelFile, $relationTypes);
            $relationCount += $addedRelations;
        }

        // Process many-to-many relations
        $manyToManyRelations = $this->processManyToManyRelations($modelPath);
        $relationCount += $manyToManyRelations;

        // Fix model structure issues
        if ($targetModel) {
            $modelFile = $modelPath . '/' . $targetModel . '.php';
            if ($this->files->exists($modelFile)) {
                $this->validateAndFixModelStructure($modelFile);
            }
        } else {
            foreach ($this->tableToModel as $tableName => $modelName) {
                $modelFile = $modelPath . '/' . $modelName . '.php';
                if ($this->files->exists($modelFile)) {
                    $this->validateAndFixModelStructure($modelFile);
                }
            }
        }

        $this->info("Summary: Added/Updated {$relationCount} relations");
    }

    /**
     * Get column types from database table
     *
     * @param string $tableName
     * @return array
     */
    protected function getColumnTypesFromTable($tableName)  {
        if (!Schema::hasTable($tableName)) {
            return [];
        }

        $columns = Schema::getColumnListing($tableName);
        $columnTypes = [];
        
        foreach ($columns as $column) {
            // Skip standard columns
            if (in_array($column, ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            try {
                $type = Schema::getColumnType($tableName, $column);
                $cast = $this->guessCastType($column, $type);
                if ($cast) {
                    $columnTypes[$column] = $cast;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return $columnTypes;
    }

    /**
     * Guess cast type from column name and database type
     *
     * @param string $columnName
     * @param string $databaseType
     * @return string|null
     */
    protected function guessCastType($columnName, $databaseType)  {
        // Database type mapping
        $typeCasts = [
            'bigint' => 'integer',
            'int' => 'integer',
            'integer' => 'integer',
            'tinyint' => 'boolean',
            'smallint' => 'integer',
            'mediumint' => 'integer',
            'float' => 'float',
            'double' => 'double',
            'decimal' => 'decimal',
            'numeric' => 'decimal',
            'boolean' => 'boolean',
            'date' => 'date',
            'datetime' => 'datetime',
            'timestamp' => 'timestamp',
            'json' => 'array',
            'jsonb' => 'array',
            'array' => 'array',
        ];

        // Use database type mapping
        if (isset($typeCasts[$databaseType])) {
            return $typeCasts[$databaseType];
        }

        // Date patterns
        if ($databaseType === 'string' || $databaseType === 'text') {
            if (Str::endsWith($columnName, '_at')) {
                return 'datetime';
            }
            
            if (Str::endsWith($columnName, '_on')) {
                return 'date';
            }
            
            if (preg_match('/(date|time|day)/', $columnName)) {
                return 'datetime';
            }
            
            if (preg_match('/(json|data|config|settings|options)/', $columnName)) {
                return 'array';
            }
        }

        // Boolean patterns
        if ($databaseType === 'integer' || $databaseType === 'tinyint') {
            if (preg_match('/^(is_|has_|can_|show_|active|enabled|visible|published)/', $columnName)) {
                return 'boolean';
            }
        }

        return null;
    }

    /**
     * Get stub file content
     *
     * @param string $type
     * @return string
     * @throws \Exception
     */
    protected function getStub(string $type): string  {
        $stubPaths = [
            dirname(__DIR__, 3) . '/stubs/' . $type . '.stub',
            dirname(__DIR__, 2) . '/stubs/' . $type . '.stub',
            dirname(__DIR__) . '/stubs/' . $type . '.stub',
        ];
        
        foreach ($stubPaths as $stubPath) {
            if (file_exists($stubPath)) {
                return file_get_contents($stubPath);
            }
        }
        
        throw new \Exception("Stub file '{$type}.stub' not found.");
    }
}