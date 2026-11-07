<?php

namespace Tests\Support\Assistant;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client for the Anthropic SDK that records every request it is
 * handed and answers with a canned Messages API response. Lets a test see the
 * exact JSON the real SDK would put on the wire — where cache_control landed,
 * which beta header went out — without a network call or an API key.
 */
final class RecordingTransport implements ClientInterface
{
    /** @var array<int, RequestInterface> */
    public array $requests = [];

    /** @param  array<string, mixed>  $usage */
    public function __construct(
        private string $text = 'OK',
        private array $usage = ['input_tokens' => 40, 'output_tokens' => 5, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return new Response(200, ['Content-Type' => 'application/json', 'request-id' => 'req_test'], json_encode([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => $this->text]],
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => $this->usage,
        ]));
    }

    /** @return array<string, mixed> */
    public function body(int $n = 0): array
    {
        $request = $this->requests[$n];
        $request->getBody()->rewind();

        return json_decode((string) $request->getBody(), true);
    }

    public function header(string $name, int $n = 0): string
    {
        return $this->requests[$n]->getHeaderLine($name);
    }
}
