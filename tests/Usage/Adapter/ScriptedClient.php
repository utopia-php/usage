<?php

namespace Utopia\Tests\Usage\Adapter;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Exception\TimeoutException;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

/**
 * PSR-18 transport that replays a scripted sequence of outcomes and records
 * what was sent. Lets the read-cancellation path be exercised without a server
 * that can be made to hang on demand.
 */
final class ScriptedClient implements ClientInterface
{
    public const TIMEOUT = 'timeout';

    /** @var array<int, string> URLs of the requests sent, in order */
    public array $urls = [];

    /** @var array<int, string> Bodies of the requests sent, in order */
    public array $bodies = [];

    /** @param array<int, ResponseInterface|string> $script One entry per request; TIMEOUT throws a transport timeout */
    public function __construct(private array $script = [])
    {
    }

    public static function response(int $status, string $body = ''): ResponseInterface
    {
        return new Response($status, '', new Stream($body));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->urls[] = (string) $request->getUri();
        $this->bodies[] = (string) $request->getBody();

        $next = array_shift($this->script);

        if ($next === self::TIMEOUT) {
            throw new TimeoutException($request, 'Operation timed out');
        }

        return $next instanceof ResponseInterface ? $next : self::response(200, '{"data":[]}');
    }
}
