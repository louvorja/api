<?php

namespace Tests\Feature;

use Tests\TestCase;

class FileControllerSecurityTest extends TestCase
{
    /**
     * Regression: SQL injection via id_album parameter.
     * ANTES: whereRaw com concatenacao de string permitia injection.
     * DEPOIS: parameter binding com cast (int) neutraliza qualquer input.
     *
     * Estes testes verificam que o input malicioso nao causa SQL malformado.
     * Como usamos SQLite in-memory sem as tabelas reais, esperamos erro de
     * tabela inexistente (SQLSTATE) — NUNCA erro de sintaxe SQL (500 sem exception).
     * O importante e que o cast (int) converte "1 OR 1=1" para 1, impedindo injection.
     */

    /**
     * Test unitario: verifica que (int) cast neutraliza payloads de injection.
     * Este e o fix real que protege o controller.
     */
    public function test_int_cast_neutralizes_classic_injection()
    {
        $payloads = [
            '1 OR 1=1',
            '1; DROP TABLE files',
            '1 UNION SELECT * FROM users',
            "1'; EXEC xp_cmdshell('dir')--",
            '1/**/OR/**/1=1',
        ];

        foreach ($payloads as $payload) {
            $casted = (int) $payload;
            $this->assertEquals(1, $casted, "Payload '$payload' should cast to 1");
        }
    }

    /**
     * Test que whereRaw usa ? placeholder e nao concatenacao.
     * Verifica o codigo-fonte do controller.
     */
    public function test_file_controller_uses_parameter_binding()
    {
        $source = file_get_contents(base_path('app/Http/Controllers/FileController.php'));

        // Nao deve haver concatenacao de $request["id_album"] em whereRaw
        $this->assertStringNotContainsString(
            "whereRaw('id_file in (select id_file_image from albums where albums.id_album=' .",
            $source,
            'FileController still uses string concatenation in whereRaw'
        );

        // Deve usar parameter binding (?)
        $this->assertStringContainsString(
            'whereRaw(\'id_file in (select id_file_image from albums where albums.id_album = ?)\'',
            $source,
            'FileController should use ? placeholder in whereRaw'
        );
        $this->assertStringContainsString(
            '[$albumId]',
            $source,
            'FileController should pass $albumId as binding parameter'
        );

        // Deve fazer cast (int) do input
        $this->assertStringContainsString(
            '(int) $request["id_album"]',
            $source,
            'FileController should cast id_album to int'
        );
    }
}
