<?php

declare(strict_types=1);

namespace Quanta\Http;

use Psr\Http\Message\ResponseInterface;

final class Server
{
    /**
     * Means the buffered output must be emitted before the response body.
     *
     * @var string
     */
    const PREPEND = 'prepend';

    /**
     * Means the buffered output must be emitted after the response body.
     *
     * @var string
     */
    const APPEND = 'append';

    /**
     * Means the buffered output must not be emitted.
     *
     * @var string
     */
    const CLEAN = 'clean';

    /**
     * The application callable to execute.
     *
     * It is expected to return a psr-7 response implementation.
     *
     * @var callable
     */
    private $app;

    /**
     * The output buffering mode.
     *
     * It can be self::PREPEND, self::APPEND or self::CLEAN.
     *
     * @var string
     */
    private $mode;

    /**
     * Constructor.
     *
     * @param callable  $app
     * @param string    $mode
     */
    public function __construct(callable $app, string $mode = self::PREPEND)
    {
        if (! in_array($mode, [self::PREPEND, self::APPEND, self::CLEAN])) {
            throw new \InvalidArgumentException(
                $this->invalidOutputBufferingModeErrorMessage($mode)
            );
        }

        $this->app = $app;
        $this->mode = $mode;
    }

    /**
     * Get a response from the application callable and emits it.
     *
     * Any output leacking from the callable is buffered and emitted according
     * to the output buffering mode.
     *
     * - self::PREPEND => prepended to the response body (default)
     * - self::APPEND => appended to the response body
     * - self::CLEAN => not emitted
     */
    public function run()
    {
        // start the output buffer.
        ob_start();

        $level = ob_get_level();

        // get a response.
        $response = ($this->app)();

        if (! $response instanceof ResponseInterface) {
            throw new \UnexpectedValueException(
                $this->unexpectedResponseTypeErrorMessage($response)
            );
        }

        // clean unflushed buffers and get the content of the current one.
        header_remove();

        while (ob_get_level() > $level) ob_end_clean();

        $output = ob_get_clean();

        // emit the response.
        $this->headers($response);

        if ($this->mode == self::PREPEND) echo $output;

        $this->body($response);

        if ($this->mode == self::APPEND) echo $output;
    }

    /**
     * Emit the headers of the given response.
     *
     * @param \Psr\Http\Message\ResponseInterface $response
     * @return void
     */
    private function headers(ResponseInterface $response)
    {
        $http_line = sprintf('HTTP/%s %s %s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase()
        );

        header($http_line, true, $response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header("$name: $value", false);
            }
        }
    }

    /**
     * Emit the body of the given response.
     *
     * @param \Psr\Http\Message\ResponseInterface $response
     * @return void
     */
    private function body(ResponseInterface $response)
    {
        $stream = $response->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        while (!$stream->eof()) {
            echo $stream->read(1024 * 8);
        }
    }

    /**
     * Return the message of the exception thrown when the output buffering mode
     * is not valid.
     *
     * @param string $mode
     * @return string
     */
    private function invalidOutputBufferingModeErrorMessage(string $mode): string
    {
        return vsprintf('%s output buffering mode must be \'%s\', \'%s\' or \'%s\', \'%s\' given', [
            self::class,
            self::PREPEND,
            self::APPEND,
            self::CLEAN,
            $mode,
        ]);
    }

    /**
     * Return the message of the exception thrown when the type of the response
     * returned by the app is unexpected.
     *
     * @param mixed $response
     * @return string
     */
    private function unexpectedResponseTypeErrorMessage($response): string
    {
        return vsprintf('%s expect the app callable to return an implementation of %s, %s returned', [
            self::class,
            ResponseInterface::class,
            is_object($response)
                ? 'instance of ' . get_class($response)
                : gettype($response),
        ]);
    }
}
