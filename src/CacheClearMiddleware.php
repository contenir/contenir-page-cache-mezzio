<?php

declare(strict_types=1);

namespace Contenir\PageCache\Mezzio;

use Contenir\PageCache\Clear\ClearProtocol;
use Contenir\PageCache\Clear\ClearVerifier;
use Contenir\PageCache\Clear\RefusedClear;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\TextResponse;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheException;
use Psr\SimpleCache\CacheInterface;

use function implode;

/**
 * Answers the CMS's request to clear the site's caches at the protocol's fixed `/api/cache`:
 * checks the signed token in its Authorization header, then clears the page store, moving its
 * generation on, and each other cache the site lists, through their own stores. Every other
 * request passes through. Pipe it before the page cache and routing.
 *
 * Answers `204` once everything is cleared, `500` naming what could not be, `405` to anything
 * but a POST, and the protocol's status and reason to a refused token. With no `pagecache.clear`
 * configured, it passes everything through, so the endpoint does not exist.
 *
 * @api
 */
final readonly class CacheClearMiddleware implements MiddlewareInterface
{
    /** The name a failure to clear the page store is reported by. */
    public const string PAGES = 'pages';

    private const array NO_STORE = ['Cache-Control' => 'no-store'];

    /**
     * @param array<string, CacheInterface> $caches The other caches to clear, by the service names the site listed
     */
    public function __construct(
        private ?ClearVerifier $verifier,
        private PageStore $store,
        private array $caches = [],
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (null === $this->verifier || ClearProtocol::ENDPOINT !== $request->getUri()->getPath()) {
            return $handler->handle($request);
        }

        if ('POST' !== $request->getMethod()) {
            return new EmptyResponse(405, ['Allow' => 'POST', ...self::NO_STORE]);
        }

        try {
            $this->verifier->verify($request->getHeaderLine('Authorization'));
        } catch (RefusedClear $refused) {
            return new TextResponse($refused->getMessage(), $refused->status, self::NO_STORE);
        }

        $failed = $this->clear();

        return [] === $failed
            ? new EmptyResponse(204, self::NO_STORE)
            : new TextResponse('Could not clear: ' . implode(', ', $failed) . '.', 500, self::NO_STORE);
    }

    /**
     * Clears every cache, whichever fail, and names those that did.
     *
     * @return list<string>
     */
    private function clear(): array
    {
        $failed = $this->store->clear() ? [] : [self::PAGES];
        foreach ($this->caches as $name => $cache) {
            try {
                $cleared = $cache->clear();
            } catch (CacheException) {
                $cleared = false;
            }

            if (! $cleared) {
                $failed[] = $name;
            }
        }

        return $failed;
    }
}
