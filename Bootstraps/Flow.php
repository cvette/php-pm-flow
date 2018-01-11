<?php
namespace PHPPM\Bootstraps;

use Neos\Flow\Core\Booting\Scripts;
use Neos\Flow\Core\Bootstrap as FlowBootstrap;
use PHPPM\Flow\ExternalRequestHandler;

class Flow implements ApplicationEnvironmentAwareInterface
{

    /**
     * @var string|null The application environment
     */
    protected $appenv;

    /**
     * @var boolean
     */
    protected $debug;


    /**
     * @param $appenv
     * @param $debug
     */
    public function initialize($appenv, $debug)
    {
        $this->appenv = $appenv;
        $this->debug = $debug;
    }

    /**
     * @return FlowBootstrap
     */
    public function getApplication()
    {
        require('./Packages/Framework/Neos.Flow/Classes/Core/Bootstrap.php');

        $context = $this->appenv ?: 'Development';

        $app = new FlowBootstrap($context);
        Scripts::initializeClassLoader($app);
        Scripts::initializeSignalSlot($app);
        Scripts::initializePackageManagement($app);

        $app->setActiveRequestHandler(new ExternalRequestHandler($app));

        return $app;
    }
}
