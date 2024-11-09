<?php

namespace SseClient;

use GuzzleHttp;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Class Client
 *
 * Manages a Server-Sent Events (SSE) client connection, handling connection retries, 
 * buffering incoming events, and yielding parsed events.
 */
class Client
{
    /**
     * Default reconnection time in milliseconds.
     */
    const RETRY_DEFAULT_MS = 3000;

    /**
     * Regex pattern to identify the end of a message in the stream.
     */
    const END_OF_MESSAGE = "/\r\n\r\n|\n\n|\r\r/";

    /** 
     * @var GuzzleHttp\Client HTTP client for connecting to the SSE server.
     */
    private $client;

    /** 
     * @var ResponseInterface Holds the HTTP response of the SSE connection.
     */
    private $response;

    /**
     * @var string URL for the SSE connection.
     */
    private $url;

    /**
     * @var string ID of the last received message.
     */
    private $lastId;

    /**
     * @var int Reconnection time in milliseconds.
     */
    private $retry = self::RETRY_DEFAULT_MS;
    
    /**
     * Constructor.
     *
     * Initializes the SSE client and opens a connection to the specified URL.
     *
     * @param string $url URL for the SSE server.
     * @param array<string, mixed> $config for GuzzleHttp Client.
     */
    public function __construct(string $url, array $config = [])
    {
        if (!isset($config['headers'])) {
            $config['headers'] = [];
        }
        $config['headers']['Accept'] = 'text/event-stream';
        $config['headers']['Cache-Control'] = 'no-cache';
        $this->url = $url;
        $this->client = new GuzzleHttp\Client($config);
        $this->connect();
    }

    /**
     * Connect to the SSE server.
     *
     * If a previous message ID is available, it will be sent in the headers
     * as 'Last-Event-ID' for the server to resume from the last received event.
     *
     * @throws RuntimeException if the server responds with a 204 status code.
     */
    private function connect(): void
    {
        $headers = [];
        if ($this->lastId) {
            $headers['Last-Event-ID'] = $this->lastId;
        }

        $this->response = $this->client->request('GET', $this->url, [
            'stream' => true,
            'headers' => $headers,
        ]);

        if ($this->response->getStatusCode() == 204) {
            throw new RuntimeException('Server forbid connection retry by responding 204 status code.');
        }
    }

    /**
     * Get an event generator.
     *
     * Continuously reads from the SSE stream, buffering messages until they are complete,
     * and then yields each parsed event.
     *
     * @return \Generator<Event> Generator that yields new Event instances from the stream.
     */
    public function getEvents(): \Generator
    {
        $buffer = '';
        $body = $this->response->getBody();
        // @phpstan-ignore-next-line
        while (true) {
            // Reconnect if the server closes the connection.
            if ($body->eof()) {
                // Wait for the retry period before reconnecting.
                sleep($this->retry / 1000);
                $this->connect();
                // Clear the buffer to remove any partial message.
                $buffer = '';
            }

            $buffer .= $body->read(1);
            if (preg_match(self::END_OF_MESSAGE, $buffer)) {
                $parts = preg_split(self::END_OF_MESSAGE, $buffer, 2);

                if (is_array($parts) && isset($parts[0], $parts[1])) {
                    $rawMessage = $parts[0];
                    $remaining = $parts[1];
                    $buffer = $remaining;

                    $event = Event::parse($rawMessage);

                    // Update the last received message ID.
                    if ($event->getId()) {
                        $this->lastId = $event->getId();
                    }

                    // Adjust retry time if specified in the message.
                    if ($event->getRetry()) {
                        $this->retry = $event->getRetry();
                    }

                    yield $event;
                }
            }
        }
    }
}
