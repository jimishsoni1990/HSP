<?php

namespace HSP\Bootstrap;

class Bootstrapper
{
    /**
     * @var Application|null
     */
    protected static ?Application $app = null;

    /**
     * Initialize the bootstrapper and core application container.
     *
     * @param string $pluginFilePath
     * @return Application
     */
    public static function init(string $pluginFilePath): Application
    {
        if (self::$app === null) {
            $basePath = dirname($pluginFilePath);
            self::$app = new Application($basePath);

            if (function_exists('register_activation_hook')) {
                register_activation_hook($pluginFilePath, [self::class, 'activate']);
            }

            if (function_exists('register_deactivation_hook')) {
                register_deactivation_hook($pluginFilePath, [self::class, 'deactivate']);
            }

            if (class_exists('WP_CLI')) {
                \WP_CLI::add_command('headless-sync work', \HSP\Core\Workers\CliCommand::class);
                \WP_CLI::add_command('headless-sync worker', \HSP\Core\Workers\CliCommand::class);
            }

            if (function_exists('add_action')) {
                add_action('plugins_loaded', [self::class, 'bootApplication']);
            } else {
                // Fallback/Direct boot for test runner or CLI contexts
                self::$app->boot();
            }
        }

        return self::$app;
    }

    /**
     * Get the current active application kernel instance.
     *
     * @return Application|null
     */
    public static function getApplication(): ?Application
    {
        return self::$app;
    }

    /**
     * Boot the application kernel.
     *
     * @return void
     */
    public static function bootApplication(): void
    {
        if (self::$app) {
            self::$app->boot();
        }
    }

    /**
     * Activate the plugin (run migrations and setup).
     *
     * @return void
     */
    public static function activate(): void
    {
        if (self::$app) {
            self::$app->boot();
            self::$app->activate();
        }
    }

    /**
     * Deactivate the plugin.
     *
     * @return void
     */
    public static function deactivate(): void
    {
        if (self::$app) {
            self::$app->deactivate();
        }
    }

    /**
     * Trigger plugin upgrades.
     *
     * @return void
     */
    public static function upgrade(): void
    {
        if (self::$app) {
            self::$app->upgrade();
        }
    }
}
