<?php

declare(strict_types=1);

namespace Contenir\PageCache\Mezzio;

use Laminas\Diactoros\StreamFactory;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function strtoupper;

/**
 * Full-page output cache, controlled by the Contenir admin.
 *
 * The policy decides whether this request may use the cache and under which
 * key (reading the admin's state afresh on every request), stamped with the
 * store's generation before anything renders; the store serves the page from
 * the cache, or the handler runs and the store keeps its response when it is
 * cacheable. A HEAD request is answered from the GET's
 * entry, but a HEAD miss is never stored, since its body is empty.
 *
 * Every response the middleware returns then has the veto header removed and
 * passes through the mutators, after storage, so per-request changes are never
 * cached.
 *
 * @api
 */
final readonly class PageCacheMiddleware implements MiddlewareInterface
{
    /**
     * Set this header on a response (any value; `off` by convention) to stop
     * it being stored. The middleware removes it before the response is sent.
     * The PSR-15 counterpart of the MVC strategy's `pagecache.disable` event.
     */
    public const string VETO_HEADER = 'X-Page-Cache';

    /**
     * Diagnostic header carrying HIT or MISS, as the MVC strategy sets.
     */
    public const string STATUS_HEADER = 'X-PK-Cache';

    /**
     * @param list<ResponseMutatorInterface> $mutators
     */
    public function __construct(
        private CachePolicy $policy,
        private PageStore $store,
        private array $mutators = [],
    ) {}

    /**
     * Marks a response so the page cache will not store it.
     */
    public static function veto(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader(self::VETO_HEADER, 'off');
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ticket = $this->policy->ticketFor($request);
        if (null === $ticket) {
            return $this->finish($handler->handle($request), $request, CacheResult::Bypass);
        }

        $ticket = $this->store->stamped($ticket);

        $isGet = 'GET' === strtoupper($request->getMethod());
        $hit   = $this->store->fetch($ticket);
        if (null !== $hit) {
            $hit = $isGet ? $hit : $hit->withBody((new StreamFactory())->createStream());

            return $this->finish($hit, $request, CacheResult::Hit);
        }

        $response = $handler->handle($request);
        if (! $isGet || ! $this->store->save($ticket, $response)) {
            return $this->finish($response, $request, CacheResult::Bypass);
        }

        return $this->finish($response->withHeader(self::STATUS_HEADER, 'MISS'), $request, CacheResult::Miss);
    }

    private function finish(
        ResponseInterface $response,
        ServerRequestInterface $request,
        CacheResult $result,
    ): ResponseInterface {
        $response = $response->withoutHeader(self::VETO_HEADER);
        foreach ($this->mutators as $mutator) {
            $response = $mutator->mutate($response, $request, $result);
        }

        return $response;
    }
}
