<?php

namespace HSP\Core\Contracts;

interface ModuleInterface
{
    /**
     * Register services and dependencies in the container.
     *
     * @return void
     */
    public function register(): void;

    /**
     * Initialize runtime hooks and events.
     *
     * @return void
     */
    public function boot(): void;

    /**
     * Initialize migrations and resources during plugin activation.
     *
     * @return void
     */
    public function activate(): void;

    /**
     * Cleanup runtime hooks during plugin deactivation.
     *
     * @return void
     */
    public function deactivate(): void;

    /**
     * Apply version migrations.
     *
     * @return void
     */
    public function upgrade(): void;
}
