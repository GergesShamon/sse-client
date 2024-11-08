# SseClient
This is a PHP client library for iterating over http Server Sent Event (SSE) streams (also known as EventSource, after the name of the Javascript interface inside browsers).

## Installation

Use composer to install the library and all its dependencies:

    composer require gerges/sse-client
## Example Usage

```php
$client = new SseClient\Client('https://eventsource.firebaseio-demo.com/.json');

foreach ($client->getEvents() as $event) {
   var_dump($event);
}
```
