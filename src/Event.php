<?php

namespace SseClient;

use InvalidArgumentException;

/**
 * Class Event
 *
 * Represents an event received from a Server-Sent Events (SSE) stream, with methods to parse raw event data.
 */
class Event
{
    /**
     * Regex pattern to identify the end of a line in the SSE message.
     */
    const END_OF_LINE = "/\r\n|\n|\r/";

    /** 
     * @var string The data content of the event. 
     */
    private $data;

    /** 
     * @var string The type of the event (e.g., "message").
     */
    private $eventType;

    /** 
     * @var ?string The ID of the event, if provided.
     */
    private $id;

    /** 
     * @var ?int The reconnection time requested by the server, if provided.
     */
    private $retry;

    /**
     * Constructor.
     *
     * Initializes an Event instance with the specified properties.
     *
     * @param string $data The event data.
     * @param string $eventType The type of event.
     * @param ?string $id The event ID.
     * @param ?int $retry The reconnection time in milliseconds.
     */
    public function __construct(string $data = '', string $eventType = 'message', ?string $id = null, ?int $retry = null)
    {
        $this->data = $data;
        $this->eventType = $eventType;
        $this->id = $id ?? '';
        $this->retry = $retry ?? 0;
    }

    /**
     * Parse raw event data into an Event object.
     *
     * Parses a raw string from the SSE stream and extracts the event type, data, ID, 
     * and retry information, if present.
     *
     * @param string $raw Raw event string data.
     * @return Event Parsed Event instance.
     * @throws InvalidArgumentException if the input format is invalid.
     */
    public static function parse(string $raw): Event
    {
        $event = new self();
        $lines = preg_split(self::END_OF_LINE, $raw);

        if (!is_array($lines)) {
            throw new InvalidArgumentException('Invalid input format.');
        }

        foreach ($lines as $line) {
            $matched = preg_match('/(?P<name>[^:]*):?( ?(?P<value>.*))?/', $line, $matches);

            if (!$matched) {
                throw new InvalidArgumentException(sprintf('Invalid line %s', $line));
            }

            $name = $matches['name'];
            $value = $matches['value'] ?? '';

            if ($name === '') {
                // Ignore comments.
                continue;
            }

            switch ($name) {
                case 'event':
                    $event->eventType = $value;
                    break;
                case 'data':
                    $event->data = empty($event->data) ? $value : "$event->data\n$value";
                    break;
                case 'id':
                    $event->id = $value;
                    break;
                case 'retry':
                    $event->retry = (int) $value;
                    break;
                default:
                    // Ignore unknown fields.
                    continue 2;
            }
        }

        return $event;
    }

    /**
     * Get the data content of the event.
     *
     * @return string The event data.
     */
    public function getData(): string
    {
        return $this->data;
    }

    /**
     * Get the event type.
     *
     * @return string The event type (e.g., "message").
     */
    public function getEventType(): string
    {
        return $this->eventType;
    }

    /**
     * Get the ID of the event.
     *
     * @return string|false The event ID, or false if not set.
     */
    public function getId(): string | false
    {
        return $this->id ?? false;
    }

    /**
     * Get the retry time for reconnection.
     *
     * @return int|false The retry time in milliseconds, or false if not set.
     */
    public function getRetry(): int | false
    {
        return $this->retry ?? false;
    }
}