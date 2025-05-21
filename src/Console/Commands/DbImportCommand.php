<?php

namespace Lac\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;

class DbImportCommand extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lac:db-import {--table= : Specify the target table for import}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import data from Excel files into database tables';

    /**
     * Base directory for database operations
     *
     * @var string
     */
    protected $baseDir;

    /**
     * Directory for import files
     *
     * @var string
     */
    protected $importDir;

    /**
     * Directory for template files
     *
     * @var string
     */
    protected $templateDir;

    /**
     * Directory for processed files
     *
     * @var string
     */
    protected $processedDir;

    /**
     * Constructor to initialize directories
     */
    public function __construct() {
        parent::__construct();
        
        $this->baseDir = storage_path('app/db');
        $this->importDir = $this->baseDir . '/imports';
        $this->templateDir = $this->baseDir . '/templates';
        $this->processedDir = $this->baseDir . '/processed'; // imports/processedから変更
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int {
        // Ensure directories exist
        $this->ensureDirectories();
        
        // Set target table if specified
        $targetTable = $this->option('table');
        
        // Get files to import
        $filesToImport = $this->getImportFiles($targetTable);
        
        if (empty($filesToImport)) {
            return Command::FAILURE;
        }
        
        // Display import plan
        $this->info('Found ' . count($filesToImport) . ' files to import:');
        foreach ($filesToImport as $file) {
            $this->line(' - ' . basename($file));
        }
        
        // Choose processing mode
        $processMode = $this->choice(
            'How would you like to import?',
            [
                'each' => 'Each file separately',
                'all' => 'Apply the same settings to all files'
            ],
            'each'
        );
        
        if ($processMode === 'all') {
            // Choose strategy for all files
            $strategy = $this->choice(
                'Select import strategy for all files:',
                [
                    'add' => 'Add all records (no duplicate checking)',
                    'update' => 'Add & update (update existing records, add new ones)'
                ],
                'add'
            );
            
            // 確認なしで全ファイル処理
            $successCount = 0;
            $failureCount = 0;
            
            foreach ($filesToImport as $filePath) {
                $this->info("\nProcessing: " . basename($filePath));
                
                if ($this->importFile($filePath, $strategy)) {
                    $successCount++;
                } else {
                    $failureCount++;
                }
            }
            
            // Summary
            $this->newLine();
            $this->info("Import completed: {$successCount} succeeded, {$failureCount} failed.");
        } else {
            // 各ファイルを個別に処理、確認なし
            foreach ($filesToImport as $filePath) {
                $fileName = basename($filePath);
                $tableName = $this->extractTableName($fileName);
                
                $this->info("\nFile: " . $fileName . " (Table: " . $tableName . ")");
                
                // 確認なしで進める
                $strategy = $this->choice(
                    'Select import strategy for this file:',
                    [
                        'add' => 'Add all records (no duplicate checking)',
                        'update' => 'Add & update (update existing records, add new ones)'
                    ],
                    'add'
                );
                
                $this->importFile($filePath, $strategy);
            }
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Get all Excel files from the import directory, optionally filtered by table name
     *
     * @param string|null $targetTable
     * @return array
     */
    protected function getImportFiles(?string $targetTable = null): array {
        $files = [];
        
        if (File::exists($this->importDir)) {
            $allFiles = File::files($this->importDir);
            
            foreach ($allFiles as $file) {
                if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'xlsx') {
                    // If target table is specified, filter by table name
                    if ($targetTable) {
                        $tableName = $this->extractTableName($file->getFilename());
                        if ($tableName !== $targetTable) {
                            continue;
                        }
                    }
                    
                    $files[] = $file->getPathname();
                }
            }
        }
        
        if (empty($files)) {
            $this->error('No import files found.');
            $this->line('Place Excel files in: storage/app/db/imports');
        }
        
        return $files;
    }
    
    /**
     * Import a single file with the specified strategy
     *
     * @param string $filePath
     * @param string $strategy
     * @return bool
     */
    protected function importFile(string $filePath, string $strategy): bool {
        // Extract table name from filename
        $fileName = basename($filePath);
        $tableName = $this->extractTableName($fileName);
        
        if (empty($tableName)) {
            $this->error("Could not determine target table from filename: {$fileName}");
            $this->line("File should be named like: table_name.xlsx");
            return false;
        }
        
        $this->info("Target table: {$tableName}");
        
        // Check if table exists
        if (!Schema::hasTable($tableName)) {
            $this->error("Table '{$tableName}' does not exist in the database.");
            return false;
        }
        
        // Get table primary key(s)
        $primaryKey = $this->getPrimaryKey($tableName);
        if (empty($primaryKey) && $strategy !== 'add') {
            $this->warn("No primary key found for table '{$tableName}'. Only 'add' strategy is supported.");
            if (!$this->confirm("Continue with 'add' strategy?", true)) {
                return false;
            }
            $strategy = 'add';
        }
        
        // Get table columns and types
        $columns = $this->getColumns($tableName);
        if (empty($columns)) {
            $this->error("No valid columns found for table '{$tableName}'.");
            return false;
        }
        
        // Read Excel file
        try {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $highestRow = $worksheet->getHighestRow();
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
            
            // Read header row (column names)
            $headers = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $cellValue = $worksheet->getCell($colLetter . '1')->getValue();
                if ($cellValue) {
                    $headers[$col] = $cellValue;
                }
            }
            
            // Check that primary key column is included for update/keep strategies
            if ($strategy !== 'add' && !empty($primaryKey)) {
                $missingPrimaryKeys = array_diff($primaryKey, $headers);
                if (!empty($missingPrimaryKeys)) {
                    $this->error("Primary key column(s) missing: " . implode(', ', $missingPrimaryKeys));
                    $this->line("Required for '{$strategy}' strategy.");
                    return false;
                }
            }
            
            // Validate headers against table columns
            $invalidHeaders = array_diff($headers, array_keys($columns));
            if (!empty($invalidHeaders)) {
                $this->warn("Some headers in the Excel file don't match table columns: " . implode(', ', $invalidHeaders));
            }
            
            $missingHeaders = array_diff(array_keys($columns), $headers);
            // IDを除外した上で足りないカラムをチェック
            $missingNonIdHeaders = array_diff($missingHeaders, ['id']);
            if (!empty($missingNonIdHeaders)) {
                // $this->warn("Some table columns are missing in the Excel file: " . implode(', ', $missingNonIdHeaders));
            } else if (!empty($missingHeaders) && count($missingHeaders) === 1 && in_array('id', $missingHeaders)) {
                // $this->info("Only 'id' column is missing, which is expected for new records.");
            }
            
            // Prepare for import
            $rowsToProcess = [];
            $rowsWithErrors = [];
            $totalRows = $highestRow - 1; // Exclude header row
            
            if ($totalRows <= 0) {
                $this->info("No data rows found in the Excel file.");
                $this->moveToProcessed($filePath);
                return true; // Consider this a success, just no data
            }
            
            // Process data rows
            for ($row = 2; $row <= $highestRow; $row++) {
                $rowData = [];
                $rowHasData = false;
                $rowHasErrors = false;
                $rowErrors = [];
                
                // Get all cells in the row
                foreach ($headers as $col => $columnName) {
                    if (!isset($columns[$columnName])) {
                        // Skip columns not in the table
                        continue;
                    }
                    
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                    $cell = $worksheet->getCell($colLetter . $row);
                    $cellValue = $cell->getValue();
                    
                    // Skip entirely empty rows
                    if ($cellValue !== null && $cellValue !== '') {
                        $rowHasData = true;
                    }
                    
                    // Validate and convert cell value based on column type
                    $columnType = $columns[$columnName];
                    $validatedValue = $this->validateAndConvertValue($cell, $columnType, $columnName);
                    
                    if (isset($validatedValue['error'])) {
                        $rowHasErrors = true;
                        $rowErrors[$columnName] = $validatedValue['error'];
                    } else {
                        $rowData[$columnName] = $validatedValue['value'];
                    }
                }
                
                // Only process rows that have data
                if ($rowHasData) {
                    if ($rowHasErrors) {
                        $rowsWithErrors[] = [
                            'row' => $row,
                            'data' => $rowData,
                            'errors' => $rowErrors
                        ];
                    } else {
                        $rowsToProcess[] = $rowData;
                    }
                }
            }
            
            $this->newLine(2);
            
            // Additional debug info to help troubleshoot
            $this->line("Total rows with data: " . (count($rowsToProcess) + count($rowsWithErrors)));
            $this->line("Valid rows ready for import: " . count($rowsToProcess));
            
            // Report errors
            if (!empty($rowsWithErrors)) {
                $this->warn(count($rowsWithErrors) . " rows have validation errors:");
                foreach ($rowsWithErrors as $index => $rowInfo) {
                    if ($index >= 5) {
                        $this->line("...and " . (count($rowsWithErrors) - 5) . " more rows with errors.");
                        break;
                    }
                    
                    $this->line("Row {$rowInfo['row']}:");
                    foreach ($rowInfo['errors'] as $column => $error) {
                        $this->line("  - {$column}: {$error}");
                    }
                }
                
                if (!$this->confirm('Do you want to continue with valid rows?', true)) {
                    $this->info('Import cancelled for this file.');
                    return false;
                }
            }
            
            // Process the data based on selected strategy
            $stats = [
                'inserted' => 0,
                'updated' => 0,
                'skipped' => 0,
                'error' => 0
            ];
            
            $this->info("Importing " . count($rowsToProcess) . " valid rows with '{$strategy}' strategy...");
            
            if (count($rowsToProcess) === 0) {
                $this->warn("No valid rows to import. Check if your Excel file has valid data.");
                // 処理には成功したが、データは0件のケース
                $this->moveToProcessed($filePath);
                return true;
            }
            
            DB::beginTransaction();
            try {
                foreach ($rowsToProcess as $rowData) {
                    // 現在日時を追加
                    if (Schema::hasColumn($tableName, 'created_at') && !isset($rowData['created_at'])) {
                        $rowData['created_at'] = now();
                    }
                    
                    if (Schema::hasColumn($tableName, 'updated_at')) {
                        $rowData['updated_at'] = now();
                    }
                    
                    switch ($strategy) {
                        case 'add':
                            // Simple insert
                            DB::table($tableName)->insert($rowData);
                            $stats['inserted']++;
                            break;
                            
                        case 'update':
                            // Try to update, then insert if no record exists
                            $whereConditions = [];
                            foreach ($primaryKey as $key) {
                                if (isset($rowData[$key])) {
                                    $whereConditions[$key] = $rowData[$key];
                                }
                            }
                            
                            if (empty($whereConditions)) {
                                // Can't identify record, so just insert
                                DB::table($tableName)->insert($rowData);
                                $stats['inserted']++;
                            } else {
                                $exists = DB::table($tableName)->where($whereConditions)->exists();
                                if ($exists) {
                                    DB::table($tableName)->where($whereConditions)->update($rowData);
                                    $stats['updated']++;
                                } else {
                                    DB::table($tableName)->insert($rowData);
                                    $stats['inserted']++;
                                }
                            }
                            break;
                            
                        case 'keep':
                            // Only insert if record doesn't exist
                            $whereConditions = [];
                            foreach ($primaryKey as $key) {
                                if (isset($rowData[$key])) {
                                    $whereConditions[$key] = $rowData[$key];
                                }
                            }
                            
                            if (empty($whereConditions)) {
                                // Can't identify record, so just insert
                                DB::table($tableName)->insert($rowData);
                                $stats['inserted']++;
                            } else {
                                $exists = DB::table($tableName)->where($whereConditions)->exists();
                                if ($exists) {
                                    $stats['skipped']++;
                                } else {
                                    DB::table($tableName)->insert($rowData);
                                    $stats['inserted']++;
                                }
                            }
                            break;
                    }
                }
                
                DB::commit();
                
                // Report results
                $this->info("Import completed for '{$tableName}':");
                $this->line(" - Inserted: {$stats['inserted']} rows");
                if ($strategy !== 'add') {
                    if ($strategy === 'update') {
                        $this->line(" - Updated: {$stats['updated']} rows");
                    } else {
                        $this->line(" - Skipped: {$stats['skipped']} rows");
                    }
                }
                
                // Move file to processed directory
                $this->moveToProcessed($filePath);
                
                return true;
            } catch (\Exception $e) {
                DB::rollBack();
                $this->error("Error during import: " . $e->getMessage());
                return false;
            }
            
        } catch (\Exception $e) {
            $this->error("Error reading Excel file: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get primary key column(s) for a table
     *
     * @param string $table
     * @return array
     */
    protected function getPrimaryKey(string $table): array {
        // For Laravel, we need to query the database directly
        try {
            $connection = config('database.default');
            $driver = config("database.connections.{$connection}.driver");
            
            if ($driver === 'mysql') {
                $database = config("database.connections.{$connection}.database");
                $results = DB::select("
                    SELECT COLUMN_NAME
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = '{$database}'
                    AND TABLE_NAME = '{$table}'
                    AND CONSTRAINT_NAME = 'PRIMARY'
                ");
                
                $primaryKeys = [];
                foreach ($results as $result) {
                    $primaryKeys[] = $result->COLUMN_NAME;
                }
                
                return $primaryKeys;
            } elseif ($driver === 'sqlite') {
                $results = DB::select("PRAGMA table_info('{$table}')");
                
                $primaryKeys = [];
                foreach ($results as $result) {
                    if ($result->pk) {
                        $primaryKeys[] = $result->name;
                    }
                }
                
                return $primaryKeys;
            } else {
                // For other drivers, try doctrine
                try {
                    $sm = Schema::getConnection()->getDoctrineSchemaManager();
                    $doctrineTable = $sm->listTableDetails($table);
                    $primaryKey = $doctrineTable->getPrimaryKey();
                    
                    return $primaryKey ? $primaryKey->getColumns() : [];
                } catch (\Exception $e) {
                    $this->warn("Could not determine primary key: " . $e->getMessage());
                    return [];
                }
            }
        } catch (\Exception $e) {
            $this->warn("Error getting primary key: " . $e->getMessage());
            return [];
        }
        
        return [];
    }
    
    /**
     * Ensure all necessary directories exist
     *
     * @return void
     */
    protected function ensureDirectories(): void {
        // Create directories if they don't exist
        foreach ([$this->baseDir, $this->importDir, $this->templateDir, $this->processedDir] as $dir) {
            if (!File::exists($dir)) {
                File::makeDirectory($dir, 0755, true);
            }
        }
    }
    
    /**
     * Move processed file to processed directory with table-specific subdirectory
     *
     * @param string $filePath
     * @return void
     */
    protected function moveToProcessed(string $filePath): void {
        try {
            // Get table name and create table-specific subdirectory
            $fileName = basename($filePath);
            $tableName = $this->extractTableName($fileName);
            $tableDir = $this->processedDir . '/' . $tableName;
            
            // Create the table directory if it doesn't exist
            if (!File::exists($tableDir)) {
                File::makeDirectory($tableDir, 0755, true);
            }
            
            // Create unique filename with timestamp
            $newFileName = pathinfo($fileName, PATHINFO_FILENAME) . '_' . 
                          date('Ymd_His') . '.' . 
                          pathinfo($fileName, PATHINFO_EXTENSION);
            
            $newPath = $tableDir . '/' . $newFileName;
            
            // Move file
            File::move($filePath, $newPath);
            $this->line("File moved to processed directory: {$tableName}");
        } catch (\Exception $e) {
            $this->warn("Could not move processed file: " . $e->getMessage());
        }
    }
    
    /**
     * Extract table name from filename.
     *
     * @param string $fileName
     * @return string|null
     */
    protected function extractTableName(string $fileName): ?string {
        // Simple pattern to extract table name from file: table_name.xlsx
        if (preg_match('/^(.+)\.xlsx$/', $fileName, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
    
    /**
     * Get columns for a table.
     *
     * @param string $table
     * @return array
     */
    protected function getColumns(string $table): array {
        // deleted_atのみをスキップ対象に変更
        $skipColumns = ['deleted_at'];
        $columns = [];

        try {
            $tableColumns = Schema::getColumnListing($table);
            
            foreach ($tableColumns as $column) {
                if (!in_array($column, $skipColumns)) {
                    $columns[$column] = Schema::getColumnType($table, $column);
                }
            }
        } catch (\Exception $e) {
            $this->error("Error getting columns for {$table}: " . $e->getMessage());
        }

        return $columns;
    }
        
    /**
     * Validate and convert cell value based on column type.
     *
     * @param \PhpOffice\PhpSpreadsheet\Cell\Cell $cell The cell object
     * @param string $type The column type
     * @param string $columnName The column name (for error messages)
     * @return array ['value' => mixed] or ['error' => string]
     */
    protected function validateAndConvertValue($cell, string $type, string $columnName): array {
        $value = $cell->getValue();
        
        // Empty values
        if ($value === null || $value === '') {
            return ['value' => null];
        }
        
        switch ($type) {
            case 'integer':
            case 'bigint':
                if (!is_numeric($value) || intval($value) != $value) {
                    return ['error' => "Must be an integer value"];
                }
                return ['value' => intval($value)];
                
            case 'decimal':
            case 'float':
            case 'double':
                if (!is_numeric($value)) {
                    return ['error' => "Must be a numeric value"];
                }
                return ['value' => floatval($value)];
                
            case 'boolean':
                // Handle various boolean representations
                if (is_bool($value)) {
                    return ['value' => $value];
                }
                if (is_string($value)) {
                    $lower = strtolower($value);
                    if (in_array($lower, ['true', 'yes', 'y', '1'])) {
                        return ['value' => true];
                    }
                    if (in_array($lower, ['false', 'no', 'n', '0'])) {
                        return ['value' => false];
                    }
                }
                if (is_numeric($value)) {
                    return ['value' => (bool)$value];
                }
                return ['error' => "Must be a boolean value (true/false, yes/no, 1/0)"];
                
            case 'date':
                try {
                    // Excel stores dates as numbers, so we need to check for that
                    if (is_numeric($value) && $cell->getDataType() == \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC) {
                        $dateValue = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value);
                        return ['value' => $dateValue->format('Y-m-d')];
                    }
                    
                    // Try to parse as date string
                    $date = date_create($value);
                    if (!$date) {
                        return ['error' => "Invalid date format"];
                    }
                    return ['value' => $date->format('Y-m-d')];
                } catch (\Exception $e) {
                    return ['error' => "Invalid date: " . $e->getMessage()];
                }
                
            case 'datetime':
                try {
                    // Excel stores dates as numbers, so we need to check for that
                    if (is_numeric($value) && $cell->getDataType() == \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC) {
                        $dateValue = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value);
                        return ['value' => $dateValue->format('Y-m-d H:i:s')];
                    }
                    
                    // Try to parse as date/time string
                    $date = date_create($value);
                    if (!$date) {
                        return ['error' => "Invalid datetime format"];
                    }
                    return ['value' => $date->format('Y-m-d H:i:s')];
                } catch (\Exception $e) {
                    return ['error' => "Invalid datetime: " . $e->getMessage()];
                }
                
            case 'string':
            case 'text':
                // Ensure value is a string
                return ['value' => (string)$value];
                
            case 'json':
                // If it's already a string, check if it's valid JSON
                if (is_string($value)) {
                    json_decode($value);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        return ['error' => "Invalid JSON format"];
                    }
                    return ['value' => $value];
                }
                
                // If it's not a string, try to encode it
                try {
                    $jsonValue = json_encode($value);
                    if ($jsonValue === false) {
                        return ['error' => "Could not convert to JSON"];
                    }
                    return ['value' => $jsonValue];
                } catch (\Exception $e) {
                    return ['error' => "JSON conversion error: " . $e->getMessage()];
                }
                
            default:
                // For other types, just convert to string
                return ['value' => (string)$value];
        }
    }
}