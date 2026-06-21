<?php

namespace App\Helpers;

/**
 * Validates required environment variables at application startup.
 *
 * Call EnvValidator::check() in bootstrap/app.php after config loads.
 * Throws RuntimeException with a clear message listing missing vars.
 */
class EnvValidator
{
    /**
     * Environment variables required in ALL environments.
     */
    private static array $required = [
        'APP_KEY',
        'JWT_SECRET',
    ];

    /**
     * Environment variables required in production only.
     */
    private static array $requiredProduction = [
        'API_TOKEN',
    ];

    /**
     * Validate that required env vars are set and non-empty.
     *
     * @throws \RuntimeException if any required variable is missing
     */
    public static function check(): void
    {
        $missing = [];

        foreach (self::$required as $var) {
            if (!env($var)) {
                $missing[] = $var;
            }
        }

        // Production-only checks
        if (app()->environment('production')) {
            foreach (self::$requiredProduction as $var) {
                if (!env($var)) {
                    $missing[] = $var . ' (production)';
                }
            }
        }

        if (!empty($missing)) {
            throw new \RuntimeException(
                'Variáveis de ambiente obrigatórias não definidas: ' . implode(', ', $missing)
                . '. Verifique o arquivo .env.'
            );
        }
    }
}
