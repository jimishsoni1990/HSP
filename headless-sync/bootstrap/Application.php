<?php

namespace HSP\Bootstrap;

use HSP\Core\Container\Container;
use HSP\Core\Config\Config;
use HSP\Core\Contracts\ModuleInterface;
use HSP\Core\Events\EventBuilder;
use HSP\Core\Events\OutboxService;
use HSP\Core\Events\WordpressEventListener;
use HSP\Core\Admin\AdminDashboard;
use HSP\Core\Queue\DatabaseQueueProvider;
use HSP\Core\Contracts\QueueProviderInterface;
use PDO;
use Exception;

class Application extends Container
{
    /**
     * @var string
     */
    protected string $basePath;

    /**
     * @var bool
     */
    protected bool $booted = false;

    /**
     * @var ModuleInterface[]
     */
    protected array $modules = [];

    /**
     * Application constructor.
     *
     * @param string $basePath
     */
    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
        $this->registerBaseBindings();
        $this->loadConfiguration();
        $this->registerCoreServices();
    }

    /**
     * Get the base path of the application.
     *
     * @return string
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * Register basic container bindings.
     *
     * @return void
     */
    protected function registerBaseBindings(): void
    {
        $this->instance(self::class, $this);
        $this->instance(Container::class, $this);
    }

    /**
     * Load configuration files into the config manager.
     *
     * @return void
     */
    protected function loadConfiguration(): void
    {
        $configPath = $this->basePath . '/config';
        $items = [];

        foreach (['app', 'database', 'queue'] as $file) {
            $filePath = "{$configPath}/{$file}.php";
            if (file_exists($filePath)) {
                $items[$file] = require $filePath;
            }
        }

        $config = new Config($items);
        $this->instance(Config::class, $config);
    }

    /**
     * Register platform core services.
     *
     * @return void
     */
    protected function registerCoreServices(): void
    {
        // Bind PDO connection as singleton
        $this->singleton(PDO::class, function (Container $container) {
            $config = $container->make(Config::class);
            $dbConfig = $config->get('database.connections.pgsql');

            if (!$dbConfig) {
                throw new Exception("PostgreSQL database configuration not found.");
            }

            $host = $dbConfig['host'];
            $port = $dbConfig['port'];
            $db = $dbConfig['database'];
            $user = $dbConfig['username'];
            $pass = $dbConfig['password'];

            $dsn = "pgsql:host={$host};port={$port};dbname={$db}";
            
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        });

        // Bind core services
        $this->singleton(EventBuilder::class);
        $this->singleton(OutboxService::class);
        $this->singleton(WordpressEventListener::class);
        $this->singleton(AdminDashboard::class);

        // Bind QueueProviderInterface
        $this->singleton(QueueProviderInterface::class, function (Container $container) {
            return new DatabaseQueueProvider($container->make(PDO::class));
        });
    }

    /**
     * Boot the application and its modules.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        // Load modules from config
        $config = $this->make(Config::class);
        $modules = $config->get('app.modules', []);

        foreach ($modules as $moduleClass) {
            if (class_exists($moduleClass)) {
                $module = $this->make($moduleClass);
                if ($module instanceof ModuleInterface) {
                    $module->register();
                    $this->modules[] = $module;
                }
            }
        }

        // Boot all modules
        foreach ($this->modules as $module) {
            $module->boot();
        }

        // Register Wordpress hooks
        $this->make(WordpressEventListener::class)->registerHooks();

        // Register WordPress Admin hooks if in admin context
        if (function_exists('is_admin') && is_admin()) {
            $this->make(AdminDashboard::class)->register();
        }

        $this->booted = true;
    }

    /**
     * Activate the application, running migrations and module activations.
     *
     * @return void
     */
    public function activate(): void
    {
        // Run SQL schema migrations
        try {
            $pdo = $this->make(PDO::class);
            $migrationFile = $this->basePath . '/database/Core/01_create_system_tables.sql';
            
            if (file_exists($migrationFile)) {
                $sql = file_get_contents($migrationFile);
                $pdo->exec($sql);
            }
        } catch (\Throwable $e) {
            // Log or ignore database issues on activation (e.g. if DB not up yet)
        }

        // Activate modules
        foreach ($this->modules as $module) {
            $module->activate();
        }
    }

    /**
     * Deactivate the application kernel.
     *
     * @return void
     */
    public function deactivate(): void
    {
        foreach ($this->modules as $module) {
            $module->deactivate();
        }
    }

    /**
     * Apply upgrades.
     *
     * @return void
     */
    public function upgrade(): void
    {
        foreach ($this->modules as $module) {
            $module->upgrade();
        }
    }
}
