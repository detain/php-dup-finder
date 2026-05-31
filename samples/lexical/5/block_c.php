<?php
declare(strict_types=1);

namespace Acme\Search\Query;

use Acme\Search\Exception\SearchNetworkException;
use Acme\Search\Exception\SearchTimeoutException;
use Acme\Search\SearchRequest;
use Acme\Search\SearchResult;
use Acme\Search\IndexClient;
use Acme\Observability\Tracer;

final class SearchExecutionHandler
{
    public function __construct(
        private readonly IndexClient $index,
        private readonly Tracer $tracer,
    ) {
    }

    public function run(SearchRequest $request): SearchResult
    {
        $span = $this->tracer->startSpan('search.query');

        // identical token shape: try with body, 3 catch arms, finally
        try {
            $hits = $this->index->search($request);
            return SearchResult::fromHits($hits);
        } catch (SearchNetworkException $e) {
            $span->tag('error', 'network');
            return SearchResult::networkFailure();
        } catch (SearchTimeoutException $e) {
            $span->tag('error', 'timeout');
            return SearchResult::timeout();
        } catch (\Throwable $e) {
            $span->tag('error', 'unknown');
            return SearchResult::unknownFailure();
        } finally {
            $span->finish();
        }
    }
}
