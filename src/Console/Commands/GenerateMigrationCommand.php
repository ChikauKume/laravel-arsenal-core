<?php

namespace Lac\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class GenerateMigrationCommand extends Command
{
   /**
    * The name and signature of the console command.
    *
    * @var string
    */
   protected $signature = 'lac:gen-migration {file? : PlantUML file name (optional)}
                           {--force : Drop existing tables and recreate}';

   /**
    * The console command description.
    *
    * @var string
    */
   protected $description = 'Generate migration files from PlantUML diagram';

   /**
    * Base directory for database operations
    *
    * @var string
    */
   protected $baseDir;

   /**
    * Directory for PlantUML diagram files
    *
    * @var string
    */
   protected $diagramDir;

   /**
    * Constructor to initialize directories
    */
   public function __construct()
   {
       parent::__construct();
       
       $this->baseDir = storage_path('app/db');
       $this->diagramDir = $this->baseDir . '/diagrams';
   }

   /**
    * Execute the console command.
    *
    * @return int
    */
   public function handle(): int
   {
       // Ensure directories exist
       $this->ensureDirectories();
       
       // Get PlantUML file
       $file = $this->getPlantUmlFile();
       
       if (!$file) {
           return Command::FAILURE;
       }
       
       // Read PlantUML content
       $content = File::get($file);
       
       // Parse PlantUML
       $result = $this->parsePlantUml($content);
       $tables = $result['tables'];
       
       if (empty($tables)) {
           $this->error("No tables found in PlantUML file");
           return Command::FAILURE;
       }
       
       // Display tables to be generated
       $this->displayTablesInfo($tables);
       
       // Check for existing tables
       $existingTables = $this->checkExistingTables($tables);
       
       if (!empty($existingTables)) {
           $this->warn("\nThe following migration files already exist:");
           foreach ($existingTables as $table) {
               $this->line(" - {$table}");
           }
           
           if ($this->option('force') || $this->confirm('Do you want to drop and recreate these migration files?')) {
               $this->dropExistingMigrations($existingTables);
           } else {
               $this->info("Operation cancelled.");
               return Command::FAILURE;
           }
       }
       
       // Generate migrations
       $this->info("\nGenerating migrations...");
       foreach ($tables as $index => $table) {
           if ($index > 0) {
               sleep(1); // Ensure different timestamps
           }
           $this->generateMigration($table);
       }
       
       $this->info("\nGenerated " . count($tables) . " migration files");
       $this->info("Run 'php artisan migrate' to create the tables");
       
       return Command::SUCCESS;
   }
   
   /**
    * Get PlantUML file path
    *
    * @return string|null
    */
   protected function getPlantUmlFile(): ?string
   {
       $file = $this->argument('file');
       
       // If no file specified, look for default files
       if (!$file) {
           $defaultFiles = ['schema.puml', 'er.puml', 'diagram.puml'];
           foreach ($defaultFiles as $defaultFile) {
               $path = $this->diagramDir . '/' . $defaultFile;
               if (File::exists($path)) {
                   $this->info("Using default file: {$defaultFile}");
                   return $path;
               }
           }
           
           $this->error("No PlantUML file specified and no default file found");
           $this->info("Place a .puml file in: {$this->diagramDir}");
           return null;
       }
       
       // If no path specified, look in default directory
       if (!str_contains($file, '/')) {
           $file = $this->diagramDir . '/' . $file;
       }
       
       // Add .puml extension if not present
       if (!str_ends_with($file, '.puml')) {
           $file .= '.puml';
       }
       
       // Check if file exists
       if (!File::exists($file)) {
           $this->error("File not found: {$file}");
           return null;
       }
       
       return $file;
   }
   
   /**
    * Display information about tables to be generated
    *
    * @param array $tables
    * @return void
    */
   protected function displayTablesInfo(array $tables): void
   {
       $this->info("Tables to be generated:");
       
       $regularTables = [];
       $pivotTables = [];
       
       foreach ($tables as $table) {
           if (isset($table['is_pivot']) && $table['is_pivot']) {
               $pivotTables[] = $table['name'];
           } else {
               $regularTables[] = Str::snake(Str::plural($table['name']));
           }
       }
       
       if (!empty($regularTables)) {
           $this->line("\nRegular tables:");
           foreach ($regularTables as $tableName) {
               $this->line(" - {$tableName}");
           }
       }
       
       if (!empty($pivotTables)) {
           $this->line("\nPivot tables:");
           foreach ($pivotTables as $tableName) {
               $this->line(" - {$tableName}");
           }
       }
   }
   
   /**
    * Check for existing tables
    *
    * @param array $tables
    * @return array
    */
   protected function checkExistingTables(array $tables): array
   {
       $existing = [];
       
       foreach ($tables as $table) {
           if (isset($table['is_pivot']) && $table['is_pivot']) {
               $tableName = $table['name'];
           } else {
               $tableName = Str::snake(Str::plural($table['name']));
           }
           
           // Check in migrations directory
           if ($this->findExistingMigration($tableName)) {
               $existing[] = $tableName;
           }
       }
       
       return $existing;
   }
   
   /**
    * Find existing migration for a table
    *
    * @param string $tableName
    * @return string|null
    */
   protected function findExistingMigration(string $tableName): ?string
   {
       $pattern = database_path("migrations/*_create_{$tableName}_table.php");
       $files = glob($pattern);
       
       return !empty($files) ? $files[0] : null;
   }
   
   /**
    * Drop existing migration files
    *
    * @param array $tables
    * @return void
    */
   protected function dropExistingMigrations(array $tables): void
   {
       foreach ($tables as $tableName) {
           $migration = $this->findExistingMigration($tableName);
           if ($migration) {
               File::delete($migration);
               $this->info("Deleted migration: " . basename($migration));
           }
       }
   }
   
   /**
    * Parse PlantUML content and extract table definitions
    *
    * @param string $content
    * @return array
    */
   protected function parsePlantUml(string $content): array
   {
       $tables = [];
       $relationships = [];
       $currentTable = null;
       
       $lines = explode("\n", $content);
       
       foreach ($lines as $line) {
           $line = trim($line);
           
           // Skip comments and empty lines
           if (empty($line) || str_starts_with($line, "'")) {
               continue;
           }
           
           // Check for entity/table definition
           if (preg_match('/^entity\s+(\w+)\s*\{?$/i', $line, $matches)) {
               if ($currentTable) {
                   $tables[] = $currentTable;
               }
               $currentTable = [
                   'name' => $matches[1],
                   'columns' => []
               ];
           }
           // Check for column definition - improved pattern with decimal support
           elseif ($currentTable && preg_match('/^\*?\s*(\w+)\s*:\s*(\w+)(?:\(([^)]+)\))?(.*)$/i', $line, $matches)) {
               $remainingText = trim($matches[4] ?? '');
               $typeParams = $matches[3] ?? '';
               
               // Check for NOT NULL constraint
               $nullable = true;
               $comment = '';
               
               if (preg_match('/\bNOT\s+NULL\b/i', $remainingText, $notNullMatch)) {
                   $nullable = false;
                   // Remove NOT NULL from remaining text to get actual comment
                   $comment = trim(preg_replace('/\bNOT\s+NULL\b/i', '', $remainingText));
               } else {
                   $comment = $remainingText;
               }
               
               // Remove any leading/trailing quotes from comment
               $comment = trim($comment, '"\'');
               
               $column = [
                   'name' => $matches[1],
                   'type' => strtolower($matches[2]),
                   'primary' => str_starts_with($line, '*'),
                   'nullable' => $nullable,
                   'comment' => $comment
               ];
               
               // Parse type parameters
               if ($typeParams) {
                   if (str_contains($typeParams, ',')) {
                       // For decimal(10,2) format
                       $params = array_map('trim', explode(',', $typeParams));
                       $column['precision'] = $params[0];
                       $column['scale'] = $params[1] ?? '0';
                   } else {
                       // For varchar(255) format
                       $column['size'] = trim($typeParams);
                   }
               }
               
               $currentTable['columns'][] = $column;
           }
           // Check for one-to-many relationship
           elseif (preg_match('/^(\w+)\s+\|\|--o\{\s+(\w+)$/i', $line, $matches)) {
               $relationships[] = [
                   'type' => 'one-to-many',
                   'parent' => $matches[1],
                   'child' => $matches[2]
               ];
           }
           // Check for one-to-one relationship
           elseif (preg_match('/^(\w+)\s+\|\|--\|\|\s+(\w+)$/i', $line, $matches)) {
               $relationships[] = [
                   'type' => 'one-to-one',
                   'parent' => $matches[1],
                   'child' => $matches[2]
               ];
           }
           // Check for many-to-many relationship
           elseif (preg_match('/^(\w+)\s+\}o--o\{\s+(\w+)(?:\s*:\s*(\w+))?$/i', $line, $matches)) {
               $relationships[] = [
                   'type' => 'many-to-many',
                   'table1' => $matches[1],
                   'table2' => $matches[2],
                   'custom_name' => $matches[3] ?? null
               ];
           }
           // Check for closing brace
           elseif ($line === '}' && $currentTable) {
               $tables[] = $currentTable;
               $currentTable = null;
           }
       }
       
       // Add last table if not closed
       if ($currentTable) {
           $tables[] = $currentTable;
       }
       
       // Process one-to-many relationships to add foreign keys
       foreach ($relationships as $relation) {
           if ($relation['type'] === 'one-to-many' || $relation['type'] === 'one-to-one') {
               $this->addForeignKeyColumn($tables, $relation);
           }
       }
       
       // IMPORTANT: Generate pivot tables AFTER all regular tables
       // This ensures foreign key constraints can be properly created
       $pivotTables = [];
       foreach ($relationships as $relation) {
           if ($relation['type'] === 'many-to-many') {
               $pivotTables[] = $this->generatePivotTable($relation);
           }
       }
       
       // Append pivot tables at the end
       $tables = array_merge($tables, $pivotTables);
       
       return [
           'tables' => $tables,
           'relationships' => $relationships
       ];
   }
   
   /**
    * Add foreign key column to child table
    *
    * @param array &$tables
    * @param array $relation
    * @return void
    */
   protected function addForeignKeyColumn(array &$tables, array $relation): void
   {
       $parentName = Str::singular(strtolower($relation['parent']));
       $childName = Str::singular(strtolower($relation['child']));
       $foreignKeyName = $parentName . '_id';
       
       // Find child table and add foreign key if not exists
       foreach ($tables as &$table) {
           if (strtolower($table['name']) === $childName) {
               // Check if foreign key already exists
               $exists = false;
               foreach ($table['columns'] as &$column) {
                   if ($column['name'] === $foreignKeyName) {
                       // Add foreign key info if not present
                       if (!isset($column['foreign_key'])) {
                           $column['foreign_key'] = [
                               'table' => Str::plural($parentName),
                               'column' => 'id'
                           ];
                       }
                       $exists = true;
                       break;
                   }
               }
               
               // Add foreign key column if not exists
               if (!$exists) {
                   $table['columns'][] = [
                       'name' => $foreignKeyName,
                       'type' => 'bigint',
                       'primary' => false,
                       'nullable' => false,
                       'comment' => '',
                       'foreign_key' => [
                           'table' => Str::plural($parentName),
                           'column' => 'id'
                       ]
                   ];
               }
               break;
           }
       }
   }

   /**
    * Build column definition
    *
    * @param array $column
    * @return string|null
    */
   protected function buildColumnDefinition(array $column): ?string
   {
       $name = $column['name'];
       $type = $column['type'];
       
       // Map PlantUML types to Laravel migration methods
       $method = match($type) {
           'int', 'integer' => 'integer',
           'bigint' => 'bigInteger',
           'varchar', 'string' => 'string',
           'text' => 'text',
           'boolean', 'bool' => 'boolean',
           'date' => 'date',
           'datetime', 'timestamp' => 'timestamp',
           'decimal' => 'decimal',
           'float' => 'float',
           'json' => 'json',
           default => 'string'
       };
       
       // Build method call
       $definition = "\$table->{$method}('{$name}'";
       
       // Add parameters based on type
       if ($method === 'decimal' && isset($column['precision']) && isset($column['scale'])) {
           $definition .= ", {$column['precision']}, {$column['scale']}";
       } elseif (in_array($method, ['string']) && isset($column['size'])) {
           $definition .= ", {$column['size']}";
       }
       
       $definition .= ")";
       
       // Add modifiers
       if (isset($column['nullable']) && $column['nullable'] && !$column['primary']) {
           $definition .= "->nullable()";
       }
       
       // Add comment only if it exists and is not empty
       if (!empty($column['comment'])) {
           $escapedComment = addslashes($column['comment']);
           $definition .= "->comment('{$escapedComment}')";
       }
       
       $definition .= ";";
       
       return $definition;
   }
   
   /**
    * Generate pivot table structure
    *
    * @param array $relation
    * @return array
    */
   protected function generatePivotTable(array $relation): array
   {
       // Convert to singular form for Laravel convention
       $model1 = Str::singular(strtolower($relation['table1']));
       $model2 = Str::singular(strtolower($relation['table2']));
       
       // Use custom name if provided, otherwise follow Laravel convention
       if (!empty($relation['custom_name'])) {
           $pivotName = Str::snake($relation['custom_name']);
       } else {
           // Sort alphabetically for Laravel convention
           $models = [$model1, $model2];
           sort($models);
           $pivotName = implode('_', $models);
       }
       
       // Determine foreign key names
       $fk1 = $model1 . '_id';
       $fk2 = $model2 . '_id';
       
       return [
           'name' => $pivotName,
           'is_pivot' => true,
           'columns' => [
               [
                   'name' => 'id',
                   'type' => 'bigint',
                   'size' => null,
                   'primary' => true,
                   'nullable' => false,
                   'comment' => ''
               ],
               [
                   'name' => $fk1,
                   'type' => 'bigint',
                   'size' => null,
                   'primary' => false,
                   'nullable' => false,
                   'comment' => '',
                   'foreign_key' => [
                       'table' => Str::plural($model1),
                       'column' => 'id'
                   ]
               ],
               [
                   'name' => $fk2,
                   'type' => 'bigint',
                   'size' => null,
                   'primary' => false,
                   'nullable' => false,
                   'comment' => '',
                   'foreign_key' => [
                       'table' => Str::plural($model2),
                       'column' => 'id'
                   ]
               ]
           ]
       ];
   }
   
   /**
    * Generate migration file for a table
    *
    * @param array $table
    * @return void
    */
   protected function generateMigration(array $table): void
   {
       // Handle table name based on whether it's a pivot table or regular table
       if (isset($table['is_pivot']) && $table['is_pivot']) {
           // Pivot tables use the name as-is (already in singular_singular format)
           $tableName = $table['name'];
       } else {
           // Regular tables are pluralized
           $tableName = Str::snake(Str::plural($table['name']));
       }
       
       $timestamp = date('Y_m_d_His');
       $fileName = "{$timestamp}_create_{$tableName}_table.php";
       
       // Get the stub file
       $stub = $this->getStub('migration');
       
       // Replace table name
       $stub = str_replace('{{ table }}', $tableName, $stub);
       
       // Build schema content
       $schemaContent = $this->buildSchemaContent($table['columns']);
       
       // Replace schema content (insert between $table->id() and $table->timestamps())
       $stub = preg_replace(
           '/(\$table->id\(\);)(.*?)(\$table->timestamps\(\);)/s',
           "$1\n{$schemaContent}\n            $3",
           $stub
       );
       
       // Handle soft deletes (default to not using soft deletes for pivot tables)
       if (isset($table['is_pivot']) && $table['is_pivot']) {
           $stub = str_replace('{{ softDeletes }}', '', $stub);
       } else {
           $stub = str_replace('{{ softDeletes }}', '', $stub);
       }
       
       // Save migration file
       $path = database_path("migrations/{$fileName}");
       File::put($path, $stub);
       
       $this->info("Created migration: {$fileName}");
   }
   
   /**
    * Build schema content for columns
    *
    * @param array $columns
    * @return string
    */
   protected function buildSchemaContent(array $columns): string
   {
       $lines = [];
       $foreignKeys = [];
       
       foreach ($columns as $column) {
           // Skip id and timestamps as they're handled by the stub
           if (in_array($column['name'], ['id', 'created_at', 'updated_at'])) {
               continue;
           }
           
           $line = $this->buildColumnDefinition($column);
           if ($line) {
               $lines[] = "            " . $line;
           }
           
           // Collect foreign key definitions
           if (isset($column['foreign_key'])) {
               $foreignKeys[] = $column;
           }
       }
       
       // Add foreign key constraints
       if (!empty($foreignKeys)) {
           $lines[] = ""; // Empty line for readability
           foreach ($foreignKeys as $fk) {
               $lines[] = "            \$table->foreign('{$fk['name']}')->references('{$fk['foreign_key']['column']}')->on('{$fk['foreign_key']['table']}')->onDelete('cascade');";
           }
       }
       
       // Add unique constraint for pivot tables (when exactly 2 foreign keys)
       $pivotForeignKeys = array_filter($foreignKeys, fn($fk) => str_ends_with($fk['name'], '_id'));
       if (count($pivotForeignKeys) === 2) {
           $fkColumns = array_map(fn($fk) => $fk['name'], $pivotForeignKeys);
           $lines[] = "            \$table->unique(['" . implode("', '", $fkColumns) . "']);";
       }
       
       return implode("\n", $lines);
   }
   
   /**
    * Ensure all necessary directories exist
    *
    * @return void
    */
   protected function ensureDirectories(): void
   {
       foreach ([$this->baseDir, $this->diagramDir] as $dir) {
           if (!File::exists($dir)) {
               File::makeDirectory($dir, 0755, true);
           }
       }
   }
   
   /**
    * Get the stub file content
    *
    * @param string $type
    * @return string
    * @throws \Exception
    */
   protected function getStub(string $type): string
   {
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