<?php

namespace Lac\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class GenerateDiagramCommand extends Command
{
   /**
    * The name and signature of the console command.
    *
    * @var string
    */
   protected $signature = 'lac:gen-diagram';

   /**
    * The console command description.
    *
    * @var string
    */
   protected $description = 'Generate PlantUML diagram from migration files';

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
    * Directory for generated diagram files
    *
    * @var string
    */
   protected $generatedDir;

   /**
    * Constructor to initialize directories
    */
   public function __construct()
   {
       parent::__construct();
       
       $this->baseDir = storage_path('app/db');
       $this->diagramDir = $this->baseDir . '/diagrams';
       $this->generatedDir = $this->diagramDir . '/generated';
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
       
       $this->info('Generating PlantUML from migration files...');
       
       // Get schema information from migrations
       [$tables, $relationships] = $this->extractFromMigrations();
       
       if (empty($tables)) {
           $this->error('No tables found in migration files');
           return Command::FAILURE;
       }
       
       // Display found tables
       $this->info("\nFound " . count($tables) . " tables:");
       foreach ($tables as $table) {
           $this->line(" - {$table['name']} ({$table['table']})");
       }
       
       // Generate PlantUML content
       $plantUml = $this->generatePlantUml($tables, $relationships);
       
       // Generate filename with timestamp
       $timestamp = date('Y_m_d_His');
       $filename = "{$timestamp}_schema.puml";
       $path = $this->generatedDir . '/' . $filename;
       
       // Save to file
       File::put($path, $plantUml);
       
       $this->info("\nPlantUML diagram generated: {$filename}");
       $this->info("Location: {$path}");
       
       return Command::SUCCESS;
   }
   
   /**
    * Extract schema information from migration files
    *
    * @return array
    */
   protected function extractFromMigrations(): array
   {
       $tables = [];
       $relationships = [];
       $pivotTables = []; // Track pivot tables with their full info
       
       $migrationPath = database_path('migrations');
       $files = File::glob($migrationPath . '/*.php');
       
       // Sort files to process in order
       sort($files);
       
       foreach ($files as $file) {
           $content = File::get($file);
           
           // Skip if not a create table migration
           if (!str_contains($content, 'Schema::create(')) {
               continue;
           }
           
           // Extract table name
           if (preg_match('/Schema::create\([\'"](\w+)[\'"]/', $content, $matches)) {
               $tableName = $matches[1];
               $entityName = Str::singular(Str::studly($tableName));
               
               $this->line("Processing: " . basename($file));
               
               // Extract columns
               $columns = $this->parseColumnsFromMigration($content);
               
               // Check if it's a pivot table BEFORE adding to tables array
               $isPivot = $this->isPivotTable($tableName, $columns);
               
               if ($isPivot) {
                   $this->info("  -> Detected as pivot table: {$tableName}");
                   $pivotTables[$tableName] = true;
               } else {
                   // Only add non-pivot tables to the tables array
                   $tables[$entityName] = [
                       'name' => $entityName,
                       'table' => $tableName,
                       'columns' => $columns
                   ];
               }
               
               // Extract relationships (pass pivot table info)
               $migrationRelationships = $this->parseRelationshipsFromMigration($content, $tableName, $isPivot);
               $relationships = array_merge($relationships, $migrationRelationships);
           }
       }
       
       return [$tables, $relationships];
   }
   
   /**
    * Parse columns from migration file content
    *
    * @param string $content
    * @return array
    */
   protected function parseColumnsFromMigration(string $content): array
   {
       $columns = [];
       
       // Handle id() method first
       if (preg_match('/\$table->id\(\)/', $content)) {
           $columns[] = [
               'name' => 'id',
               'type' => 'bigint',
               'nullable' => false,
               'primary' => true,
               'comment' => ''
           ];
       }
       
       // Handle rememberToken()
       if (preg_match('/\$table->rememberToken\(\)/', $content)) {
           $columns[] = [
               'name' => 'remember_token',
               'type' => 'varchar(100)',
               'nullable' => true,
               'primary' => false,
               'comment' => ''
           ];
       }
       
       // Handle morphs() - creates two columns
       if (preg_match('/\$table->morphs\([\'"](\w+)[\'"]\)/', $content, $morphMatch)) {
           $morphName = $morphMatch[1];
           $columns[] = [
               'name' => $morphName . '_type',
               'type' => 'varchar(255)',
               'nullable' => false,
               'primary' => false,
               'comment' => ''
           ];
           $columns[] = [
               'name' => $morphName . '_id',
               'type' => 'bigint',
               'nullable' => false,
               'primary' => false,
               'comment' => ''
           ];
       }
       
       // Handle foreignId() - creates bigint column with foreign key
       if (preg_match_all('/\$table->foreignId\([\'"](\w+)[\'"]\)/', $content, $foreignIdMatches)) {
           foreach ($foreignIdMatches[1] as $columnName) {
               // Skip if already added
               if (!in_array($columnName, array_column($columns, 'name'))) {
                   $columns[] = [
                       'name' => $columnName,
                       'type' => 'bigint',
                       'nullable' => false,
                       'primary' => false,
                       'comment' => ''
                   ];
               }
           }
       }
       
       // Match regular column definitions
       $pattern = '/\$table->(\w+)\([\'"](\w+)[\'"](?:,\s*(\d+)(?:,\s*(\d+))?)?\)([^;]*);/';
       preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
       
       foreach ($matches as $match) {
           $method = $match[1];
           $columnName = $match[2];
           $param1 = $match[3] ?? null;
           $param2 = $match[4] ?? null;
           $modifiers = $match[5] ?? '';
           
           // Skip certain methods and already processed columns
           if (in_array($method, ['foreign', 'unique', 'index', 'primary', 'dropColumn', 'dropForeign', 'foreignId', 'morphs'])) {
               continue;
           }
           
           // Skip if column already added
           if (in_array($columnName, array_column($columns, 'name'))) {
               continue;
           }
           
           // Handle primary() modifier for columns like email in password_reset_tokens
           $isPrimary = str_contains($modifiers, 'primary()');
           
           // Map Laravel migration methods to PlantUML types
           $type = $this->mapMigrationMethodToPlantUml($method);
           
           // Add size parameter if applicable
           if ($param1) {
               if ($method === 'decimal' && $param2) {
                   $type .= "({$param1},{$param2})";
               } elseif (in_array($method, ['string', 'char', 'varchar'])) {
                   $type .= "({$param1})";
               }
           }
           
           // Check for nullable
           $nullable = str_contains($modifiers, 'nullable()');
           
           // Check for unique
           if (str_contains($modifiers, 'unique()')) {
               // Could add as comment or handle differently
           }
           
           // Check for comment
           $comment = '';
           if (preg_match('/->comment\([\'"]([^\'"]*)[\'"]\)/', $modifiers, $commentMatch)) {
               $comment = $commentMatch[1];
           }
           
           $columns[] = [
               'name' => $columnName,
               'type' => $type,
               'nullable' => $nullable,
               'primary' => $isPrimary,
               'comment' => $comment
           ];
       }
       
       // Handle timestamps()
       if (preg_match('/\$table->timestamps\(\)/', $content)) {
           $columns[] = [
               'name' => 'created_at',
               'type' => 'timestamp',
               'nullable' => true,
               'primary' => false,
               'comment' => ''
           ];
           $columns[] = [
               'name' => 'updated_at',
               'type' => 'timestamp',
               'nullable' => true,
               'primary' => false,
               'comment' => ''
           ];
       }
       
       // Handle softDeletes()
       if (preg_match('/\$table->softDeletes\(\)/', $content)) {
           $columns[] = [
               'name' => 'deleted_at',
               'type' => 'timestamp',
               'nullable' => true,
               'primary' => false,
               'comment' => ''
           ];
       }
       
       // Handle useCurrent() for timestamps
       if (preg_match('/\$table->timestamp\([\'"](\w+)[\'"]\)->useCurrent\(\)/', $content, $currentMatch)) {
           foreach ($columns as &$column) {
               if ($column['name'] === $currentMatch[1]) {
                   $column['nullable'] = false;
                   break;
               }
           }
       }
       
       return $columns;
   }
   
   /**
    * Parse relationships from migration file content
    *
    * @param string $content
    * @param string $tableName
    * @param bool $isPivot
    * @return array
    */
   protected function parseRelationshipsFromMigration(string $content, string $tableName, bool $isPivot = false): array
   {
       $relationships = [];
       
       // Check for foreignId()->constrained() pattern
       $pattern = '/\$table->foreignId\([\'"](\w+)[\'"]\)->constrained\((?:[\'"](\w+)[\'"])?\)/';
       preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
       
       $foreignIdConstraints = [];
       foreach ($matches as $match) {
           $columnName = $match[1];
           $referencedTable = $match[2] ?? null;
           
           // If no table specified, derive from column name (user_id -> users)
           if (!$referencedTable && str_ends_with($columnName, '_id')) {
               $referencedTable = Str::plural(str_replace('_id', '', $columnName));
           }
           
           if ($referencedTable) {
               $foreignIdConstraints[] = [
                   'column' => $columnName,
                   'table' => $referencedTable
               ];
           }
       }
       
       // Also check traditional foreign() pattern
       $pattern = '/\$table->foreign\([\'"](\w+)[\'"]\)->references\([\'"](\w+)[\'"]\)->on\([\'"](\w+)[\'"]\)/';
       preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
       
       $foreignKeys = [];
       foreach ($matches as $match) {
           $foreignKeys[] = [
               'column' => $match[1],
               'references' => $match[2],
               'on' => $match[3]
           ];
       }
       
       // Combine foreignId and traditional foreign keys
       $allForeignKeys = [];
       foreach ($foreignIdConstraints as $fk) {
           $allForeignKeys[] = [
               'column' => $fk['column'],
               'on' => $fk['table']
           ];
       }
       foreach ($foreignKeys as $fk) {
           $allForeignKeys[] = [
               'column' => $fk['column'],
               'on' => $fk['on']
           ];
       }
       
       // Process based on whether it's a pivot table
       if ($isPivot && count($allForeignKeys) >= 2) {
           // For pivot tables, create many-to-many relationship
           $relatedTables = [];
           foreach ($allForeignKeys as $fk) {
               if (str_ends_with($fk['column'], '_id')) {
                   $relatedTables[] = Str::singular(Str::studly($fk['on']));
               }
           }
           
           if (count($relatedTables) >= 2) {
               // Take first two tables for many-to-many
               $relationships[] = [
                   'type' => 'many-to-many',
                   'table1' => $relatedTables[0],
                   'table2' => $relatedTables[1],
                   'pivot_table' => $tableName
               ];
           }
       } else {
           // Check for one-to-one relationships by looking for unique constraints
           $uniqueColumns = [];
           if (preg_match_all('/\$table->unique\([\'"](\w+)[\'"]\)/', $content, $uniqueMatches)) {
               $uniqueColumns = $uniqueMatches[1];
           }
           
           // Regular foreign key relationships
           foreach ($allForeignKeys as $fk) {
               if (str_ends_with($fk['column'], '_id')) {
                   // Check if this foreign key has a unique constraint
                   $relationType = in_array($fk['column'], $uniqueColumns) ? 'one-to-one' : 'one-to-many';
                   
                   $relationships[] = [
                       'type' => $relationType,
                       'parent' => Str::singular(Str::studly($fk['on'])),
                       'child' => Str::singular(Str::studly($tableName))
                   ];
               }
           }
       }
       
       return $relationships;
   }
   
   /**
    * Check if table name follows pivot table naming convention
    *
    * @param string $tableName
    * @return bool
    */
   protected function isPivotTableName(string $tableName): bool
   {
       // Check for patterns like: item_user, post_tag, product_category
       // Also check for custom names like product_categories (plural form)
       $parts = explode('_', $tableName);
       
       if (count($parts) === 2) {
           // Check if both parts are singular (standard Laravel convention)
           $singular1 = Str::singular($parts[0]);
           $singular2 = Str::singular($parts[1]);
           
           if ($singular1 === $parts[0] && $singular2 === $parts[1]) {
               return true;
           }
           
           // Also check for patterns where second part might be plural
           // e.g., product_categories, post_tags
           if ($singular1 === $parts[0] && $singular2 !== $parts[1]) {
               return true;
           }
       }
       
       return false;
   }
   
   /**
    * Check if table is a pivot table based on columns
    *
    * @param string $tableName
    * @param array $columns
    * @return bool
    */
   protected function isPivotTable(string $tableName, array $columns): bool
   {
       // First check naming convention
       if (!$this->isPivotTableName($tableName)) {
           return false;
       }
       
       // Count foreign key columns
       $foreignKeyCount = 0;
       $nonSystemColumns = 0;
       
       foreach ($columns as $column) {
           if (str_ends_with($column['name'], '_id')) {
               $foreignKeyCount++;
           } elseif (!in_array($column['name'], ['id', 'created_at', 'updated_at', 'deleted_at'])) {
               $nonSystemColumns++;
           }
       }
       
       // Typical pivot table has exactly 2 foreign keys and minimal other columns
       return $foreignKeyCount === 2 && $nonSystemColumns === 0;
   }
   
   /**
    * Map Laravel migration method to PlantUML type
    *
    * @param string $method
    * @return string
    */
   protected function mapMigrationMethodToPlantUml(string $method): string
   {
       return match($method) {
           'id', 'bigIncrements', 'bigInteger' => 'bigint',
           'increments', 'integer', 'tinyInteger', 'smallInteger', 'mediumInteger' => 'int',
           'string', 'char' => 'varchar',
           'text', 'mediumText', 'longText', 'tinyText' => 'text',
           'boolean' => 'boolean',
           'date' => 'date',
           'dateTime', 'dateTimeTz' => 'datetime',
           'timestamp', 'timestampTz' => 'timestamp',
           'time', 'timeTz' => 'time',
           'decimal', 'unsignedDecimal' => 'decimal',
           'float', 'double' => 'float',
           'json', 'jsonb' => 'json',
           'uuid' => 'varchar(36)',
           'binary' => 'blob',
           'enum' => 'varchar',
           'year' => 'year',
           default => $method
       };
   }
   
   /**
    * Generate PlantUML content
    *
    * @param array $tables
    * @param array $relationships
    * @return string
    */
   protected function generatePlantUml(array $tables, array $relationships): string
   {
       $content = "@startuml\n";
       $content .= "' Generated on " . date('Y-m-d H:i:s') . "\n\n";
       
       // Add entities
       foreach ($tables as $entity) {
           $content .= "entity {$entity['name']} {\n";
           
           foreach ($entity['columns'] as $column) {
               // Primary key marker
               $prefix = $column['primary'] ? '* ' : '  ';
               
               // Column definition
               $content .= $prefix . $column['name'] . ' : ' . $column['type'];
               
               // NOT NULL constraint
               if (!$column['nullable'] && !$column['primary']) {
                   $content .= ' NOT NULL';
               }
               
               // Comment
               if (!empty($column['comment'])) {
                   $content .= ' "' . $column['comment'] . '"';
               }
               
               $content .= "\n";
           }
           
           $content .= "}\n\n";
       }
       
       // Add relationships section
       if (!empty($relationships)) {
           $content .= "' Relationships\n";
           
           // Organize relationships by type
           $oneToOne = [];
           $oneToMany = [];
           $manyToMany = [];
           
           foreach ($relationships as $relation) {
               if ($relation['type'] === 'one-to-one') {
                   $key = "{$relation['parent']}-{$relation['child']}";
                   $oneToOne[$key] = $relation;
               } elseif ($relation['type'] === 'one-to-many') {
                   $key = "{$relation['parent']}-{$relation['child']}";
                   $oneToMany[$key] = $relation;
               } elseif ($relation['type'] === 'many-to-many') {
                   // Sort tables alphabetically for consistent key
                   $tables = [$relation['table1'], $relation['table2']];
                   sort($tables);
                   $key = implode('-', $tables);
                   $manyToMany[$key] = $relation;
               }
           }
           
           // Add one-to-one relationships
           foreach ($oneToOne as $relation) {
               $content .= "{$relation['parent']} ||--|| {$relation['child']}\n";
           }
           
           // Add one-to-many relationships
           foreach ($oneToMany as $relation) {
               $content .= "{$relation['parent']} ||--o{ {$relation['child']}\n";
           }
           
           // Add many-to-many relationships with pivot table name
           foreach ($manyToMany as $relation) {
               if (isset($relation['pivot_table'])) {
                   $content .= "{$relation['table1']} }o--o{ {$relation['table2']} : {$relation['pivot_table']}\n";
               } else {
                   $content .= "{$relation['table1']} }o--o{ {$relation['table2']}\n";
               }
           }
       }
       
       $content .= "\n@enduml";
       
       return $content;
   }
   
   /**
    * Ensure all necessary directories exist
    *
    * @return void
    */
   protected function ensureDirectories(): void
   {
       foreach ([$this->baseDir, $this->diagramDir, $this->generatedDir] as $dir) {
           if (!File::exists($dir)) {
               File::makeDirectory($dir, 0755, true);
           }
       }
   }
}