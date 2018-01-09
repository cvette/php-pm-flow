<?php
namespace Vette\PHPPM\Flow;

use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Http\Component\ComponentContext;
use Neos\Flow\Http\Request;
use Neos\Flow\Http\RequestHandler;
use Neos\Flow\Http\Response;
use Neos\Flow\Http\Uri;

class ExternalRequestHandler extends RequestHandler
{
    /**
     * @var Request
     */
    protected $request;

    /**
     * @param Request $request
     */
    public function setRequest(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Handle Request
     */
    public function handleRequest()
    {
        $response = new Response();
        $this->componentContext = new ComponentContext($this->request, $response);

        $this->boot();
        $this->resolveDependencies();
        $this->addPoweredByHeader($response);
        if (isset($this->settings['http']['baseUri'])) {
            $this->request->setBaseUri(new Uri($this->settings['http']['baseUri']));
        }

        $this->baseComponentChain->handle($this->componentContext);
        $this->baseComponentChain->getResponse();

        $this->bootstrap->shutdown(Bootstrap::RUNLEVEL_RUNTIME);
        $this->exit->__invoke();
    }

    /**
     * @return bool
     */
    public function canHandleRequest()
    {
        return true;
    }

    /**
     * @return int
     */
    public function getPriority()
    {
        return 100;
    }
}
