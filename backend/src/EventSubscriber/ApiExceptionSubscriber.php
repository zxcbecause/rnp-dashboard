<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Ai\Llm\AiUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Renders every exception under /api as JSON: {"error": "...", "violations": {...}?}.
 * Internal errors never leak their message outside debug mode.
 */
final class ApiExceptionSubscriber
{
    public function __construct(
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    #[AsEventListener(event: ExceptionEvent::class, priority: -10)]
    public function onException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $exception = $event->getThrowable();
        $status = $exception instanceof HttpExceptionInterface
            ? $exception->getStatusCode()
            : Response::HTTP_INTERNAL_SERVER_ERROR;
        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

        $payload = ['error' => $this->message($exception, $status)];

        $previous = $exception->getPrevious();
        if ($exception instanceof UnprocessableEntityHttpException && $previous instanceof ValidationFailedException) {
            $violations = [];
            foreach ($previous->getViolations() as $violation) {
                $violations[$violation->getPropertyPath()][] = (string) $violation->getMessage();
            }
            $payload['error'] = 'Validation failed.';
            $payload['violations'] = $violations;
        }

        $response = new JsonResponse(null, $status, $headers);
        $response->setEncodingOptions($response->getEncodingOptions() | \JSON_UNESCAPED_UNICODE);
        $event->setResponse($response->setData($payload));
    }

    private function message(\Throwable $exception, int $status): string
    {
        // Written for the user ("AI is unavailable, try later"): never carries internals.
        if ($exception instanceof AiUnavailableException) {
            return $exception->getMessage();
        }

        if ($status >= 500 && !$this->debug) {
            return 'Internal server error.';
        }

        if ($exception instanceof HttpExceptionInterface && '' !== $exception->getMessage()) {
            // Symfony's router puts the full URL into the message; keep the generic text instead.
            return Response::HTTP_NOT_FOUND === $status ? 'Not found.' : $exception->getMessage();
        }

        return Response::$statusTexts[$status] ?? 'Error.';
    }
}
