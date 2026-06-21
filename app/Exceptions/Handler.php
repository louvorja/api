<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Laravel\Lumen\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that should not be reported.
     *
     * @var array
     */
    protected $dontReport = [
        AuthorizationException::class,
        HttpException::class,
        ModelNotFoundException::class,
        ValidationException::class,
    ];

    /**
     * Report or log an exception.
     *
     * @param  \Throwable  $exception
     * @return void
     *
     * @throws \Exception
     */
    public function report(Throwable $exception)
    {
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * All errors return JSON with a consistent { error, code } structure.
     * In debug mode, the message includes the exception details.
     * In production, generic messages are used for server errors.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     *
     * @throws \Throwable
     */
    public function render($request, Throwable $exception)
    {
        $status = $this->getStatusCode($exception);
        $message = $this->getErrorMessage($exception, $status);

        // Validation errors: include field-level messages
        if ($exception instanceof ValidationException) {
            return response()->json([
                'error' => implode(PHP_EOL, array_merge(...array_values($exception->errors()))),
                'messages' => $exception->errors(),
                'code' => 422,
            ], 422);
        }

        // All other errors: consistent { error, code } JSON
        return response()->json([
            'error' => $message,
            'code' => $status,
        ], $status);
    }

    /**
     * Determine the HTTP status code for the exception.
     */
    private function getStatusCode(Throwable $exception): int
    {
        if ($exception instanceof ValidationException) {
            return 422;
        }

        if ($exception instanceof HttpException) {
            return $exception->getStatusCode();
        }

        return 500;
    }

    /**
     * Get the error message for the exception.
     * Debug mode shows real messages; production shows generic ones for 5xx.
     */
    private function getErrorMessage(Throwable $exception, int $status): string
    {
        $debug = config('api.debug', false);

        if ($status >= 500 && !$debug) {
            return 'Erro interno do servidor.';
        }

        return $exception->getMessage() ?: $this->defaultMessage($status);
    }

    /**
     * Default messages for common HTTP status codes.
     */
    private function defaultMessage(int $status): string
    {
        $messages = [
            401 => 'Token inválido ou expirado.',
            403 => 'Acesso negado.',
            404 => 'Recurso não encontrado.',
            405 => 'Método não permitido.',
            422 => 'Dados inválidos.',
            429 => 'Muitas requisições. Tente novamente mais tarde.',
            500 => 'Erro interno do servidor.',
            503 => 'Serviço indisponível.',
        ];

        return $messages[$status] ?? 'Erro desconhecido.';
    }
}
