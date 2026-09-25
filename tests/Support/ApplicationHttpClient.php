<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PhpSoftBox\Application\Application;
use PhpSoftBox\Http\Message\ServerRequest;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final readonly class ApplicationHttpClient implements ClientInterface
{
    public function __construct(
        private Application
    $application)
    {
    }
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->application->handle(new ServerRequest($request->getMethod(), $request->getUri(), $request->getHeaders(), (string) $request->getBody()));
    }
}
