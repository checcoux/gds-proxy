<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

final class RootController extends AbstractController
{
    #[Route('/', name: 'root_info', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $root = $this->getParameter('kernel.project_dir') ?: \dirname(__DIR__, 2);
        $name = 'app';
        $version = null;
        $file = $root.'/composer.json';
        if (is_file($file)) {
            $data = json_decode((string)file_get_contents($file), true);
            if (is_array($data)) {
                $name = $data['name'] ?? $name;
                $version = $data['version'] ?? $version;
                if (!$version && isset($data['extra']['app-version'])) {
                    $version = $data['extra']['app-version'];
                }
            }
        }
        return $this->json([
            'name' => $name,
            'version' => $version,
        ]);
    }
}
