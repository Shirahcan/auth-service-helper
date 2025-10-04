<?php

namespace AuthService\Helper\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'authservice:install
                            {--with-views : Publish views in addition to config files}
                            {--configure-guard : Add authservice guard to config/auth.php}
                            {--as-default : Set authservice as the default guard}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install and configure the Auth Service Helper package';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🚀 Installing Auth Service Helper...');
        $this->newLine();

        // Always publish config
        $this->publishConfig();

        // Optionally publish views
        if ($this->option('with-views')) {
            $this->publishViews();
        }

        // Handle auth guard configuration
        $configureGuard = $this->option('configure-guard') || $this->option('as-default');
        $setAsDefault = $this->option('as-default');

        if ($configureGuard) {
            $this->configureAuthGuard($setAsDefault);
        }

        // Display summary and next steps
        $this->displaySummary();

        return self::SUCCESS;
    }

    /**
     * Publish the configuration file
     */
    protected function publishConfig(): void
    {
        $this->components->task('Publishing configuration file', function () {
            $this->call('vendor:publish', [
                '--tag' => 'authservice-config',
                '--force' => false,
            ]);
        });
    }

    /**
     * Publish the view files
     */
    protected function publishViews(): void
    {
        $this->components->task('Publishing view files', function () {
            $this->call('vendor:publish', [
                '--tag' => 'authservice-views',
                '--force' => false,
            ]);
        });
    }

    /**
     * Configure the auth guard in config/auth.php
     */
    protected function configureAuthGuard(bool $setAsDefault): void
    {
        $authConfigPath = config_path('auth.php');

        if (!File::exists($authConfigPath)) {
            $this->components->error('config/auth.php not found');
            return;
        }

        $this->components->task('Configuring authentication guard', function () use ($authConfigPath, $setAsDefault) {
            $content = File::get($authConfigPath);
            $modified = false;

            // Add guard configuration
            if (!$this->guardExists($content)) {
                $content = $this->addGuardConfiguration($content);
                $modified = true;
                $this->components->info('  ✓ Added authservice guard configuration');
            } else {
                $this->components->info('  • Guard configuration already exists');
            }

            // Add provider configuration
            if (!$this->providerExists($content)) {
                $content = $this->addProviderConfiguration($content);
                $modified = true;
                $this->components->info('  ✓ Added authservice provider configuration');
            } else {
                $this->components->info('  • Provider configuration already exists');
            }

            // Set as default guard
            if ($setAsDefault) {
                if (!$this->isDefaultGuard($content)) {
                    $content = $this->setDefaultGuard($content);
                    $modified = true;
                    $this->components->info('  ✓ Set authservice as default guard');
                } else {
                    $this->components->info('  • Already set as default guard');
                }
            }

            if ($modified) {
                File::put($authConfigPath, $content);
            }
        });
    }

    /**
     * Check if authservice guard exists in config
     */
    protected function guardExists(string $content): bool
    {
        return preg_match("/['\"]authservice['\"]\\s*=>\\s*\\[/", $content) &&
               strpos($content, "'guards'") !== false;
    }

    /**
     * Check if authservice provider exists in config
     */
    protected function providerExists(string $content): bool
    {
        return preg_match("/['\"]authservice['\"]\\s*=>\\s*\\[/", $content) &&
               strpos($content, "'providers'") !== false;
    }

    /**
     * Check if authservice is the default guard
     */
    protected function isDefaultGuard(string $content): bool
    {
        return preg_match("/['\"]guard['\"]\\s*=>\\s*['\"]authservice['\"]/", $content);
    }

    /**
     * Add guard configuration to config/auth.php
     */
    protected function addGuardConfiguration(string $content): string
    {
        $guardConfig = <<<'PHP'
        'authservice' => [
            'driver' => 'authservice',
            'provider' => 'authservice',
        ],


PHP;

        // Find the 'guards' array and add our configuration
        $pattern = "/([\'\"]guards[\'\"]\\s*=>\\s*\\[)/";

        if (preg_match($pattern, $content)) {
            $content = preg_replace(
                $pattern,
                "$1\n" . $guardConfig,
                $content,
                1
            );
        }

        return $content;
    }

    /**
     * Add provider configuration to config/auth.php
     */
    protected function addProviderConfiguration(string $content): string
    {
        $providerConfig = <<<'PHP'
        'authservice' => [
            'driver' => 'authservice',
        ],


PHP;

        // Find the 'providers' array and add our configuration
        $pattern = "/([\'\"]providers[\'\"]\\s*=>\\s*\\[)/";

        if (preg_match($pattern, $content)) {
            $content = preg_replace(
                $pattern,
                "$1\n" . $providerConfig,
                $content,
                1
            );
        }

        return $content;
    }

    /**
     * Set authservice as the default guard
     */
    protected function setDefaultGuard(string $content): string
    {
        // Replace the default guard value
        $pattern = "/([\'\"]guard[\'\"]\\s*=>\\s*['\"])([^'\"]+)(['\"])/";

        if (preg_match($pattern, $content)) {
            $content = preg_replace(
                $pattern,
                "${1}authservice${3}",
                $content,
                1
            );
        }

        return $content;
    }

    /**
     * Display installation summary and next steps
     */
    protected function displaySummary(): void
    {
        $this->newLine();
        $this->components->info('✅ Installation complete!');
        $this->newLine();

        $this->components->twoColumnDetail('<fg=cyan>Next Steps:</>', '');

        $this->components->twoColumnDetail(
            '1. Configure your .env file',
            '<fg=gray>Add AUTH_SERVICE_* variables</>'
        );

        $this->line('   <fg=gray>Required variables:</>');
        $this->line('   <fg=yellow>AUTH_SERVICE_BASE_URL</>=http://localhost:8000');
        $this->line('   <fg=yellow>AUTH_SERVICE_API_KEY</>=your_service_api_key_here');
        $this->line('   <fg=yellow>AUTH_SERVICE_SLUG</>=your-service-slug');
        $this->newLine();

        $this->line('   <fg=gray>Optional variables:</>');
        $this->line('   <fg=yellow>AUTH_SERVICE_TIMEOUT</>=30');
        $this->line('   <fg=yellow>AUTH_SERVICE_LOGIN_ROLES</>=admin,manager');
        $this->line('   <fg=yellow>AUTH_SERVICE_CALLBACK_URL</>=/auth/callback');
        $this->line('   <fg=yellow>AUTH_SERVICE_REDIRECT_AFTER_LOGIN</>=/dashboard');
        $this->newLine();

        $this->components->twoColumnDetail(
            '2. Clear configuration cache',
            '<fg=gray>php artisan config:clear</>'
        );
        $this->newLine();

        $this->components->twoColumnDetail(
            '3. Start using the package',
            '<fg=gray>Redirect to route(\'auth.login\')</>'
        );
        $this->newLine();

        $this->components->info('📖 Documentation: https://github.com/Shirahcan/auth-service-helper');
        $this->newLine();
    }
}
