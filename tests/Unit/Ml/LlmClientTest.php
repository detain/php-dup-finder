<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Ml;

use Phpdup\Ml\LlmClient;
use PHPUnit\Framework\TestCase;

/**
 * Item 8.2 — the client is the wire contract: relabelled SSRF admission,
 * strict decode guards on both passes, env-only bearer, and null-on-
 * failure everywhere. Every test here runs through the injectable
 * transport seam; zero sockets are opened by this family.
 */
final class LlmClientTest extends TestCase
{
    private ?string $savedToken;

    protected function setUp(): void
    {
        $this->savedToken = getenv(LlmClient::TOKEN_ENV) === false ? null : (string) getenv(LlmClient::TOKEN_ENV);
    }

    protected function tearDown(): void
    {
        if ($this->savedToken === null) {
            putenv(LlmClient::TOKEN_ENV);
        } else {
            putenv(LlmClient::TOKEN_ENV . '=' . $this->savedToken);
        }
    }

    /** @param (callable(string,string,list<string>): ?string)|null $transport */
    private function client(callable $transport, string $token = 'sekret'): LlmClient
    {
        return new LlmClient('https://llm.example/v1', 'm1', $token, false, 5, $transport);
    }

    public function testAdmissionRefusalsSpeakTheLlmKeyNames(): void
    {
        $garbage = LlmClient::urlAdmissionError('not a url', false);
        $this->assertNotNull($garbage);
        $this->assertStringContainsString('llm_url', $garbage);
        $this->assertStringNotContainsString('ml_pair', $garbage, 'an operator must never read a foreign key name in an llm_url refusal');

        $private = LlmClient::urlAdmissionError('http://10.0.0.5:8080', false);
        $this->assertNotNull($private, 'the shared address matrix still refuses private ranges');
        $this->assertStringContainsString('llm_allow_private', $private);
        $this->assertStringNotContainsString('--ml-pair-allow-private', $private, 'llm_* are config-file keys; no CLI hint may surface');
        $this->assertStringNotContainsString('ml_pair', $private);

        $this->assertNull(LlmClient::urlAdmissionError('https://llm.example/v1', false), 'a public https endpoint sails the door');
        $this->assertNull(LlmClient::urlAdmissionError('http://10.0.0.5:8080', true), 'the explicit opt-in admits the private sidecar');
    }

    public function testTokenIsEnvOnlyAndBlankMeansUnset(): void
    {
        putenv(LlmClient::TOKEN_ENV);
        $this->assertNull(LlmClient::tokenFromEnv());
        putenv(LlmClient::TOKEN_ENV . '=   ');
        $this->assertNull(LlmClient::tokenFromEnv(), 'a whitespace-only bearer is never sent');
        putenv(LlmClient::TOKEN_ENV . '= abc ');
        $this->assertSame('abc', LlmClient::tokenFromEnv(), 'pasted tokens survive surrounding whitespace');
    }

    public function testBearerHeaderRidesOnlyWhenTokenExists(): void
    {
        $seen = [];
        $transport = static function (string $url, string $body, array $headers) use (&$seen): ?string {
            $seen[] = [$url, $headers];

            return '{"annotations":[]}';
        };

        $this->client($transport)->annotateBatch([['finding_id' => 'F1']]);
        $this->assertSame('https://llm.example/v1/annotate', $seen[0][0], 'the batch rides the /annotate endpoint');
        $this->assertSame(['Authorization: Bearer sekret'], $seen[0][1]);

        $seen = [];
        $noToken = new LlmClient('https://llm.example/v1', 'm1', null, false, 5, $transport);
        $noToken->annotateBatch([['finding_id' => 'F1']]);
        $this->assertSame([], $seen[0][1], 'no credential configured => zero Authorization headers');

        $seen = [];
        $blank = new LlmClient('https://llm.example/v1', 'm1', '', false, 5, $transport);
        $blank->annotateBatch([['finding_id' => 'F1']]);
        $this->assertSame([], $seen[0][1], 'a blank bearer is never sent (the client normalizes the ctor too, not just the env reader)');
        $this->assertFalse($blank->hasToken());
    }

    public function testAnnotateBatchNormalizesDedupesAndDropsGarbageRows(): void
    {
        $packs = [['finding_id' => 'F1'], ['finding_id' => 'F2'], ['finding_id' => 'F3']];
        $response = json_encode([
            'annotations' => [
                ['finding_id' => 'F1', 'title' => ' T ', 'proposal' => 'P', 'risk' => ' MEDIUM ', 'effort' => ' m ', 'intentional' => true],
                ['finding_id' => 'F1', 'title' => 'dup', 'proposal' => 'd', 'risk' => 'low', 'effort' => 'S', 'intentional' => false],
                ['finding_id' => 'F2', 'title' => 'x', 'proposal' => 'y', 'risk' => 'extreme', 'effort' => 'S', 'intentional' => false],
                ['finding_id' => 'F3', 'title' => 'x', 'proposal' => 'y', 'risk' => 'low', 'effort' => 'XL', 'intentional' => 'yes'],
                'not-an-array',
                ['title' => 'missing id', 'proposal' => 'p', 'risk' => 'low', 'effort' => 'S', 'intentional' => false],
            ],
        ]);
        $rows = $this->client(static fn (): ?string => $response)->annotateBatch($packs);

        $this->assertNotNull($rows);
        $this->assertCount(1, $rows, 'garbage rows lose only themselves; the dedup keeps the first verdict per finding');
        $this->assertSame([
            'finding_id' => 'F1', 'title' => 'T', 'proposal' => 'P', 'risk' => 'medium', 'effort' => 'M', 'intentional' => true,
        ], $rows[0], 'risk lower-cases, effort upper-cases, title trims — the vocabularies are the guard');
    }

    public function testAnnotateBatchRefusesUnusableTransports(): void
    {
        $packs = [['finding_id' => 'F1']];

        $this->assertNull($this->client(static fn (): ?string => null)->annotateBatch($packs), 'transport failure is null, never an exception');
        $this->assertNull($this->client(static fn (): ?string => '][')->annotateBatch($packs), 'non-JSON bodies are refusals');
        $this->assertNull($this->client(static fn (): ?string => '{"wrong":"shape"}')->annotateBatch($packs));
        $this->assertNull($this->client(static fn (): ?string => '{"annotations":[]}')->annotateBatch($packs), 'an empty verdict set is a miss, not a success');
        $this->assertNull($this->client(static fn (): ?string => '{"annotations":"scalar"}')->annotateBatch($packs));
    }

    public function testAnnotateRequestCarriesModelTempZeroAndPacks(): void
    {
        $captured = null;
        $transport = static function (string $url, string $body, array $headers) use (&$captured): ?string {
            $captured = json_decode($body, true);

            return '{"annotations":[{"finding_id":"F1","title":"t","proposal":"p","risk":"low","effort":"S","intentional":false}]}';
        };

        $this->client($transport)->annotateBatch([['finding_id' => 'F1', 'rank' => 1]]);
        $this->assertNotNull($captured);
        $this->assertSame('m1', $captured['model']);
        $this->assertSame(0, $captured['temperature'], 'the §8.2 ruling pins temperature 0');
        $this->assertIsString($captured['instructions']);
        $this->assertSame('F1', $captured['packs'][0]['finding_id']);
        $this->assertSame(['finding_id' => 'F1', 'rank' => 1], $captured['packs'][0]['pack'], 'the pack document rides whole (the builder already capped it)');
    }

    public function testMergeThemesStrictGuards(): void
    {
        $annotated = [['finding_id' => 'F1', 'title' => 't', 'risk' => 'low', 'effort' => 'S', 'intentional' => false]];

        $good = '{"themes":[{"name":"retry","label":"outbound HTTP retry","members":["F1"]}],"summary":"s","landing_order":["retry"]}';
        $merged = $this->client(static fn (): ?string => $good)->mergeThemes($annotated);
        $this->assertNotNull($merged);
        $this->assertSame('outbound HTTP retry', $merged['themes'][0]['label'], 'the semantic label the docs deferred to 8.2 lands here');
        $this->assertSame('s', $merged['summary']);
        $this->assertSame(['retry'], $merged['landing_order']);

        $missingLabel = '{"themes":[{"name":"retry","members":["F1"]}],"summary":"s","landing_order":[]}';
        $merged = $this->client(static fn (): ?string => $missingLabel)->mergeThemes($annotated);
        $this->assertNotNull($merged);
        $this->assertSame('', $merged['themes'][0]['label'], 'a bare name without a phrase is still a usable theme (label degrades to empty, never fabricated)');

        foreach ([
            'garbage',
            '{"themes":"scalar","summary":"s","landing_order":[]}',
            '{"themes":[{"name":"t","members":["F1"]}],"summary":"s"}',
            '{"themes":[{"members":["F1"]}],"summary":"s","landing_order":[]}',
            '{"themes":[{"name":"t","members":[42]}],"summary":"s","landing_order":[]}',
            '{"themes":[{"name":"t","members":["F1"]}],"summary":"s","landing_order":[""]} ',
            '{"themes":[{"name":"","members":["F1"]}],"summary":"s","landing_order":[]}',
        ] as $i => $bad) {
            $this->assertNull(
                $this->client(static fn (): ?string => (string) $bad)->mergeThemes($annotated),
                'theme shape violation ' . $i . ' must refuse the whole pass (half-trusted groupings mislead the schedule)',
            );
        }
    }

    public function testEndpointUrlAndHostAccessors(): void
    {
        $client = $this->client(static fn (): ?string => null);
        $this->assertSame('https://llm.example/v1/themes', LlmClient::endpointUrl('https://llm.example/v1/', '/themes'));
        $this->assertSame('llm.example', $client->host());
        $this->assertSame('m1', $client->model());
        $this->assertTrue($client->hasToken());
        $this->assertFalse((new LlmClient('https://LLM.Example/x', '', null))->hasToken());
        $this->assertSame('llm.example', (new LlmClient('https://LLM.Example/x'))->host(), 'cache scoping lower-cases the host');
        $this->assertSame('', (new LlmClient('garbage'))->host(), 'unparseable => no host claim');
    }

    public function testAnnotationRowValidGatesCachedEntries(): void
    {
        $this->assertTrue(LlmClient::annotationRowValid([
            'finding_id' => 'F1', 'title' => 't', 'proposal' => 'p', 'risk' => 'low', 'effort' => 'S', 'intentional' => true,
        ]));
        $this->assertFalse(LlmClient::annotationRowValid(['finding_id' => 'F1', 'title' => 't', 'proposal' => 'p', 'risk' => 'nope', 'effort' => 'S', 'intentional' => true]), 'a hand-edited cache file is a miss');
        $this->assertFalse(LlmClient::annotationRowValid(['finding_id' => '', 'title' => 't', 'proposal' => 'p', 'risk' => 'low', 'effort' => 'S', 'intentional' => true]));
    }

    public function testBatchAndResponseCeilings(): void
    {
        $this->assertSame(20, LlmClient::BATCH_SIZE, 'the letter batches; 20 packs/request is the shipped ceiling');
        $this->assertSame(4_194_304, LlmClient::MAX_RESPONSE_BYTES, 'own ceiling (not the 1 MiB ml-pair sidecar cap): batch verdicts are bigger than pair scores — disclosed in the report');
    }
}
