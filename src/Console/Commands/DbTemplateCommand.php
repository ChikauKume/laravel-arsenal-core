<?php

namespace Lac\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class DbTemplateCommand extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lac:db-template
                            {--force : Overwrite existing templates without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate Excel templates for database tables';

    /**
     * Base directory for database operations
     *
     * @var string
     */
    protected $baseDir;

    /**
     * Directory for templates
     *
     * @var string
     */
    protected $templatesDir;
    
    /**
     * Directory for imports
     *
     * @var string
     */
    protected $importsDir;
    
    /**
     * Migration path
     *
     * @var string
     */
    protected $migrationsPath;

    /**
     * System tables to skip
     * 
     * @var array
     */
    protected $skipTables = [
        'migrations', 
        'password_resets', 
        'personal_access_tokens', 
        'failed_jobs', 
        'jobs', 
        'cache', 
        'sessions'
    ];
    
    /**
     * Constructor to initialize directories
     */
    public function __construct() {
        parent::__construct();
        
        // Using relative paths from base
        $basePath = config('lac.storage_path', 'storage/app/db');
        $this->baseDir = storage_path('app/db'); // storage_path()を使用して相対パス化
        $this->templatesDir = $this->baseDir . '/templates';
        $this->importsDir = $this->baseDir . '/imports';
        $this->migrationsPath = base_path('database/migrations');
        
        // Allow configuration override
        $this->baseDir = config('lac.db.base_dir', $this->baseDir);
        $this->templatesDir = config('lac.db.templates_dir', $this->templatesDir);
        $this->importsDir = config('lac.db.imports_dir', $this->importsDir);
        
        // Load skip tables from config if available
        $this->skipTables = config('lac.database.skip_tables', $this->skipTables);
    }

    /**
     * Execute the console command
     * 
     * @return int
     */
    public function handle(): int {
        try {
            // Create necessary directories
            $this->ensureDirectoriesExist();
            
            // Check for schema discrepancies
            $discrepancies = $this->checkSchemaDiscrepancies();
            
            // If discrepancies found, abort with error message
            if (!empty($discrepancies)) {
                $this->error("Schema discrepancies detected:");
                
                foreach ($discrepancies as $discrepancy) {
                    $tableName = $discrepancy['table'];
                    $missingColumns = implode(', ', $discrepancy['details']);
                    $this->line(" - Schema discrepancy for table '{$tableName}': Missing columns in DB: {$missingColumns}.");
                }
                
                $this->error("Schema discrepancies between migrations and database detected.");
                $this->error("Template generation aborted to prevent potential errors.");
                $this->line("Please run \"php artisan migrate\" to update the database schema first.");
                $this->line("You can also check your migration files to ensure they match the current database structure.");
                $this->line("");
                $this->line("You can add these columns using:");
                $this->line("  For id:            \$table->id();");
                $this->line("  For created_at:    \$table->timestamps();");
                $this->line("  For deleted_at:    \$table->softDeletes();");
                
                return 1; // Exit with error code
            }
            
            // Generate templates for all tables
            $tables = $this->getTables();
            
            if (empty($tables)) {
                $this->warn("No tables found in the database.");
                return 0;
            }
            
            $this->info("Generating Excel templates for " . count($tables) . " tables...");
            
            $successCount = 0;
            
            foreach ($tables as $table) {
                $columns = $this->getColumns($table);
                
                if (empty($columns)) {
                    $this->warn("No columns found for table '{$table}', skipping...");
                    continue;
                }
                
                $outputPath = $this->templatesDir . '/' . $table . '.xlsx';
                
                // Check if template already exists
                if (File::exists($outputPath) && !$this->option('force')) {
                    if (!$this->confirm("Template for '{$table}' already exists. Overwrite?")) {
                        $this->line("Skipping '{$table}'");
                        continue;
                    }
                }
                
                if ($this->createTemplate($table, $columns, $outputPath)) {
                    $successCount++;
                }
            }
            
            $relativePath = str_replace(base_path() . '/', '', $this->templatesDir);
            $this->info("Generated {$successCount} templates successfully.");
            $this->line("Templates are stored in: {$relativePath}");
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("An error occurred: " . $e->getMessage());
            if ($this->getOutput()->isVerbose()) {
                $this->line("Stack trace: " . $e->getTraceAsString());
            }
            return 1;
        }
    }

    /**
     * Ensure required directories exist
     * 
     * @return void
     */
    protected function ensureDirectoriesExist(): void {
        foreach ([$this->baseDir, $this->templatesDir, $this->importsDir] as $dir) {
            if (!File::exists($dir)) {
                File::makeDirectory($dir, 0755, true);
                if ($this->getOutput()->isVerbose()) {
                    $this->line("Created directory: {$dir}");
                }
            }
        }
    }

    /**
     * Get all database tables (excluding system tables).
     *
     * @return array
     */
    protected function getTables(): array {
        // Get all tables and filter out system tables
        $allTables = $this->getAllTables();
        
        return array_filter($allTables, function($table) {
            return !in_array($table, $this->skipTables);
        });
    }

    /**
     * Get all tables from the current database connection
     * 
     * @return array List of table names as strings
     */
    protected function getAllTables(): array {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        
        if ($this->getOutput()->isVerbose()) {
            $this->info("Database connection: {$connection}");
            $this->info("Database driver: {$driver}");
        }
        
        $tables = [];
        
        try {
            switch ($driver) {
                case 'mysql':
                    // MySQL table retrieval with debug info
                    $database = config("database.connections.{$connection}.database");
                    $results = DB::select("SHOW TABLES");
                    
                    // Debug the result structure if verbose
                    if ($this->getOutput()->isVerbose() && !empty($results)) {
                        $firstResult = $results[0];
                        $this->info("First result structure: " . json_encode(get_object_vars($firstResult)));
                    }
                    
                    foreach ($results as $result) {
                        // Get value regardless of property name
                        $properties = get_object_vars($result);
                        $tableName = reset($properties);
                        $tables[] = $tableName;
                    }
                    break;
                    
                case 'pgsql':
                    $results = DB::select("SELECT tablename as table_name FROM pg_catalog.pg_tables WHERE schemaname = 'public'");
                    foreach ($results as $result) {
                        $tables[] = $result->table_name;
                    }
                    break;
                    
                case 'sqlite':
                    $results = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
                    foreach ($results as $result) {
                        $tables[] = $result->name;
                    }
                    break;
                    
                default:
                    // Use Doctrine Schema for more generic approach
                    try {
                        $schemaManager = Schema::getConnection()->getDoctrineSchemaManager();
                        $tables = $schemaManager->listTableNames();
                    } catch (\Exception $e) {
                        throw new \Exception("Database driver {$driver} is not supported for schema checking: " . $e->getMessage());
                    }
            }
            
            // Output table list if verbose
            if ($this->getOutput()->isVerbose()) {
                $this->info("Found total tables: " . implode(', ', $tables));
            }
            
            return $tables;
            
        } catch (\Exception $e) {
            $this->error("Error getting tables: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get columns for a table from database schema.
     *
     * @param string $table
     * @return array
     */
    protected function getColumns(string $table): array {
        $skipColumns = ['created_at', 'updated_at', 'deleted_at'];
        $columns = [];

        try {
            // Get all columns for the table
            $tableColumns = Schema::getColumnListing($table);
            
            // Output all columns if verbose
            if ($this->getOutput()->isVerbose()) {
                $this->line("Table '{$table}' has columns: " . implode(', ', $tableColumns));
            }
            
            foreach ($tableColumns as $column) {
                if (!in_array($column, $skipColumns)) {
                    $columns[$column] = Schema::getColumnType($table, $column);
                }
            }
            
            // Output processed columns if verbose
            if ($this->getOutput()->isVerbose()) {
                $this->line("After filtering, using columns: " . implode(', ', array_keys($columns)));
            }
            
            // If all columns were filtered out, include all columns except timestamps
            if (empty($columns)) {
                $alternativeSkip = ['created_at', 'updated_at', 'deleted_at'];
                foreach ($tableColumns as $column) {
                    if (!in_array($column, $alternativeSkip)) {
                        $columns[$column] = Schema::getColumnType($table, $column);
                    }
                }
                
                if ($this->getOutput()->isVerbose()) {
                    $this->line("No columns after filtering, including all columns except timestamps: " . implode(', ', array_keys($columns)));
                }
            }
        } catch (\Exception $e) {
            $this->error("Error getting columns for {$table}: " . $e->getMessage());
        }

        return $columns;
    }

    /**
     * Set column format based on data type.
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param string $colLetter
     * @param string $type
     * @return void
     */
    protected function setColumnFormat($sheet, string $colLetter, string $type): void {
        $formatRange = "{$colLetter}2:{$colLetter}1000";
        
        switch ($type) {
            case 'date':
                $sheet->getStyle($formatRange)
                    ->getNumberFormat()
                    ->setFormatCode('yyyy-mm-dd');
                break;
                
            case 'datetime':
                $sheet->getStyle($formatRange)
                    ->getNumberFormat()
                    ->setFormatCode('yyyy-mm-dd hh:mm:ss');
                break;
                
            case 'decimal':
            case 'float':
            case 'double':
                $sheet->getStyle($formatRange)
                    ->getNumberFormat()
                    ->setFormatCode('#,##0.00');
                break;
                
            case 'integer':
            case 'bigint':
                $sheet->getStyle($formatRange)
                    ->getNumberFormat()
                    ->setFormatCode('#,##0');
                break;
                
            case 'boolean':
                // Set data validation for boolean fields (0 or 1)
                $validation = $sheet->getCell("{$colLetter}2")->getDataValidation();
                $validation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
                $validation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_INFORMATION);
                $validation->setAllowBlank(true);
                $validation->setShowDropDown(true);
                $validation->setFormula1('"0,1"');  // Options: 0 or 1
                
                // Copy validation to all cells in range
                for ($i = 3; $i <= 1000; $i++) {
                    $sheet->getCell("{$colLetter}{$i}")
                        ->setDataValidation(clone $validation);
                }
                break;
        }
    }

    /**
     * Check for schema discrepancies between migrations and database tables
     * 
     * @return array Array of detected discrepancies
     */
    protected function checkSchemaDiscrepancies(): array {
        $discrepancies = [];
        
        // Get all tables from the database
        $tables = $this->getAllTables();
        
        foreach ($tables as $tableName) {
            // Skip system tables
            if (in_array($tableName, $this->skipTables)) {
                continue;
            }
            
            // Get column information from database
            $dbColumns = Schema::getColumnListing($tableName);
            
            // Get column information from migration files
            $migrationColumns = $this->getMigrationColumns($tableName);
            
            // Missing columns in database based on migrations
            $missingInDb = [];
            // Columns in database but not in migrations
            $extraInDb = [];
            
            // Check primary key (id)
            if (isset($migrationColumns['id']) && !in_array('id', $dbColumns)) {
                $missingInDb[] = 'id';
            } elseif (!isset($migrationColumns['id']) && in_array('id', $dbColumns)) {
                $extraInDb[] = 'id';
            }
            
            // Check timestamps
            if (isset($migrationColumns['created_at']) && !in_array('created_at', $dbColumns)) {
                $missingInDb[] = 'created_at';
            } elseif (!isset($migrationColumns['created_at']) && in_array('created_at', $dbColumns)) {
                $extraInDb[] = 'created_at';
            }
            
            if (isset($migrationColumns['updated_at']) && !in_array('updated_at', $dbColumns)) {
                $missingInDb[] = 'updated_at';
            } elseif (!isset($migrationColumns['updated_at']) && in_array('updated_at', $dbColumns)) {
                $extraInDb[] = 'updated_at';
            }
            
            // Check softDeletes
            if (isset($migrationColumns['deleted_at']) && !in_array('deleted_at', $dbColumns)) {
                $missingInDb[] = 'deleted_at';
            } elseif (!isset($migrationColumns['deleted_at']) && in_array('deleted_at', $dbColumns)) {
                $extraInDb[] = 'deleted_at';
            }
            
            // Record discrepancies
            if (!empty($missingInDb)) {
                $discrepancies[] = [
                    'table' => $tableName,
                    'type' => 'missing_columns',
                    'details' => $missingInDb
                ];
            }
            
            if (!empty($extraInDb)) {
                $discrepancies[] = [
                    'table' => $tableName,
                    'type' => 'extra_columns',
                    'details' => $extraInDb
                ];
            }
        }
        
        return $discrepancies;
    }

    /**
     * Get columns from migration files for a specific table.
     *
     * @param string $table
     * @return array
     */
    protected function getMigrationColumns(string $table): array {
        $columns = [];
        $migrationFiles = File::glob($this->migrationsPath . '/*.php');
        
        foreach ($migrationFiles as $file) {
            // Skip if file doesn't exist or can't be read
            if (!File::exists($file) || !File::isReadable($file)) {
                continue;
            }
            
            $content = File::get($file);
            
            // Check if this migration defines the table we're looking for
            if (preg_match('/Schema::create\s*\(\s*[\'"]' . preg_quote($table, '/') . '[\'"].*?{(.*?)}\s*\)\s*;/s', $content, $matches)) {
                $schemaBlock = $matches[1];
                
                // Debug log for verbose mode
                if ($this->getOutput()->isVerbose()) {
                    $this->line("Found migration for table '{$table}':");
                    $this->line(substr($schemaBlock, 0, 200) . (strlen($schemaBlock) > 200 ? '...' : ''));
                }
                
                // Check for id() method (Laravel's standard ID column)
                if (preg_match('/\$table->id\(\)/', $schemaBlock)) {
                    $columns['id'] = 'bigint';
                }
                
                // Check for bigIncrements() which is also an auto-incrementing ID
                if (preg_match('/\$table->bigIncrements\(/', $schemaBlock)) {
                    $columns['id'] = 'bigint';
                }
                
                // Check for increments() which is an auto-incrementing ID
                if (preg_match('/\$table->increments\(/', $schemaBlock)) {
                    $columns['id'] = 'integer';
                }
                
                // Check for timestamps() method
                if (preg_match('/\$table->timestamps\(\)/', $schemaBlock)) {
                    $columns['created_at'] = 'datetime';
                    $columns['updated_at'] = 'datetime';
                }
                
                // Check for softDeletes() method
                if (preg_match('/\$table->softDeletes\(\)/', $schemaBlock)) {
                    $columns['deleted_at'] = 'datetime';
                }
                
                // Also look for explicitly defined timestamp columns
                if (preg_match('/\$table->timestamp\([\'"]created_at[\'"]\)/', $schemaBlock)) {
                    $columns['created_at'] = 'datetime';
                }
                
                if (preg_match('/\$table->timestamp\([\'"]updated_at[\'"]\)/', $schemaBlock)) {
                    $columns['updated_at'] = 'datetime';
                }
                
                if (preg_match('/\$table->timestamp\([\'"]deleted_at[\'"]\)/', $schemaBlock)) {
                    $columns['deleted_at'] = 'datetime';
                }
                
                // We found the table definition, so break out of the loop
                break;
            }
        }
        
        // Debug log for verbose mode
        if ($this->getOutput()->isVerbose()) {
            $this->line("Migration columns for '{$table}': " . json_encode($columns));
        }
        
        return $columns;
    }

    /**
     * Determine if soft deletes should be checked for a table
     * 
     * @param string $tableName
     * @return bool
     */
    protected function shouldCheckSoftDeletes(string $tableName): bool {
        // 1. If "hardDelete" option is set in table config, respect that first
        $tableConfig = config("lac.tables.{$tableName}");
        if (isset($tableConfig['hardDelete'])) {
            return !$tableConfig['hardDelete'];
        }
        
        // 2. Check migration files for soft deletes usage
        $migrationFiles = File::glob($this->migrationsPath . '/*.php');
        foreach ($migrationFiles as $file) {
            if (!File::exists($file) || !File::isReadable($file)) {
                continue;
            }
            
            $content = File::get($file);
            
            // Check if this migration defines the table we're looking for
            if (preg_match('/Schema::create\s*\(\s*[\'"]' . preg_quote($tableName, '/') . '[\'"].*?{(.*?)}\s*\)\s*;/s', $content, $matches)) {
                $schemaBlock = $matches[1];
                
                // If the migration explicitly includes softDeletes, we should check for deleted_at
                if (preg_match('/\$table->softDeletes\(\)/', $schemaBlock)) {
                    return true;
                }
                
                // If we found the migration but it doesn't have softDeletes(), don't require it
                return false;
            }
        }
        
        // 3. Fall back to the global setting
        return config('lac.use_soft_deletes', false);
    }

    /**
     * Check if the migration for a table requires soft deletes
     * 
     * @param string $tableName
     * @return bool
     */
    protected function migrationRequiresSoftDeletes(string $tableName): bool {
        // First check if global soft deletes is disabled
        if (!config('lac.use_soft_deletes', true)) {
            return false;
        }
        
        // Then check migration files for this specific table
        $migrationFiles = File::glob($this->migrationsPath . '/*.php');
        
        foreach ($migrationFiles as $file) {
            // Skip if file doesn't exist or can't be read
            if (!File::exists($file) || !File::isReadable($file)) {
                continue;
            }
            
            $content = File::get($file);
            
            // Check if this migration defines the table we're looking for
            if (preg_match('/Schema::create\s*\(\s*[\'"]' . preg_quote($tableName, '/') . '[\'"].*?{(.*?)}\s*\)\s*;/s', $content, $matches)) {
                $schemaBlock = $matches[1];
                
                // Check for softDeletes() method
                if (preg_match('/\$table->softDeletes\(\)/', $schemaBlock)) {
                    return true;
                }
                
                // If we found the table definition but no softDeletes, then it's not required
                return false;
            }
        }
        
        // No migration found for this table, fallback to the global setting
        return config('lac.use_soft_deletes', true);
    }

    /**
     * Create Excel template.
     *
     * @param string $table
     * @param array $columns
     * @param string $outputPath
     * @return bool
     */
    protected function createTemplate(string $table, array $columns, string $outputPath): bool {
        try {
            if (empty($columns)) {
                $this->warn("No columns to include in template for '{$table}'");
                return false;
            }
            
            if ($this->getOutput()->isVerbose()) {
                $this->line("Creating template for table '{$table}' with " . count($columns) . " columns");
            }
            
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            
            // Set table name as sheet name (limited to 31 characters for Excel compatibility)
            $sheet->setTitle(Str::limit($table, 31));
            
            // Header row style - gray background, bold font, thin borders
            $headerStyle = [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E0E0E0'],
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
            ];
            
            // Set header row
            $colIndex = 1; // Start from column 1 (A)
            foreach ($columns as $column => $type) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
                $sheet->setCellValue("{$colLetter}1", $column);
                $sheet->getColumnDimension($colLetter)->setAutoSize(true);
                
                // Set cell format based on data type
                $this->setColumnFormat($sheet, $colLetter, $type);
                
                $colIndex++;
            }
            
            // Apply borders to data cells (100 rows)
            $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex - 1);
            $lastRow = 100; // Number of data rows
            
            // Apply header row style
            $sheet->getStyle("A1:{$lastCol}1")->applyFromArray($headerStyle);
            
            // Apply data rows style with thin borders
            $dataCellStyle = [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
            ];
            $sheet->getStyle("A2:{$lastCol}{$lastRow}")->applyFromArray($dataCellStyle);
            
            // Freeze header row
            $sheet->freezePane('A2');
            
            // Save file
            $writer = new Xlsx($spreadsheet);
            $writer->save($outputPath);
            
            if ($this->getOutput()->isVerbose()) {
                $this->line("Successfully created template at: {$outputPath}");
            } else {
                $this->line("Created template for '{$table}'");
            }
            return true;
        } catch (\Exception $e) {
            $this->error("Error creating template for {$table}: " . $e->getMessage());
            return false;
        }
    }
}