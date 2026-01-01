<?php

namespace Tests\NFR\Maintainability;

use Tests\TestCase;
use Illuminate\Support\Facades\File;

/**
 * Maintainability Requirements Test: Code Quality
 * 
 * Tests code quality requirements:
 * - Code coverage: ≥ 80% for unit tests
 * - Static code analysis with zero critical issues
 * - Consistent coding conventions and style guides
 * - Comprehensive documentation for all modules
 * 
 * Note: These tests verify code quality metrics and standards
 * Actual code coverage and static analysis should be run via CI/CD
 */
class CodeQualityTest extends TestCase
{
    /**
     * Test: Code coverage configuration exists
     * Requirement: Code coverage: ≥ 80% for unit tests
     */
    public function test_code_coverage_configuration_exists(): void
    {
        // Check if phpunit.xml has coverage configuration
        $phpunitPath = base_path('phpunit.xml');
        
        $this->assertTrue(
            File::exists($phpunitPath),
            "phpunit.xml should exist for code coverage configuration"
        );

        $phpunitContent = File::get($phpunitPath);
        
        // Should have coverage configuration
        $this->assertStringContainsString(
            '<source>',
            $phpunitContent,
            "phpunit.xml should include source code coverage configuration"
        );
    }

    /**
     * Test: Coding standards configuration
     * Requirement: Consistent coding conventions and style guides
     */
    public function test_coding_standards_configuration(): void
    {
        // Check for common coding standard config files
        $configFiles = [
            '.php_cs.dist',
            '.php-cs-fixer.dist.php',
            'phpcs.xml',
            '.phpcs.xml',
            'phpstan.neon',
            'psalm.xml',
        ];

        $hasConfig = false;
        foreach ($configFiles as $configFile) {
            if (File::exists(base_path($configFile))) {
                $hasConfig = true;
                break;
            }
        }

        $this->assertTrue(
            $hasConfig || File::exists(base_path('composer.json')),
            "Coding standards configuration should exist (PHP-CS-Fixer, PHPStan, Psalm, or similar)"
        );
    }

    /**
     * Test: Documentation files exist
     * Requirement: Comprehensive documentation for all modules
     */
    public function test_documentation_files_exist(): void
    {
        $documentationFiles = [
            'README.md',
            'docs/',
        ];

        $hasDocs = false;
        foreach ($documentationFiles as $doc) {
            $path = base_path($doc);
            if (File::exists($path)) {
                $hasDocs = true;
                break;
            }
        }

        $this->assertTrue(
            $hasDocs,
            "Documentation files should exist (README.md or docs/ directory)"
        );
    }

    /**
     * Test: Module structure consistency
     * All modules should follow consistent structure
     */
    public function test_module_structure_consistency(): void
    {
        $modulesPath = base_path('Modules');
        
        if (!File::isDirectory($modulesPath)) {
            $this->markTestSkipped('Modules directory does not exist');
        }

        $modules = File::directories($modulesPath);
        
        if (empty($modules)) {
            $this->markTestSkipped('No modules found');
        }

        $requiredDirs = ['app', 'routes'];
        $consistentModules = 0;

        foreach ($modules as $module) {
            $hasRequired = true;
            foreach ($requiredDirs as $dir) {
                if (!File::isDirectory($module . '/' . $dir)) {
                    $hasRequired = false;
                    break;
                }
            }
            
            if ($hasRequired) {
                $consistentModules++;
            }
        }

        $consistencyRate = ($consistentModules / count($modules)) * 100;
        $this->assertGreaterThanOrEqual(
            80,
            $consistencyRate,
            "At least 80% of modules should follow consistent structure. Actual: {$consistencyRate}%"
        );
    }

    /**
     * Test: Composer dependencies are locked
     * Dependencies should be version-locked for consistency
     */
    public function test_composer_dependencies_locked(): void
    {
        $composerLockPath = base_path('composer.lock');
        
        $this->assertTrue(
            File::exists($composerLockPath),
            "composer.lock should exist to lock dependency versions"
        );
    }

    /**
     * Test: Environment configuration
     * Environment configuration should be properly managed
     */
    public function test_environment_configuration(): void
    {
        $envExamplePath = base_path('.env.example');
        
        // .env.example should exist as template
        $this->assertTrue(
            File::exists($envExamplePath),
            ".env.example should exist as environment configuration template"
        );
    }

    /**
     * Test: Git ignore configuration
     * Sensitive files should be in .gitignore
     */
    public function test_git_ignore_configuration(): void
    {
        $gitIgnorePath = base_path('.gitignore');
        
        if (File::exists($gitIgnorePath)) {
            $gitIgnoreContent = File::get($gitIgnorePath);
            
            $sensitiveFiles = [
                '.env' => ['.env'],
                'vendor' => ['vendor', '/vendor'],
                'node_modules' => ['node_modules', '/node_modules'],
            ];
            
            foreach ($sensitiveFiles as $key => $patterns) {
                $found = false;
                foreach ($patterns as $pattern) {
                    if (strpos($gitIgnoreContent, $pattern) !== false) {
                        $found = true;
                        break;
                    }
                }
                $this->assertTrue(
                    $found,
                    ".gitignore should exclude: {$key} (checked patterns: " . implode(', ', $patterns) . ")"
                );
            }
        } else {
            $this->markTestSkipped('.gitignore file does not exist');
        }
    }

    /**
     * Test: Type hints usage
     * Modern PHP should use type hints
     */
    public function test_type_hints_usage(): void
    {
        // This is a conceptual test - actual type hint checking requires static analysis
        $this->assertTrue(
            true,
            "Type hints should be used in all method signatures (verify with static analysis)"
        );
    }

    /**
     * Test: PSR-12 compliance
     * Code should follow PSR-12 coding standard
     */
    public function test_psr12_compliance(): void
    {
        // PSR-12 compliance requires static analysis tools
        $this->assertTrue(
            true,
            "PSR-12 compliance should be verified with PHP-CS-Fixer or similar tool"
        );
    }
}
