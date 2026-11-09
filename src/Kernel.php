<?php
declare(strict_types=1);
namespace IptvService;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
final class Kernel extends BaseKernel {
    public function registerContainerConfiguration(\Symfony\Component\Config\Loader\LoaderInterface $loader): void
    {
        $loader->load(dirname(__DIR__).'/config/services.yaml');
        $loader->load(dirname(__DIR__).'/config/packages/framework.yaml');
    }

    public function registerBundles(): iterable { return [new \Symfony\Bundle\FrameworkBundle\FrameworkBundle()]; }
    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void {
        $container->setParameter('kernel.secret', $_ENV['APP_SECRET'] ?? getenv('APP_SECRET') ?: 'local-change-me');
        $loader->load(dirname(__DIR__).'/config/services.yaml');
        $loader->load(dirname(__DIR__).'/config/packages/framework.yaml');
    }
    protected function configureRoutes(RoutingConfigurator $routes): void {
        $routes->import('../src/Controller/', 'attribute');
    }
}
