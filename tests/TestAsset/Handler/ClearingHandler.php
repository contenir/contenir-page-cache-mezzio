<?php

declare(strict_types=1);

namespace Contenir\PageCache\Mezzio\Tests\TestAsset\Handler;

use Contenir\PageCache\Mezzio\PageStore;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A handler during whose rendering the store is cleared, as when the CMS saves while a page is
 * being drawn from the data the save replaces.
 */
final readonly class ClearingHandler implements RequestHandlerInterface
{
    public function __construct(
        private PageStore $store,
        private ResponseInterface $response,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->store->clear();

        return $this->response;
    }
}
