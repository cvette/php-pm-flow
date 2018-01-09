<?php
namespace Vette\PHPPM\Bridges;

use Neos\Flow\Http\Cookie;
use PHPPM\Bootstraps\ApplicationEnvironmentAwareInterface;
use PHPPM\Bridges\BridgeInterface;
use PHPPM\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Io\UploadedFile;
use RingCentral\Psr7\Response;
use Neos\Flow\Http\Response as FlowResponse;
use Neos\Flow\Http\Request as FlowRequest;
use function RingCentral\Psr7\stream_for;
use Vette\PHPPM\Flow\ExternalRequestHandler;

class Flow implements BridgeInterface
{
    /**
     * An application implementing the HttpKernelInterface
     *
     * @var \Neos\Flow\Core\Bootstrap
     */
    protected $application;

    /**
     * @var Flow
     */
    protected $bootstrap;


    /**
     * @var string[]
     */
    protected $tempFiles = [];

    /**
     * @param null|string $appBootstrap
     * @param string $appenv
     * @param bool $debug
     */
    public function bootstrap($appBootstrap, $appenv, $debug)
    {
        $appBootstrap = $this->normalizeAppBootstrap($appBootstrap);
        $this->bootstrap = new $appBootstrap();
        if ($this->bootstrap instanceof ApplicationEnvironmentAwareInterface) {
            $this->bootstrap->initialize($appenv, $debug);
        }
        if ($this->bootstrap instanceof \Vette\PHPPM\Bootstraps\Flow) {
            $this->application = $this->bootstrap->getApplication();
        }
    }

    /**
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request)
    {
        if (null === $this->application) {
            // internal server error
            return new Response(500, ['Content-type' => 'text/plain'], 'Application not configured during bootstrap');
        }

        if (!$this->application->getActiveRequestHandler() instanceof ExternalRequestHandler) {
            return new Response(500, ['Content-type' => 'text/plain'], 'Flow request handler is not an instance of ExternalRequestHandler');
        }

        /** @var ExternalRequestHandler $requestHandler */
        $requestHandler = $this->application->getActiveRequestHandler();

        $flowRequest = $this->mapRequest($request);
        // start buffering the output, so cgi is not sending any http headers
        // this is necessary because it would break session handling since
        // headers_sent() returns true if any unbuffered output reaches cgi stdout.
        ob_start();
        try {
            $requestHandler->setRequest($flowRequest);
            $requestHandler->handleRequest();
        } catch (\Exception $exception) {
            // internal server error
            error_log((string)$exception);
            $response = new Response(500, ['Content-type' => 'text/plain'], 'Unexpected error');
            // end buffering if we need to throw
            @ob_end_clean();
            return $response;
        }
        // should not receive output from application->handle()
        @ob_end_clean();

        $flowResponse = $requestHandler->getHttpResponse();
        $response = $this->mapResponse($flowResponse);
        return $response;
    }

    /**
     * Convert React\Http\Request to Neos\Flow\Http\Request
     *
     * @param ServerRequestInterface $psrRequest
     * @return FlowRequest $flowRequest
     */
    protected function mapRequest(ServerRequestInterface $psrRequest)
    {
        $method = $psrRequest->getMethod();
        $query = $psrRequest->getQueryParams();
        // cookies
        $_COOKIE = [];
        $sessionCookieSet = false;
        foreach ($psrRequest->getHeader('Cookie') as $cookieHeader) {
            $cookies = explode(';', $cookieHeader);
            foreach ($cookies as $cookie) {
                list($name, $value) = explode('=', trim($cookie));
                $_COOKIE[$name] = $value;
                if ($name === session_name()) {
                    session_id($value);
                    $sessionCookieSet = true;
                }
            }
        }
        if (!$sessionCookieSet && session_id()) {
            // session id already set from the last round but not obtained
            // from the cookie header, so generate a new one, since php is
            // not doing it automatically with session_start() if session
            // has already been started.
            session_id(Utils::generateSessionId());
        }
        /** @var \React\Http\Io\UploadedFile $file */
        $uploadedFiles = $psrRequest->getUploadedFiles();
        $mapFiles = function(&$files) use (&$mapFiles) {
            foreach ($files as &$value) {
                if (is_array($value)) {
                    $mapFiles($value);
                } else if ($value instanceof UploadedFile) {
                    $tmpname = tempnam(sys_get_temp_dir(), 'upload');
                    $this->tempFiles[] = $tmpname;
                    file_put_contents($tmpname, (string)$value->getStream());
                    $value = new \Neos\Flow\Http\UploadedFile(
                        $tmpname,
                        $value->getSize(),
                        $value->getError(),
                        $value->getClientFilename(),
                        $value->getClientMediaType()
                    );
                }
            }
        };
        $mapFiles($uploadedFiles);
        // @todo check howto handle additional headers
        // @todo check howto support other HTTP methods with bodies
        $post = $psrRequest->getParsedBody() ?: array();
        $class = FlowRequest::class;

        /** @var FlowRequest $flowRequest */
        $flowRequest = new $class($query, $post, $attributes = [], $_COOKIE, $uploadedFiles, $_SERVER, $psrRequest->getBody());
        $flowRequest->setMethod($method);
        return $flowRequest;
    }
    /**
     * Convert Symfony\Component\HttpFoundation\Response to React\Http\Response
     *
     * @param FlowResponse $flowResponse
     * @return ResponseInterface
     */
    protected function mapResponse(FlowResponse $flowResponse)
    {
        // end active session
        if (PHP_SESSION_ACTIVE === session_status()) {
            session_write_close();
            session_unset(); // reset $_SESSION
        }
        $nativeHeaders = [];
        foreach (headers_list() as $header) {
            if (false !== $pos = strpos($header, ':')) {
                $name = substr($header, 0, $pos);
                $value = trim(substr($header, $pos + 1));
                if (isset($nativeHeaders[$name])) {
                    if (!is_array($nativeHeaders[$name])) {
                        $nativeHeaders[$name] = [$nativeHeaders[$name]];
                    }
                    $nativeHeaders[$name][] = $value;
                } else {
                    $nativeHeaders[$name] = $value;
                }
            }
        }
        // after reading all headers we need to reset it, so next request
        // operates on a clean header.
        header_remove();
        $headers = array_merge($nativeHeaders, $flowResponse->getHeaders()->getAll());
        $cookies = [];
        /** @var Cookie $cookie */
        foreach ($flowResponse->getHeaders()->getCookies() as $cookie) {
            $cookieHeader = sprintf('%s=%s', $cookie->getName(), $cookie->getValue());
            if ($cookie->getPath()) {
                $cookieHeader .= '; Path=' . $cookie->getPath();
            }
            if ($cookie->getDomain()) {
                $cookieHeader .= '; Domain=' . $cookie->getDomain();
            }
            if ($cookie->getExpires()) {
                $cookieHeader .= '; Expires=' . gmdate('D, d-M-Y H:i:s', $cookie->getExpires()). ' GMT';
            }
            if ($cookie->isSecure()) {
                $cookieHeader .= '; Secure';
            }
            if ($cookie->isHttpOnly()) {
                $cookieHeader .= '; HttpOnly';
            }
            $cookies[] = $cookieHeader;
        }
        if (isset($headers['Set-Cookie'])) {
            $headers['Set-Cookie'] = array_merge((array)$headers['Set-Cookie'], $cookies);
        } else {
            $headers['Set-Cookie'] = $cookies;
        }

        $psrResponse = new Response($flowResponse->getStatusCode(), $headers);
        ob_start();
        $content = $flowResponse->getContent();
        @ob_end_flush();

        if (!isset($headers['Content-Length'])) {
            $psrResponse = $psrResponse->withAddedHeader('Content-Length', strlen($content));
        }
        $psrResponse = $psrResponse->withBody(stream_for($content));
        foreach ($this->tempFiles as $tmpname) {
            if (file_exists($tmpname)) {
                unlink($tmpname);
            }
        }
        return $psrResponse;
    }

    /**
     * @param $appBootstrap
     * @return string
     * @throws \RuntimeException
     */
    protected function normalizeAppBootstrap($appBootstrap)
    {
        $appBootstrap = str_replace('\\\\', '\\', $appBootstrap);
        $bootstraps = [
            $appBootstrap,
            '\\' . $appBootstrap,
            '\\PHPPM\Bootstraps\\' . ucfirst($appBootstrap)
        ];
        foreach ($bootstraps as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }
    }
}
