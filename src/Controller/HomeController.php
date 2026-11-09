<?php
declare(strict_types=1);

namespace IptvService\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController
{
    #[Route('/', name: 'nsatellite_home', methods: ['GET'])]
    public function index(): RedirectResponse
    {
        return new RedirectResponse('/home.html', 302);
    }
}
