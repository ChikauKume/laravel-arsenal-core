<?php

namespace Lac\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;

class SyncRoutesCommand extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lac:sync-routes 
                        {--web : Synchronize web routes only}
                        {--api : Synchronize API routes only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize web and API routes based on controllers';

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
        $this->info('Starting route synchronization...');
        
        $syncWeb = $this->option('web') || (!$this->option('web') && !$this->option('api'));
        $syncApi = $this->option('api') || (!$this->option('web') && !$this->option('api'));
        
        $success = true;
        
        if ($syncWeb) {
            $webSuccess = $this->syncRoutes(false);
            $success = $success && $webSuccess;
        }
        
        if ($syncApi) {
            $apiSuccess = $this->syncRoutes(true);
            $success = $success && $apiSuccess;
        }
        
        if ($success) {
            $this->info('Route synchronization completed successfully.');
        } else {
            $this->warn('Route synchronization completed with some errors. Check the messages above for details.');
        }
        
        return $success ? Command::SUCCESS : Command::FAILURE;
    }
    
    /**
     * Synchronize routes based on controllers.
     *
     * @param bool $isApi Whether to generate API routes
     * @return bool
     */
    protected function syncRoutes(bool $isApi = false): bool {
        $routeType = $isApi ? 'API' : 'Web';
        $this->info("Synchronizing {$routeType} routes...");
        
        try {
            // Template and output file paths - use stubs from package
            $lacStubName = $isApi ? 'lac-api.stub' : 'lac-web.stub';
            $lacStubPath = $this->getPackageStubPath($lacStubName);
            $outputFile = base_path('routes/' . ($isApi ? 'lac-api.php' : 'lac-web.php'));
            $mainRoutesPath = base_path('routes/' . ($isApi ? 'api.php' : 'web.php'));
            
            // Check if stub exists
            if (!$this->files->exists($lacStubPath)) {
                $this->error("LAC {$routeType} stub file not found: " . $this->getRelativePath($lacStubPath));
                return false;
            }
            
            // Generate route file content for controllers
            $controllers = $this->getControllersForRoutes();
            
            if (empty($controllers)) {
                $this->info("No controllers found for route generation.");
                
                // Create an empty lac file from stub template
                $stubContent = $this->files->get($lacStubPath);
                $this->files->put($outputFile, str_replace([
                    '{{controller_imports}}',
                    '{{route_definitions}}'
                ], [
                    '// No controllers found',
                    '// No routes defined'
                ], $stubContent));
                $this->info("Created empty LAC {$routeType} routes file from stub: " . $this->getRelativePath($outputFile));
                
                // Check if main routes file exists, create if needed
                if (!$this->files->exists($mainRoutesPath)) {
                    $this->ensureRoutesImported($mainRoutesPath, $isApi);
                }
                
                return true;
            }
            
            // Generate routes file from stub
            $routeFileContent = $this->generateRoutesFromStub($lacStubPath, $controllers, $isApi);
            $this->files->put($outputFile, $routeFileContent);
            $this->info("Generated LAC {$routeType} routes file: " . $this->getRelativePath($outputFile));
            $this->info("Registered " . count($controllers) . " resource routes");
            
            // Ensure routes are imported in main route file
            $this->ensureRoutesImported($mainRoutesPath, $isApi);
            
            return true;
        } catch (\Exception $e) {
            $this->error("Error synchronizing {$routeType} routes: " . $e->getMessage());
            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }
            return false;
        }
    }
    
    /**
     * Get the package stub path.
     *
     * @param string $stubName
     * @return string
     */
    protected function getPackageStubPath(string $stubName): string {
        return base_path('packages/lac/stubs/' . $stubName);
    }
    
    /**
     * Generate routes file content from stub template.
     *
     * @param string $stubPath
     * @param array $controllers
     * @param bool $isApi
     * @return string
     */
    protected function generateRoutesFromStub(string $stubPath, array $controllers, bool $isApi): string {
        // Read stub content
        $stubContent = $this->files->get($stubPath);
        
        // Generate controller imports
        $imports = [];
        foreach ($controllers as $routeName => $controllerName) {
            $imports[] = "use App\\Http\\Controllers\\{$controllerName};";
        }
        $controllerImports = implode("\n", $imports);
        
        $standardMethods = [
            'index', 'create', 'store', 'show', 'edit', 'update', 'destroy'
        ];
        
        if ($isApi) {
            $standardMethods = array_diff($standardMethods, ['create', 'edit']);
        }
        
        $routeMethod = $isApi ? 'apiResource' : 'resource';
        $routes = [];
        foreach ($controllers as $routeName => $controllerName) {
            
            $controllerPath = app_path('Http/Controllers/' . $controllerName . '.php');
            $missingMethods = $this->getMissingMethods($controllerPath, $standardMethods);
            
            if (empty($missingMethods)) {
                $routes[] = "Route::{$routeMethod}('{$routeName}', {$controllerName}::class);";
            } 
            else {
                $exceptList = "'" . implode("', '", $missingMethods) . "'";
                $routes[] = "Route::{$routeMethod}('{$routeName}', {$controllerName}::class)->except([{$exceptList}]);";
            }
        }
        $routeDefinitions = implode("\n", $routes);
        
        // Replace placeholders in stub
        $content = str_replace('{{controller_imports}}', $controllerImports, $stubContent);
        $content = str_replace('{{route_definitions}}', $routeDefinitions, $content);
        
        return $content;
    }
    
    /**
     * Parsing controller files to retrieve unimplemented RESTful methods
     *
     * @param string $controllerPath
     * @param array $standardMethods
     * @return array Array of unimplemented method names
     */
    protected function getMissingMethods(string $controllerPath, array $standardMethods): array {
        // If the controller file does not exist, an empty array is returned.
        if (!file_exists($controllerPath)) {
            return [];
        }
        
        // Get the contents of the file
        $content = file_get_contents($controllerPath);
        
        // List of methods not implemented.
        $missingMethods = [];
        
        // For each standard method, check whether it is implemented
        foreach ($standardMethods as $method) {
            // Patterns of method definition
            $pattern = '/\bfunction\s+' . $method . '\b/i';
            
            // If the pattern does not match (if the method is not implemented)
            if (!preg_match($pattern, $content)) {
                $missingMethods[] = $method;
            }
        }
        
        return $missingMethods;
    }
    
    /**
     * Ensure routes are imported in main routes file.
     *
     * @param string $mainRoutesPath
     * @param bool $isApi
     * @return void
     */
    protected function ensureRoutesImported(string $mainRoutesPath, bool $isApi = false): void {
        $lacFile = $isApi ? 'lac-api.php' : 'lac-web.php';
        $routeType = $isApi ? 'API' : 'web';
        $stubFile = $isApi ? 'lac-api.stub' : 'lac-web.stub';
        
        // Create main routes file if it doesn't exist
        if (!$this->files->exists($mainRoutesPath)) {
            $content = "<?php\n\n";
            $content .= "use Illuminate\\Support\\Facades\\Route;\n\n";
            $content .= "// Import LAC {$routeType} routes\n";
            $content .= "require __DIR__ . '/{$lacFile}';\n\n";
            
            $this->files->put($mainRoutesPath, $content);
            $this->info("Created main routes file: " . $this->getRelativePath($mainRoutesPath));
            return;
        }
        
        // Read current content
        $mainContent = $this->files->get($mainRoutesPath);
        
        // Check for import statements
        $importStatement = "require __DIR__ . '/{$lacFile}';";
        $alternateImport = "require __DIR__.'/lac.php';";
        $stubImport = "require __DIR__.'/{$stubFile}';";
        $stubImport2 = "require __DIR__ . '/{$stubFile}';";
        $newAlternateImport = "require __DIR__.'/{$lacFile}';";
        
        // Check for duplicate imports and comments
        $importCount = substr_count($mainContent, $importStatement) + 
                    substr_count($mainContent, $newAlternateImport);
        $importCommentCount = substr_count($mainContent, "// Import LAC {$routeType} routes");
        $defaultRouteComment = $isApi ? "// Default API route" : "// Default web route";
        
        // Fix duplicate imports and comments if found
        if ($importCount > 1 || $importCommentCount > 1 || strpos($mainContent, $defaultRouteComment) !== false) {
            $this->info("Detected duplicates. Fixing...");
            
            // Create a clean version of the file
            $lines = explode("\n", $mainContent);
            $cleanLines = [];
            $hasImport = false;
            $hasImportComment = false;
            $hasRouteFacade = false;
            
            foreach ($lines as $line) {
                // Skip duplicate import lines
                if (strpos($line, $importStatement) !== false || strpos($line, $newAlternateImport) !== false) {
                    if (!$hasImport) {
                        $cleanLines[] = $line;
                        $hasImport = true;
                    }
                    continue;
                }
                
                // Skip duplicate import comments
                if (strpos($line, "// Import LAC {$routeType} routes") !== false) {
                    if (!$hasImportComment) {
                        $cleanLines[] = $line;
                        $hasImportComment = true;
                    }
                    continue;
                }
                
                // Skip default route comments and routes
                if ($isApi) {
                    if (strpos($line, "// Default API route") !== false ||
                        strpos($line, "Route::get('/api'") !== false) {
                        continue;
                    }
                } else {
                    if (strpos($line, "// Default web route") !== false) {
                        continue;
                    }
                }
                
                // Skip duplicate Route facade imports
                if (strpos($line, "use Illuminate\\Support\\Facades\\Route;") !== false) {
                    if (!$hasRouteFacade) {
                        $cleanLines[] = $line;
                        $hasRouteFacade = true;
                    }
                    continue;
                }
                
                // Keep other lines
                $cleanLines[] = $line;
            }
            
            // Save the cleaned content
            $cleanContent = implode("\n", $cleanLines);
            $this->files->put($mainRoutesPath, $cleanContent);
            $this->info("Fixed duplicates in " . $this->getRelativePath($mainRoutesPath));
            
            // Update mainContent for further processing
            $mainContent = $cleanContent;
        }
        
        // Now check if we need to replace incorrect imports
        if (strpos($mainContent, $stubImport) !== false) {
            $mainContent = str_replace($stubImport, $newAlternateImport, $mainContent);
            $this->files->put($mainRoutesPath, $mainContent);
            $this->info("Fixed LAC routes import in " . $this->getRelativePath($mainRoutesPath) . " from stub to PHP file");
            return;
        } else if (strpos($mainContent, $stubImport2) !== false) {
            $mainContent = str_replace($stubImport2, $importStatement, $mainContent);
            $this->files->put($mainRoutesPath, $mainContent);
            $this->info("Fixed LAC routes import in " . $this->getRelativePath($mainRoutesPath) . " from stub to PHP file");
            return;
        } else if (strpos($mainContent, $alternateImport) !== false) {
            // Replace old lac.php format
            $mainContent = str_replace($alternateImport, $newAlternateImport, $mainContent);
            $this->files->put($mainRoutesPath, $mainContent);
            $this->info("Updated LAC routes import in " . $this->getRelativePath($mainRoutesPath) . " from old format");
            return;
        }
        
        // Check if any valid import exists
        $hasImport = strpos($mainContent, $importStatement) !== false || 
                    strpos($mainContent, $newAlternateImport) !== false;
        
        if (!$hasImport) {
            // Add import without asking
            $phpTagPos = strpos($mainContent, "<?php");
            if ($phpTagPos !== false) {
                $mainContent = substr_replace(
                    $mainContent, 
                    "<?php\n\n// Import LAC {$routeType} routes\n{$importStatement}\n", 
                    $phpTagPos, 
                    5
                );
                $this->files->put($mainRoutesPath, $mainContent);
                $this->info("Added LAC {$routeType} routes import to " . $this->getRelativePath($mainRoutesPath));
            } else {
                $this->warn("Could not find PHP tag in " . $this->getRelativePath($mainRoutesPath) . ". Manually adding the import.");
                
                // Prepend import to file regardless
                $importContent = "<?php\n\n// Import LAC {$routeType} routes\n{$importStatement}\n";
                $importContent .= $mainContent;
                
                $this->files->put($mainRoutesPath, $importContent);
                $this->info("Added LAC {$routeType} routes import to " . $this->getRelativePath($mainRoutesPath));
            }
        } else {
            $this->info("LAC {$routeType} routes import already exists in " . $this->getRelativePath($mainRoutesPath));
        }
    }

    /**
     * Get controllers for route generation by scanning controller directory.
     *
     * @return array
     */
    protected function getControllersForRoutes(): array {
        // Check Controllers directory
        $controllersPath = app_path('Http/Controllers');
        if (!$this->files->isDirectory($controllersPath)) {
            $this->warn("Controllers directory not found. No controller mappings generated.");
            return [];
        }
        
        // Get controller files
        $controllerFiles = $this->files->glob($controllersPath . '/*.php');
        
        // Generate mappings
        $controllers = [];
        foreach ($controllerFiles as $file) {
            $fileName = basename($file, '.php');
            
            // Skip base controller and other non-resource controllers
            if ($fileName === 'Controller' || strpos($fileName, 'Abstract') === 0 || strpos($fileName, 'Base') === 0) {
                continue;
            }
            
            // Check if it's a resource controller by naming convention
            if (preg_match('/(.+)Controller$/', $fileName, $matches)) {
                $modelName = $matches[1];
                
                // Skip Laravel's built-in controllers
                if (in_array($modelName, ['Auth', 'Password', 'Email', 'Verification'])) {
                    continue;
                }
                
                $routeName = Str::plural(Str::kebab($modelName));
                $controllers[$routeName] = $fileName;
                
                $this->line("Found controller: {$fileName} → route: {$routeName}");
            }
        }
        
        // Sort controllers
        ksort($controllers);
        
        return $controllers;
    }

    /**
     * Get a relative path for display.
     *
     * @param string $path
     * @return string
     */
    protected function getRelativePath(string $path): string {
        return str_replace(base_path() . '/', '', $path);
    }
}