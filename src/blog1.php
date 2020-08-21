<?php

require __DIR__ . '/../vendor/autoload.php';

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\HttpClient;
use Amp\Loop;
use Amp\ByteStream\ClosedException;
use Amp\File\File;
use Amp\LazyPromise;

/**
 * @template A
 */
interface IO extends Amp\Promise
{
    /**
     * @param A $io
     * @return bool
     */
    public function equals($io);
}

class WriteIO implements IO
{
    /** @var string */
    private $message;

    /** @var LazyPromise */
    private $promise;

    public function __construct(File $handle = null, string $message)
    {
        $this->message = $message;
        $this->promise = new LazyPromise(
            function() use ($handle, $message) { 
                if (is_null($handle)) {
                    throw new Exception('File handle is null');
                }
                return $handle->write($this->message);
            }
        );
    }

    public function onResolve(callable $onResolved)
    {
        $this->promise->onResolve($onResolved);
    }

    public function equals($io): bool
    {
        return (string) $io === (string) $this;
    }

    public function __toString(): string
    {
        return $this->message;
    }
}

class Writer
{
    /** @var File */
    private $handle;

    public function __construct(File $handle)
    {
        $this->handle = $handle;
    }

    public function write(string $message): WriteIO
    {
        return new WriteIO($this->handle, $message);
    }
}

class HttpIO implements IO
{
    /** @var Request */
    private $request;

    /** @var LazyPromise */
    private $promise;
    
    public function __construct(HttpClient $httpClient = null, Request $request)
    {
        $this->request = $request;
        $this->promise = new LazyPromise(
            function() use ($httpClient, $request) {
                if (is_null($httpClient)) {
                    throw new Exception('HttpClient is null');
                }
                return $httpClient->request($request);
            }
        );
    }

    public function equals($io): bool
    {
        return $io->request === $this->request;
    }

    public function onResolve(callable $onResolved)
    {
        $this->promise->onResolve($onResolved);
    }
}

class Http
{
    /** @var HttpClient */
    private $client;
    public function __construct(HttpClient $client)
    {
        $this->client = $client;
    }

    public function request(Request $request): HttpIO
    {
        return new HttpIO($this->client, $request);
    }
}

class FileSaver
{
    /** @var Writer */
    private $writer;

    /** @var Http */
    private $http;

    public function __construct(Writer $writer, Http $http)
    {
        $this->writer = $writer;
        $this->http = $http;
    }

    /**
     * @return Generator<WriteIO|HttpIO>
     */
    public function saveFile(string $path, int $file): generator
    {
        yield $this->writer->write('Saving file ' . $path);

        $request = new Request('https://google.com', 'POST');
        $request->setBody($file);

        $response = yield $this->http->request($request);

        if ($response->getStatus() === 200) {
            yield $this->writer->write('Successfully saved file ' . $path);
        } else {
            $msg = 'Failed to save file ' . $path;
            yield $this->writer->write($msg);
            throw new Exception($msg);
        }
    }
}

Loop::run(function() {
    $httpClient = HttpClientBuilder::buildDefault();
    $http = new Http($httpClient);
    $logFile = yield Amp\File\open('log.txt', "c+");
    $writer = new Writer($logFile);
    $fileSaver = new FileSaver($writer, $http);
    yield from $fileSaver->saveFile('moo', 0);
});

function test(): generator
{
    $httpClient = HttpClientBuilder::buildDefault();
    $http = new Http($httpClient);
    $logFile = yield Amp\File\open('log.txt', "c+");
    $writer = new Writer($logFile);
    $fileSaver = new FileSaver($writer, $http);

    $effects = [
        [
            'yield' => new WriteIO(null, 'Saving file moo'),
            'send'  => null
        ],
        [
            'yield' => null,
            'send'  => new class { public function getStatus(): int { return 200; } }
        ]
    ];
    $gen = $fileSaver->saveFile('moo', 0);
    testGenerator($gen, $effects);
}

function testGenerator(generator $gen, array $effects): void
{
    foreach ($gen as $i => $promise) {
        if ($effects[$i]['yield']) {
            if (!$promise->equals($effects[$i]['yield'])) {
                throw new Exception('Failed test');
            }
        }
        if ($effects[$i]['send']) {
            $gen->send($effects[$i]['send']);
        }
    }
}
