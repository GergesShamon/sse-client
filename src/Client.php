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
     * @var string|false ID of the last received message.
     */
    private $lastId;

    /**
     * @var int Reconnection time in milliseconds.
     */
    private $retry = self::RETRY_DEFAULT_MS;
    
    /**
     * @var string Path to the file where the lastId will be stored.
     */
    private $lastIdFile;
    
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
        
        $this->lastId = $this->loadLastIdFromFile();
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
    /**
     * Load the last received event ID from a file.
     *
     * @return false|string The last event ID or false if the file doesn't exist or is empty.
     */
    private function loadLastIdFromFile(): false|string 
    {
        if (file_exists($this->lastIdFile)) {
            $lastId = file_get_contents($this->lastIdFile);
            return $lastId ?: false;
        }
        return false;
    }
    /**
     * Sets the file path where the last processed ID will be saved.
     *
     * @param string $lastIdFile The path to the file where the last ID will be stored.
     * @return void
     */
    public function saveLastIdToFile(string $lastIdFile): void 
    {
        $this->lastIdFile = $lastIdFile;
    }
    
    /**
     * Destructor that writes the last processed ID to the specified file.
     *
     * This method is called automatically when the object is destroyed.
     * If a file path was set with `saveLastIdToFile()`, it saves the value
     * of `$this->lastId` to that file.
     */
    public function __destruct()
    {
        if ($this->lastIdFile) {
            file_put_contents($this->lastIdFile, $this->lastId);
        }
    }
}
