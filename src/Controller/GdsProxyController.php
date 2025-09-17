<?php

namespace App\Controller;

use App\Service\GdsParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class GdsProxyController extends AbstractController
{
    #[Route('/gds-proxy', name: 'gds_proxy', methods: ['GET'])]
    public function __invoke(Request $request, GdsParser $parser): JsonResponse
    {
        $id = $request->query->getString('id');
        if (!$id) {
            return $this->json(['error' => 'missing id'], 400);
        }

        $url = sprintf('https://dms.gds.com/BUSITALIA/BUSITALIA.aspx?stationID=%s', urlencode($id));
        $client = HttpClient::create([
            'timeout' => 8,
            'headers' => [
                'User-Agent' => 'StopDisplay/1.0',
                'Accept' => 'text/html,application/xhtml+xml',
            ],
        ]);

        try {
            $resp = $client->request('GET', $url);
            if (200 !== $resp->getStatusCode()) {
                return $this->json(['error' => 'upstream_error', 'status' => $resp->getStatusCode()], 502);
            }
            $html = $resp->getContent();
        } catch (\Throwable $e) {
            return $this->json(['error' => 'fetch_failed'], 502);
        }

        $data = $parser->parse($html, $id);

        $response = $this->json($data);
        $response->setPublic();
        $response->setMaxAge(20);
        $response->headers->set('Access-Control-Allow-Origin', '*');

        return $response;
    }
}
