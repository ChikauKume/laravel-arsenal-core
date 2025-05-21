<?php

namespace Lac\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;

class ScaffoldCommand extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lac:scaffold {name* : The name(s) of the resource(s)}
                            {--hard-delete : Use hard delete instead of soft delete}
                            {--force : Overwrite existing files}
                            {--no-view : Skip generation of view files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a complete set of files for one or more resources (model, migration, controller, requests, service)';

    /**
     * The filesystem instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;

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
        // Get resource names
        $names = $this->argument('name');
        
        if (empty($names)) {
            $this->error('At least one resource name must be specified.');
            return Command::FAILURE;
        }

        // Create search helper trait if it doesn't exist
        // $this->createSearchHelperTrait();
        
        $this->info('Starting scaffold for ' . count($names) . ' resources: ' . implode(', ', $names));
        
        $progressBar = $this->output->createProgressBar(count($names));
        $progressBar->start();
        
        $success = true;
        $completedModels = [];
        $failedModels = [];
        
        try {
            foreach ($names as $name) {
                $this->info("\nProcessing resource: {$name}");
                
                try {
                    $this->scaffoldSingleResource($name);
                    $completedModels[] = $name;
                } catch (\Exception $e) {
                    $this->error("Failed to scaffold resource '{$name}': " . $e->getMessage());
                    $failedModels[] = $name;
                    $success = false;
                }
                
                $progressBar->advance();
            }
            
            $progressBar->finish();
            $this->line(''); // 改行
            
            $this->info("\nScaffold Summary:");
            if (!empty($completedModels)) {
                $this->info("✓ Successfully scaffolded: " . implode(', ', $completedModels));
            }
            if (!empty($failedModels)) {
                $this->error("✗ Failed to scaffold: " . implode(', ', $failedModels));
            }
            
            return $success ? Command::SUCCESS : Command::FAILURE;
        } catch (\Exception $e) {
            $this->error("Error during scaffolding: " . $e->getMessage());
            $this->error("File: " . $e->getFile() . " Line: " . $e->getLine());
            
            if ($this->option('verbose')) {
                $this->error("Stack trace:");
                $this->error($e->getTraceAsString());
            }
            
            return Command::FAILURE;
        }
    }

    /**
     * Scaffold a single resource.
     *
     * @param string $name
     * @return bool
     * @throws \Exception
     */
    protected function scaffoldSingleResource(string $name) {
        $className = Str::studly($name);
        $tableName = Str::plural(Str::snake($name));
        
        // Check for existing files
        $this->checkExistingFiles($className, $tableName);
        
        // Create necessary directories
        $this->createDirectories();

        // Ensure base Controller exists
        $this->checkAndCreateBaseController();
        
        // Generate files
        $this->createModel($name);
        $this->createMigration($name);
        $this->createController($name);
        $this->createService($name);
        $this->createRequests($name);
        $this->createSeeder($name);
        $this->createFactory($name);
        
        // Generate views only if --no-view option is NOT specified
        if (!$this->option('no-view')) {
            $this->createViews($name);
        }
        
        $this->info("Scaffold for '{$name}' completed successfully!");
        
        return true;
    }

    /**
     * Auxiliary methods for converting to relative paths.
     * 
     * @param string $path
     * @return string
     */
    protected function getRelativePath(string $path): string {
        return str_replace(base_path() . '/', '', $path);
    }

    /**
     * Create necessary directories.
     *
     * @return void
     */
    protected function createDirectories() {
        $directories = [
            app_path('Http/Controllers'),
            app_path('Services'),
            app_path('Models'),
            app_path('Http/Requests'),
            // app_path('Services/Helpers'),
            resource_path('css'),
            resource_path('js'),
        ];
        
        foreach ($directories as $directory) {
            if (!$this->files->isDirectory($directory)) {
                $this->files->makeDirectory($directory, 0755, true);
                $this->info("Created directory: " . $this->getRelativePath($directory));
            }
        }
    }

    /**
     * Create model for the resource.
     *
     * @param string $name
     * @return bool
     */
    protected function createModel(string $name) {
        $modelNamespace = config('lac.model_namespace', 'App\\Models');
        $tableName = Str::snake(Str::pluralStudly($name));
        $className = Str::studly($name);
        
        // Define path for model
        $path = app_path('Models/' . $className . '.php');
        
        $this->info("Creating model for '{$name}'...");
        
        try {
            // Create Models directory if it doesn't exist
            $directory = dirname($path);
            if (!$this->files->isDirectory($directory)) {
                $this->files->makeDirectory($directory, 0755, true);
                $this->info("Created directory: " . $this->getRelativePath($directory));
            }
            
            // Skip if file exists and --force option is not specified
            if ($this->files->exists($path) && !$this->option('force')) {
                $this->info('Model already exists: ' . $className . ' (Skipping)');
                return true;
            }
            
            // Get the model stub file
            $stub = $this->getStub('model');
            
            // Replace variables in the stub file
            $stub = str_replace('{{ namespace }}', $modelNamespace, $stub);
            $stub = str_replace('{{ class }}', $className, $stub);
            $stub = str_replace('{{ table }}', $tableName, $stub);
            
            // Add generation timestamp
            $generatedAt = date('Y-m-d H:i:s');
            $stub = str_replace('{{ generatedAt }}', $generatedAt, $stub);
            
            // Handle soft delete option
            if ($this->option('hard-delete')) {
                $stub = str_replace('use Illuminate\Database\Eloquent\SoftDeletes;', '// use Illuminate\Database\Eloquent\SoftDeletes;', $stub);
                $stub = str_replace('use HasFactory, SoftDeletes;', 'use HasFactory;', $stub);
                // Remove deleted_at cast
                $stub = preg_replace("/\s*'deleted_at' => 'datetime',/", '', $stub);
            }
            
            // Save the model file
            $this->files->put($path, $stub);
            
            $this->info('Model created: ' . $className);
            
            return true;
        } catch (\Exception $e) {
            $this->error("Error creating model: " . $e->getMessage());
            throw $e; // Re-throw to be handled by the caller
        }
    }

    /**
     * Create a migration file for the model.
     *
     * @param string $name
     * @return bool
     */
    protected function createMigration(string $name): bool {
        $tableName = Str::plural(Str::snake($name));
        
        $this->info("Creating migration for table '{$tableName}'...");
        
        try {
            // Get the migration stub file
            $stub = $this->getStub('migration');
            
            // Replace variables in the stub file
            $stub = str_replace('{{ table }}', $tableName, $stub);
            
            // Handle soft delete option
            if ($this->option('hard-delete')) {
                $stub = str_replace('{{ softDeletes }}', '', $stub);
            } else {
                $stub = str_replace('{{ softDeletes }}', '$table->softDeletes();', $stub);
            }
            
            // Generate migration filename with current timestamp
            $filename = date('Y_m_d_His') . '_create_' . $tableName . '_table.php';
            $path = database_path('migrations/' . $filename);
            
            // Create directory if it doesn't exist
            $directory = dirname($path);
            if (!$this->files->isDirectory($directory)) {
                $this->files->makeDirectory($directory, 0755, true);
                $this->info("Created directory: " . $this->getRelativePath($directory));
            }
            
            $this->info("Writing new migration file to: " . $this->getRelativePath($path));
            
            // Save the migration file
            file_put_contents($path, $stub);
            
            $this->info("Migration created successfully: " . basename($path));
            return true;
        } catch (\Exception $e) {
            $this->error("Error creating migration: " . $e->getMessage());
            throw $e; // Re-throw to be handled by the caller
        }
    }

    /**
     * Create view files for the model.
     *
     * @param string $name
     * @return bool
     */
    protected function createViews(string $name): bool {
        $className = Str::studly($name);
        $viewDirName = Str::kebab(Str::plural($className)); // e.g., blog-posts
        
        $this->info("Creating views for '{$name}'...");
        
        try {
            // Define view directory path
            $viewsDir = resource_path('views/' . $viewDirName);
            
            // Skip if views directory exists
            if ($this->files->isDirectory($viewsDir)) {
                $this->info("Views directory already exists for {$viewDirName}. Skipping view creation.");
                
                // ビューが存在してもCSSとJSファイルは作成
                $this->createCssFiles($name);
                $this->createJsFiles($name);
                
                // app.cssとapp.jsにインポート文を追加
                $this->updateAppCssImports($name);
                $this->updateAppJsImports($name);
                
                return false;
            }
            
            // Create the views directory
            $this->files->makeDirectory($viewsDir, 0755, true);
            $this->info("Created views directory: " . $this->getRelativePath($viewsDir));
            
            // Create basic view files
            $this->createViewFile($name, 'index', $viewsDir);
            $this->createViewFile($name, 'create', $viewsDir);
            $this->createViewFile($name, 'show', $viewsDir);
            $this->createViewFile($name, 'edit', $viewsDir);
            
            // Create layouts/app.blade.php if it doesn't exist
            $this->createLayoutIfNotExists();
            
            // Create CSS and JS files
            $this->createCssFiles($name);
            $this->createJsFiles($name);
            
            // Update app.css and app.js with imports
            $this->updateAppCssImports($name);
            $this->updateAppJsImports($name);
            
            $this->info('Views created in: ' . $this->getRelativePath($viewsDir));
            return true;
        } catch (\Exception $e) {
            $this->error("Error creating views: " . $e->getMessage());
            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }
            return false;
        }
    }

    /**
     * Create layouts/app.blade.php if it doesn't exist.
     *
     * @return void
     */
    protected function createLayoutIfNotExists(): void {
        $layoutsDir = resource_path('views/layouts');
        $layoutFile = $layoutsDir . '/app.blade.php';
        
        if (!$this->files->exists($layoutFile)) {
            // Create the directory if it doesn't exist
            if (!$this->files->isDirectory($layoutsDir)) {
                $this->files->makeDirectory($layoutsDir, 0755, true);
                $this->info("Created directory: " . $this->getRelativePath($layoutsDir));
            }
            
            // Create app.blade.php
            $this->createLayoutFile($layoutsDir);
            $this->info('Layout file created: layouts/app.blade.php');
        }
    }

    /**
     * Create CSS files for each view of the model.
     *
     * @param string $name
     * @return void
     */
    protected function createCssFiles(string $name): void {
        $className = Str::studly($name);
        $viewPrefix = Str::kebab(Str::plural($className));
        
        // Define CSS directory
        $cssDir = resource_path('css/' . $viewPrefix);
        
        // Create directory if it doesn't exist
        if (!$this->files->isDirectory($cssDir)) {
            $this->files->makeDirectory($cssDir, 0755, true);
            $this->info("Created CSS directory: " . $this->getRelativePath($cssDir));
        }
        
        // Create CSS files for each view - simply empty files
        $viewTypes = ['index', 'create', 'show', 'edit'];
        
        foreach ($viewTypes as $viewType) {
            $fileName = $viewType . '.css';
            $path = $cssDir . '/' . $fileName;
            
            // Skip if file already exists
            if ($this->files->exists($path)) {
                $this->info('CSS file already exists: ' . $fileName . ' (Skipping)');
                continue;
            }
            
            // Create empty CSS file
            $this->files->put($path, '');
            $this->info("Created empty CSS file: {$fileName}");
        }
    }

    /**
     * Create JavaScript files for the model.
     *
     * @param string $name
     * @return void
     */
    protected function createJsFiles(string $name): void {
        $className = Str::studly($name);
        $viewPrefix = Str::kebab(Str::plural($className));
        
        // Define JS directory
        $jsDir = resource_path('js/' . $viewPrefix);
        
        // Create directory if it doesn't exist
        if (!$this->files->isDirectory($jsDir)) {
            $this->files->makeDirectory($jsDir, 0755, true);
            $this->info("Created JS directory: " . $this->getRelativePath($jsDir));
        }
        
        // Create JS files for each view - simply empty files
        $viewTypes = ['index', 'create', 'show', 'edit'];
        
        foreach ($viewTypes as $viewType) {
            $fileName = $viewType . '.js';
            $path = $jsDir . '/' . $fileName;
            
            // Skip if file already exists
            if ($this->files->exists($path)) {
                $this->info('JavaScript file already exists: ' . $fileName . ' (Skipping)');
                continue;
            }
            
            // Create empty JS file
            $this->files->put($path, '');
            $this->info("Created empty JavaScript file: {$fileName}");
        }
    }    

    /**
     * Check if the base controller exists, and create it if it doesn't.
     *
     * @return void
     */
    protected function checkAndCreateBaseController(): void {
        $controllerPath = app_path('Http/Controllers/Controller.php');
        
        if (!$this->files->exists($controllerPath)) {
            $this->info('Base Controller not found. Creating it...');
            
            // Create the directory if it doesn't exist
            $directory = dirname($controllerPath);
            if (!$this->files->isDirectory($directory)) {
                $this->files->makeDirectory($directory, 0755, true);
                $this->info("Created directory: {$directory}");
            }
            
            // Get the controller-base stub
            $stub = $this->getStub('controller-base');
            
            // Add generation timestamp
            $generatedAt = date('Y-m-d H:i:s');
            $stub = str_replace('{{ generatedAt }}', $generatedAt, $stub);
            
            // Save the controller file
            $this->files->put($controllerPath, $stub);
            
            $this->info('Base Controller created successfully');
        }
    }

    /**
     * Check for existing files and ask for confirmation to overwrite.
     *
     * @param string $className
     * @param string $tableName
     * @return void
     * @throws \Exception
     */
    protected function checkExistingFiles($className, $tableName) {
        // Model file
        $modelPath = app_path('Models/' . $className . '.php');
        $modelExists = $this->files->exists($modelPath);
        
        // Migration check
        $existingMigration = $this->findExistingMigration("create_{$tableName}_table");
        $migrationExists = $existingMigration !== null;
        
        // Controller check
        $controllerPath = app_path('Http/Controllers/' . $className . 'Controller.php');
        $controllerExists = $this->files->exists($controllerPath);
        
        // Service check
        $servicePath = app_path('Services/' . $className . 'Service.php');
        $serviceExists = $this->files->exists($servicePath);
        
        // Requests check
        $storeRequestPath = app_path('Http/Requests/' . $className . '/StoreRequest.php');
        $updateRequestPath = app_path('Http/Requests/' . $className . '/UpdateRequest.php');
        $requestExists = $this->files->exists($storeRequestPath) || $this->files->exists($updateRequestPath);
        
        // Seeder check
        $seederPath = database_path('seeders/' . $className . 'Seeder.php');
        $seederExists = $this->files->exists($seederPath);
        
        // Factory check
        $factoryPath = database_path('factories/' . $className . 'Factory.php');
        $factoryExists = $this->files->exists($factoryPath);
        
        // Views check - only check if --no-view is not specified
        $viewsExist = false;
        if (!$this->option('no-view')) {
            $viewsDir = resource_path('views/' . Str::kebab(Str::plural($className)));
            $viewsExist = $this->files->isDirectory($viewsDir);
        }
        
        // Check if any files exist and require confirmation
        if (($modelExists || $migrationExists || $controllerExists || 
             $serviceExists || $requestExists || $seederExists || $factoryExists || $viewsExist) && 
            !$this->option('force')) {
            
            // Create list of existing files
            $existingFiles = [];
            if ($modelExists) $existingFiles[] = "Model";
            if ($migrationExists) $existingFiles[] = "Migration";
            if ($controllerExists) $existingFiles[] = "Controller";
            if ($serviceExists) $existingFiles[] = "Service";
            if ($requestExists) $existingFiles[] = "Request";
            if ($seederExists) $existingFiles[] = "Seeder";
            if ($factoryExists) $existingFiles[] = "Factory";
            if ($viewsExist) $existingFiles[] = "Views";
            
            $fileList = implode(', ', $existingFiles);
            $message = "Some files for '{$className}' already exist: {$fileList}";
            
            if (!$this->confirm($message . '. Would you like to continue and overwrite these files?')) {
                $this->info("Scaffold for '{$className}' has been cancelled.");
                throw new \Exception("Scaffold cancelled by user.");
            }
            
            $this->info("Proceeding with scaffold for '{$className}'. Existing files will be overwritten.");
        }
        
        // Remove existing migration if needed
        if ($migrationExists && $existingMigration) {
            $this->info("Removing existing migration file: " . basename($existingMigration));
            if (unlink($existingMigration)) {
                $this->info("Existing migration file removed successfully.");
            } else {
                $this->warn("Failed to remove existing migration file. Continuing anyway...");
            }
        }
    }


    /**
     * Find an existing migration file for the given name.
     *
     * @param string $name
     * @return string|null
     */
    protected function findExistingMigration(string $name): ?string {
        $files = glob(database_path('migrations/*'));
        
        foreach ($files as $file) {
            if (str_contains($file, $name)) {
                return $file;
            }
        }
        
        return null;
    }
    
    /**
     * Create controller for the model.
     *
     * @param string $name
     * @return bool
     */
    protected function createController(string $name): bool {
        $className = Str::studly($name);
        $controllerName = $className . 'Controller';
        $modelName = $className;
        
        $this->info("Creating controller for '{$name}'...");
        
        try {
            // Define controller path
            $path = app_path('Http/Controllers/' . $controllerName . '.php');
            $controllerNamespace = config('lac.controller_namespace', 'App\\Http\\Controllers');
            
            // Skip if file exists and --force option is not specified
            if ($this->files->exists($path) && !$this->option('force')) {
                $this->info('Controller already exists: ' . $controllerName . ' (Skipping)');
                return true;
            }
            
            // Create directory if it doesn't exist
            $directory = dirname($path);
            if (!$this->files->isDirectory($directory)) {
                $this->files->makeDirectory($directory, 0755, true);
                $this->info("Created directory: {$directory}");
            }
            
            // Get the controller stub
            $stub = $this->getStub('controller');
            
            // Replace variables in the stub
            $stub = str_replace('{{ namespace }}', $controllerNamespace, $stub);
            $stub = str_replace('{{ class }}', $controllerName, $stub);
            $stub = str_replace('{{ model }}', $modelName, $stub);
            $stub = str_replace('{{ modelVariable }}', Str::camel($modelName), $stub);
            $stub = str_replace('{{ modelVariablePlural }}', Str::plural(Str::camel($modelName)), $stub);
            $stub = str_replace('{{ modelNamespace }}', config('lac.model_namespace', 'App\\Models'), $stub);
            
            // Add generation timestamp
            $generatedAt = date('Y-m-d H:i:s');
            $stub = str_replace('{{ generatedAt }}', $generatedAt, $stub);
            
            // Path parameter for route model binding
            $routeParameter = Str::snake($modelName);
            $stub = str_replace('{{ routeParameter }}', $routeParameter, $stub);
            
            // Save the controller file
            $this->files->put($path, $stub);
            
            $this->info('Controller created: ' . $controllerName);
            return true;
            
        } catch (\Exception $e) {
            $this->error("Error creating controller: " . $e->getMessage());
            throw $e; // Re-throw to be handled by the caller
        }
    }

    /**
     * Create search helper trait if it doesn't exist.
     *
     * @return void
     */
    protected function createSearchHelperTrait(): void {
        // Define the path for the search helper trait
        $searchHelperTraitPath = app_path('Services/Helpers/SearchHelperTrait.php');
        
        // Check if the file already exists
        if ($this->files->exists($searchHelperTraitPath)) {
            $this->info('Search Helper Trait already exists. Skipping creation.');
            return;
        }
        
        // Create the directory if it doesn't exist
        $directory = dirname($searchHelperTraitPath);
        if (!$this->files->isDirectory($directory)) {
            $this->files->makeDirectory($directory, 0755, true);
            $this->info("Created directory: {$directory}");
        }
        
        try {
            // Get the helper stub
            $stub = $this->getStub('helpers/search');
            
            // Replace namespace if needed
            $serviceNamespace = config('lac.service_namespace', 'App\\Services');
            $helperNamespace = $serviceNamespace . '\\Helpers';
            $stub = str_replace('{{ namespace }}', $helperNamespace, $stub);
            
            // Add creation timestamp
            $createdAt = date('Y-m-d H:i:s');
            $stub = str_replace('{{ createdAt }}', $createdAt, $stub);
            
            // Save the helper trait file
            $this->files->put($searchHelperTraitPath, $stub);
            
            $this->info('Created Search Helper Trait: SearchHelperTrait');
        } catch (\Exception $e) {
            // If helper stub not found, try fallback stub
            try {
                $stub = $this->getStub('search-helper');
                
                // Replace namespace if needed
                $serviceNamespace = config('lac.service_namespace', 'App\\Services');
                $helperNamespace = $serviceNamespace . '\\Helpers';
                $stub = str_replace('{{ namespace }}', $helperNamespace, $stub);
                
                // Add creation timestamp
                $createdAt = date('Y-m-d H:i:s');
                $stub = str_replace('{{ createdAt }}', $createdAt, $stub);
                
                // Save the helper trait file
                $this->files->put($searchHelperTraitPath, $stub);
                
                $this->info('Created Search Helper Trait from fallback stub: SearchHelperTrait');
            } catch (\Exception $e2) {
                $this->error("Failed to create SearchHelperTrait: " . $e2->getMessage());
                $this->warn("Please create SearchHelperTrait manually or add the required stub file.");
                throw $e2;
            }
        }
    }

    /**
     * Create service for the model.
     *
     * @param string $name
     * @return bool
     */
    protected function createService(string $name): bool {
        $className = Str::studly($name);
        $serviceName = $className . 'Service';
        $modelName = $className;
        
        $this->info("Creating service for '{$name}'...");
        
        try {
            // Define service path
            $path = app_path('Services/' . $serviceName . '.php');
            $serviceNamespace = config('lac.service_namespace', 'App\\Services');
            
            // Skip if file exists and --force option is not specified
            if ($this->files->exists($path) && !$this->option('force')) {
                $this->info('Service already exists: ' . $serviceName . ' (Skipping)');
                return true;
            }
            
            // Create directory if it doesn't exist
            $directory = dirname($path);
            if (!$this->files->isDirectory($directory)) {
                $this->files->makeDirectory($directory, 0755, true);
                $this->info("Created directory: {$directory}");
            }
            
            // Get the service stub
            $stub = $this->getStub('service');
            
            // Replace variables in the stub
            $stub = str_replace('{{ namespace }}', $serviceNamespace, $stub);
            $stub = str_replace('{{ class }}', $serviceName, $stub);
            $stub = str_replace('{{ model }}', $modelName, $stub);
            $stub = str_replace('{{ modelVariable }}', Str::camel($modelName), $stub);
            $stub = str_replace('{{ modelVariablePlural }}', Str::plural(Str::camel($modelName)), $stub);
            $stub = str_replace('{{ modelNamespace }}', config('lac.model_namespace', 'App\\Models'), $stub);
            
            // Add search helper trait use statement
            // $helperNamespace = $serviceNamespace . '\\Helpers\\SearchHelperTrait';
            // $useStatement = "use {$helperNamespace};";
            
            // Find position to add use statement (after namespace declaration)
            $namespacePos = strpos($stub, 'namespace');
            $semicolonPos = strpos($stub, ';', $namespacePos);
            $afterNamespace = $semicolonPos + 1;
            
            // Insert use statement after namespace
            // $stub = substr_replace($stub, "\n\n" . $useStatement, $afterNamespace, 0);
            
            // // Add use trait statement inside class
            // $classStartPos = strpos($stub, '{', strpos($stub, 'class'));
            // $afterClassStart = $classStartPos + 1;
            // $stub = substr_replace($stub, "\n    use SearchHelperTrait;\n", $afterClassStart, 0);
            
            // Add creation timestamp
            $generatedAt = date('Y-m-d H:i:s');
            $stub = str_replace('{{ generatedAt }}', $generatedAt, $stub);
            
            // Table name
            $tableName = Str::snake(Str::pluralStudly($name));
            $stub = str_replace('{{ table }}', $tableName, $stub);
            
            // Save the service file
            $this->files->put($path, $stub);
            
            $this->info('Service created: ' . $serviceName);
            return true;
            
        } catch (\Exception $e) {
            $this->error("Error creating service: " . $e->getMessage());
            throw $e; // Re-throw to be handled by the caller
        }
    }

    /**
     * Create request classes for the model.
     *
     * @param string $name
     * @return bool
     */
    protected function createRequests(string $name): bool {
        $className = Str::studly($name);
        
        $this->info("Creating request classes for '{$name}'...");
        
        try {
            // Define request namespace and directory
            $requestNamespace = config('lac.request_namespace', 'App\\Http\\Requests');
            $requestDir = app_path('Http/Requests/' . $className);
            
            // Create directory if it doesn't exist
            if (!$this->files->isDirectory($requestDir)) {
                $this->files->makeDirectory($requestDir, 0755, true);
                $this->info("Created directory: {$requestDir}");
            }
            
            // Create StoreRequest
            $storeRequestPath = $requestDir . '/StoreRequest.php';
            $storeRequestNamespace = $requestNamespace . '\\' . $className;
            
            $this->createRequestFile($storeRequestPath, $storeRequestNamespace, $className, 'store');
            $this->info('Store Request created: ' . $className . '\StoreRequest');
            
            // Create UpdateRequest
            $updateRequestPath = $requestDir . '/UpdateRequest.php';
            
            $this->createRequestFile($updateRequestPath, $storeRequestNamespace, $className, 'update');
            $this->info('Update Request created: ' . $className . '\UpdateRequest');
            
            return true;
        } catch (\Exception $e) {
            $this->error("Error creating request classes: " . $e->getMessage());
            throw $e; // Re-throw to be handled by the caller
        }
    }

    /**
     * Create a specific request file.
     *
     * @param string $path
     * @param string $namespace
     * @param string $modelName
     * @param string $type
     * @return void
     */
    protected function createRequestFile(string $path, string $namespace, string $modelName, string $type): void {
        // Skip if file exists and --force option is not specified
        if ($this->files->exists($path) && !$this->option('force')) {
            $this->info($type . ' Request already exists: ' . $modelName . '\\' . ucfirst($type) . 'Request (Skipping)');
            return;
        }
        
        // Get the request stub
        $stub = $this->getStub('request');
        
        // Replace variables in the stub file
        $stub = str_replace('{{ namespace }}', $namespace, $stub);
        $stub = str_replace('{{ class }}', ucfirst($type) . 'Request', $stub);
        $stub = str_replace('{{ model }}', $modelName, $stub);
        $stub = str_replace('{{ modelVariable }}', Str::camel($modelName), $stub);
        
        // Table name
        $tableName = Str::snake(Str::pluralStudly($modelName));
        $stub = str_replace('{{ table }}', $tableName, $stub);
        
        // Save the request file
        $this->files->put($path, $stub);
    }

    /**
     * Create a seeder class for the model.
     *
     * @param string $name
     * @return bool
     */
    protected function createSeeder(string $name): bool {
        $className = Str::studly($name);
        $seederName = $className . 'Seeder';
        
        $this->info("Creating seeder for '{$name}'...");
        
        try {
            // Define seeder file path
            $path = database_path('seeders/' . $seederName . '.php');
            
            // Skip if file exists and --force option is not specified
            if ($this->files->exists($path) && !$this->option('force')) {
                $this->info('Seeder already exists: ' . $seederName . ' (Skipping)');
                return true;
            }
            
            // Get the seeder stub
            $stub = $this->getStub('seeder');
            
            // Replace variables in the stub
            $stub = str_replace('{{ class }}', $seederName, $stub);
            $stub = str_replace('{{ model }}', $className, $stub);
            $stub = str_replace('{{ modelVariable }}', Str::camel($className), $stub);
            $stub = str_replace('{{ namespace }}', 'Database\\Seeders', $stub);
            $stub = str_replace('{{ rootNamespace }}', $this->laravel->getNamespace(), $stub);
            
            // Create directory if it doesn't exist
            $directory = dirname($path);
            if (!$this->files->isDirectory($directory)) {
                $this->files->makeDirectory($directory, 0755, true);
                $this->info("Created directory: {$directory}");
            }
            
            // Save the seeder file
            $this->files->put($path, $stub);
            
            $this->info('Seeder created: ' . $seederName);
            
            // Register seeder in DatabaseSeeder.php
            // $registrationResult = $this->registerSeederInDatabaseSeeder($seederName);
            
            // Suggest alternative if registration fails
            // if (!$registrationResult) {
            //     $this->info('Note: Seeder was not registered in DatabaseSeeder.php.');
            //     $this->info('You can register all seeders later using "php artisan lac:sync"');
            // }
            
            return true;
        } catch (\Exception $e) {
            $this->error("Error creating seeder: " . $e->getMessage());
            throw $e; // Re-throw to be handled by the caller
        }
    }

    /**
     * Register the new seeder in DatabaseSeeder.php file.
     *
     * @param string $seederName The seeder name to register
     * @return bool Success status
     */
    protected function registerSeederInDatabaseSeeder(?string $seederName = null): bool {
        $databaseSeederPath = database_path('seeders/DatabaseSeeder.php');
        
        // Create DatabaseSeeder.php if it doesn't exist
        if (!$this->files->exists($databaseSeederPath)) {
            $this->info('DatabaseSeeder.php not found. Creating it...');
            
            // Create the seeders directory if it doesn't exist
            $seedersDir = database_path('seeders');
            if (!$this->files->isDirectory($seedersDir)) {
                $this->files->makeDirectory($seedersDir, 0755, true);
                $this->info("Created directory: {$seedersDir}");
            }
            
            // Get the DatabaseSeeder stub
            $stub = $this->getStub('database-seeder');
            
            // Replace namespace if needed
            $stub = str_replace('{{ namespace }}', 'Database\\Seeders', $stub);
            
            // Save the file
            $this->files->put($databaseSeederPath, $stub);
            $this->info('DatabaseSeeder.php created.');
        }
        
        // Get the current content of DatabaseSeeder
        $content = $this->files->get($databaseSeederPath);
        
        // Get all available seeder files
        $seedersPath = database_path('seeders');
        $files = $this->files->files($seedersPath);
        
        $seederClasses = [];
        foreach ($files as $file) {
            $filename = pathinfo($file, PATHINFO_FILENAME);
            
            // Exclude DatabaseSeeder itself
            if ($filename !== 'DatabaseSeeder') {
                $seederClasses[] = $filename;
            }
        }
        
        if (empty($seederClasses)) {
            $this->info('No seeder files found to register.');
            return true;
        }
        
        // Find the run() method content
        $pattern = '/(public\s+function\s+run\s*\(\)\s*:?\s*void?\s*\{)(.*?)(\s*\})/s';
        
        if (preg_match($pattern, $content, $matches)) {
            $methodStart = $matches[1]; // public function run(): void {
            $methodBody = $matches[2];  // Method body content
            $methodEnd = $matches[3];   // }
            
            // Extract user custom code from the original method body
            $originalLines = explode("\n", $methodBody);
            $customCode = [];
            $existingCalls = [];
            
            foreach ($originalLines as $line) {
                // Detect $this->call() lines
                if (preg_match('/\$this->call\(([^:]+)::class\)/', $line, $callMatches)) {
                    $seederClass = trim($callMatches[1]);
                    $existingCalls[] = $seederClass;
                } 
                // Skip empty lines and indentation-only lines
                elseif (trim($line) !== '' && !preg_match('/^\s*$/', $line)) {
                    // Keep other lines as custom code
                    $customCode[] = $line;
                }
            }
            
            // Build new method body
            $newMethodBody = '';
            
            // Add custom code first if it exists
            if (!empty($customCode)) {
                $newMethodBody .= implode("\n", $customCode);
                $newMethodBody .= "\n\n";
            }
            
            // Add calls to all seeders
            foreach ($seederClasses as $seederClass) {
                $newMethodBody .= "        \$this->call({$seederClass}::class);\n";
            }
            
            // Replace with updated method content
            $updatedContent = str_replace(
                $methodStart . $methodBody . $methodEnd,
                $methodStart . "\n" . $newMethodBody . "    " . $methodEnd,
                $content
            );
            
            // Save updated content
            $this->files->put($databaseSeederPath, $updatedContent);
            
            if ($seederName) {
                $this->info("Seeder '{$seederName}' registered in DatabaseSeeder.php");
            } else {
                $this->info('All seeders synchronized in DatabaseSeeder.php');
            }
            
            return true;
        } else {
            $this->warn('Could not find run() method in DatabaseSeeder.php. Unable to register seeders.');
            return false;
        }
    }

    /**
     * Create a factory for the model.
     *
     * @param string $name
     * @return bool
     */
    protected function createFactory(string $name): bool {
        $className = Str::studly($name);
        $factoryName = $className . 'Factory';
        
        $this->info("Creating factory for '{$name}'...");
        
        try {
            // Define factory file path
            $path = database_path('factories/' . $factoryName . '.php');
            
            // Skip if file exists and --force option is not specified
            if ($this->files->exists($path) && !$this->option('force')) {
                $this->info('Factory already exists: ' . $factoryName . ' (Skipping)');
                return true;
            }
            
            // Create directory if it doesn't exist
            $directory = dirname($path);
            if (!$this->files->isDirectory($directory)) {
                $this->files->makeDirectory($directory, 0755, true);
                $this->info("Created directory: {$directory}");
            }
            
            // Get the factory stub
            $stub = $this->getStub('factory');
            
            // Replace variables in the stub
            $stub = str_replace('{{ namespace }}', 'Database\\Factories', $stub);
            $stub = str_replace('{{ class }}', $factoryName, $stub);
            $stub = str_replace('{{ model }}', $className, $stub);
            $stub = str_replace('{{ modelNamespace }}', config('lac.model_namespace', 'App\\Models'), $stub);
            
            // Initialize with empty factory definition
            $stub = str_replace('{{ fakerFields }}', '', $stub);
            
            // Save the factory file
            $this->files->put($path, $stub);
            
            $this->info('Factory created: ' . $factoryName);
            
            return true;
        } catch (\Exception $e) {
            $this->error("Error creating factory: " . $e->getMessage());
            throw $e; // Re-throw to be handled by the caller
        }
    }

    /**
     * Update app.css with imports for the new CSS files.
     *
     * @param string $name
     * @return void
     */
    protected function updateAppCssImports(string $name): void {
        $appCssPath = resource_path('css/app.css');
        
        // Create app.css if it doesn't exist
        if (!$this->files->exists($appCssPath)) {
            $this->createAppCss($appCssPath);
        }
        
        // Read current content
        $content = $this->files->get($appCssPath);
        
        // Generate import statement
        $viewPrefix = Str::kebab(Str::plural(Str::studly($name)));
        $importStatement = "@import '{$viewPrefix}/*.css';";
        
        // Check if import already exists
        if (strpos($content, $importStatement) !== false) {
            $this->info('CSS import already exists in app.css. Skipping update.');
            return;
        }
        
        // Always appended to the beginning of the file.
        $newContent = $importStatement . "\n" . $content;
        
        // Save updated content
        $this->files->put($appCssPath, $newContent);
        $this->info("Updated app.css with import for {$viewPrefix} CSS files");
    }

    
    /**
     * Create a single view file.
     *
     * @param string $name Model name
     * @param string $view View name (index, create, show, edit)
     * @param string $dir View directory path
     * @return void
     */
    protected function createViewFile(string $name, string $view, string $dir): void {
        $className = Str::studly($name);
        $modelVariable = Str::camel($className);
        $modelVariablePlural = Str::plural($modelVariable);
        $modelTitle = Str::title(Str::snake($name, ' '));
        $modelTitlePlural = Str::title(Str::plural(Str::snake($name, ' ')));
        
        // Define file path
        $path = $dir . '/' . $view . '.blade.php';
        
        // Skip if file already exists
        if ($this->files->exists($path)) {
            $this->info('View already exists: ' . basename($path) . ' (Skipping)');
            return;
        }
        
        // Get the stub for the type of view
        $stubName = 'view.' . $view;
        
        try {
            $stub = $this->getStub($stubName);
        } catch (\Exception $e) {
            // If specific view stub not found, create a basic view
            $stub = "@extends('layouts.app')\n\n@section('content')\n\n@endsection";
            $this->warn("View stub for '{$view}' not found. Creating a basic view.");
        }
        
        // Replace variables in the stub file
        $stub = str_replace('{{ className }}', $className, $stub);
        $stub = str_replace('{{ modelVariable }}', $modelVariable, $stub);
        $stub = str_replace('{{ modelVariablePlural }}', $modelVariablePlural, $stub);
        $stub = str_replace('{{ modelTitle }}', $modelTitle, $stub);
        $stub = str_replace('{{ modelTitlePlural }}', $modelTitlePlural, $stub);
        $stub = str_replace('{{ viewPrefix }}', Str::kebab(Str::plural($className)), $stub);
        
        // Route parameter name
        $routeParameter = Str::kebab($className);
        $stub = str_replace('{{ routeParameter }}', $routeParameter, $stub);
        
        // Save the view file
        $this->files->put($path, $stub);
        
        $this->info('Created view: ' . basename($path));
    }
    
    /**
     * Create a layout file.
     *
     * @param string $dir Layouts directory path
     * @return void
     */
    protected function createLayoutFile(string $dir): void {
        try {
            // レイアウトスタブを取得
            $stub = $this->getStub('view.layout');
            
            // Define file path
            $path = $dir . '/app.blade.php';
            
            // Save the layout file
            $this->files->put($path, $stub);
            $this->info('Layout file created from stub: app.blade.php');
        } catch (\Exception $e) {
            $this->error('Layout stub file not found. Please create view.layout.stub in the stubs directory.');
            throw $e;
        }
    }

    /**
     * Update app.js with imports for the new JS files.
     *
     * @param string $name
     * @return void
     */
    protected function updateAppJsImports(string $name): void {
        $appJsPath = resource_path('js/app.js');
        
        // Create app.js if it doesn't exist
        if (!$this->files->exists($appJsPath)) {
            $this->createAppJs($appJsPath);
        }
        
        // Read current content
        $content = $this->files->get($appJsPath);
        
        // Generate import statement (コメントなし)
        $viewPrefix = Str::kebab(Str::plural(Str::studly($name)));
        $importStatement = "import './{$viewPrefix}/*.js';";
        
        // Check if import already exists
        if (strpos($content, $importStatement) !== false) {
            $this->info('JS import already exists in app.js. Skipping update.');
            return;
        }
        
        // 常にファイルの先頭に追記
        $newContent = $importStatement . "\n" . $content;
        
        // Save updated content
        $this->files->put($appJsPath, $newContent);
        $this->info("Updated app.js with import for {$viewPrefix} JS files");
    }

    /**
     * Get a CSS class name from the resource name.
     *
     * @param string $name
     * @return string
     */
    protected function getCssClassName(string $name): string {
        return Str::kebab($name);
    }

    /**
     * Get a formatted timestamp.
     *
     * @return string
     */
    protected function getTimestamp(): string {
        return date('Y-m-d H:i:s');
    }

    /**
     * Get default content for missing stubs when no stub files are available.
     *
     * @param string $type
     * @return string
     * @throws \Exception
     */
    protected function getDefaultStubContent(string $type): string {
        if ($type === 'default-css' || $type === 'css' || $type === 'app-css') {
            return "/* Default CSS styles */\n";
        }
        
        if ($type === 'default-js' || $type === 'js' || $type === 'app-js') {
            return "// Default JavaScript\n";
        }
        
        if (strpos($type, 'view.') === 0) {
            return "@extends('layouts.app')\n\n@section('content')\n\n@endsection";
        }
        
        // If we don't have a fallback for this stub type, throw an exception
        throw new \Exception("Stub file '{$type}.stub' not found and no fallback content available. Please create the necessary stub files.");
    }

    /**
     * Create default app.css if it doesn't exist.
     *
     * @param string $path
     * @return void
     */
    protected function createAppCss(string $path): void {
        if (!$this->files->exists($path)) {
            // 空のファイルを作成
            $this->files->put($path, '');
            $this->info('Created app.css file');
        }
    }

    /**
     * Create default app.js if it doesn't exist.
     *
     * @param string $path
     * @return void
     */
    protected function createAppJs(string $path): void {
        if (!$this->files->exists($path)) {
            // Create empty file
            $this->files->put($path, '');
            $this->info('Created app.js file');
        }
    }

   
    /**
     * Create a CSS file for a specific view.
     *
     * @param string $name Model name
     * @param string $view View name (index, create, show, edit)
     * @param string $dir CSS directory path
     * @return void
     */
    protected function createCssFile(string $name, string $view, string $dir): void {
        $className = Str::studly($name);
        $fileName = $view . '.css';
        $path = $dir . '/' . $fileName;
        
        // Skip if file already exists
        if ($this->files->exists($path)) {
            $this->info('CSS file already exists: ' . $fileName . ' (Skipping)');
            return;
        }
        
        try {
            // Get the CSS view stub
            $stub = $this->getStub('css.' . $view);
        } catch (\Exception $e) {
            try {
                // Try to get general CSS stub
                $stub = $this->getStub('css');
            } catch (\Exception $e2) {
                $this->warn('Could not find CSS stub. Please create a proper stub file.');
                throw $e2;
            }
        }
        
        // Replace variables in the stub file
        $stub = str_replace('{{ class }}', $className, $stub);
        $stub = str_replace('{{ cssClass }}', $this->getCssClassName($name), $stub);
        $stub = str_replace('{{ view }}', $view, $stub);
        $stub = str_replace('{{ viewTitle }}', ucfirst($view), $stub);
        $stub = str_replace('{{ generatedAt }}', $this->getTimestamp(), $stub);
        
        // Save the CSS file
        $this->files->put($path, $stub);
        $this->info("Created CSS file: {$view}.css");
    }

    /**
     * Create a JavaScript file for a specific view.
     *
     * @param string $name Model name
     * @param string $view View name (index, create, show, edit)
     * @param string $dir JS directory path
     * @return void
     */
    protected function createJsFile(string $name, string $view, string $dir): void {
        $className = Str::studly($name);
        $fileName = $view . '.js';
        $path = $dir . '/' . $fileName;
        
        // Skip if file already exists
        if ($this->files->exists($path)) {
            $this->info('JavaScript file already exists: ' . $fileName . ' (Skipping)');
            return;
        }
        
        try {
            // Get the JS view stub
            $stub = $this->getStub('js.' . $view);
        } catch (\Exception $e) {
            try {
                // Try to get general JS stub
                $stub = $this->getStub('js');
            } catch (\Exception $e2) {
                $this->warn('Could not find JS stub. Please create a proper stub file.');
                throw $e2;
            }
        }
        
        // Replace variables in the stub file
        $stub = str_replace('{{ class }}', $className, $stub);
        $stub = str_replace('{{ modelVariable }}', Str::camel($className), $stub);
        $stub = str_replace('{{ view }}', $view, $stub);
        $stub = str_replace('{{ viewTitle }}', ucfirst($view), $stub);
        $stub = str_replace('{{ generatedAt }}', $this->getTimestamp(), $stub);
        
        // Save the JS file
        $this->files->put($path, $stub);
        $this->info("Created JavaScript file: {$view}.js");
    }

    /**
     * Get the stub file content.
     *
     * @param string $type
     * @return string
     * @throws \Exception
     */
    protected function getStub(string $type): string {
        // Search by path relative to the command file.
        $stubPaths = [
            // Search by relative path (relative to the current command file)
            dirname(__DIR__, 3) . '/stubs/' . $type . '.stub',
            dirname(__DIR__, 2) . '/stubs/' . $type . '.stub',
            dirname(__DIR__) . '/stubs/' . $type . '.stub',
        ];
        
        // Additional paths for special types
        if (strpos($type, 'view.') === 0) {
            $viewType = substr($type, 5); // Remove 'view.' prefix
            $stubPaths[] = dirname(__DIR__, 3) . '/stubs/views/' . $viewType . '.stub';
        } else if (strpos($type, 'css.') === 0) {
            $cssType = substr($type, 4); // Remove 'css.' prefix
            $stubPaths[] = dirname(__DIR__, 3) . '/stubs/css/' . $cssType . '.stub';
        } else if (strpos($type, 'js.') === 0) {
            $jsType = substr($type, 3); // Remove 'js.' prefix
            $stubPaths[] = dirname(__DIR__, 3) . '/stubs/js/' . $jsType . '.stub';
        }
        
        foreach ($stubPaths as $stubPath) {
            if (file_exists($stubPath)) {
                return file_get_contents($stubPath);
            }
        }
        
        $this->error("Stub file not found: {$type}.stub");
        $this->warn("Please create the necessary stub files in the stubs directory.");
        
        throw new \Exception("Stub file '{$type}.stub' not found.");
    }
}