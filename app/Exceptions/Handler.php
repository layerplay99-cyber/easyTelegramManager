<?php

namespace App\Exceptions;

use Catch\Enums\Code;
use Catch\Exceptions\CatchException;
use Catch\Exceptions\FailedException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }


    /**
     * render
     *
     * @param $request
     * @param Throwable $e
     * @return JsonResponse|Response
     * @throws Throwable
     */
    public function render($request, Throwable $e): JsonResponse|Response
    {
        // 针对表单验证异常，返回多语言
        if ($e instanceof \Illuminate\Validation\ValidationException) {
            $errors = $e->validator->errors();
            return response()->json([
                'code' => 10005,
                'message' => $errors->first(), // 多语言
                'errors' => $errors,
            ], 422);
        }

        $message = __($e->getMessage());

        if (method_exists($e, 'getStatusCode')) {
            if ($e->getStatusCode() == Response::HTTP_NOT_FOUND) {
                $message = __('route_not_found_or_register');
            }
        }

        $e = new FailedException($message ?: __('Server Error'), $e instanceof CatchException ? $e->getCode() : Code::FAILED);

        $response = parent::render($request, $e);

        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Access-Control-Allow-Methods', '*');
        $response->header('Access-Control-Allow-Headers', '*');
        return $response;
    }
}
