<?php

namespace App\Service;

use Symfony\Component\DomCrawler\Crawler;

final class GdsParser
{
    public function parse(string $html, string $stationId): array
    {
        $crawler = new Crawler($html);
        $crawler->filter('script,style')->each(function (Crawler $n) {
            $node = $n->getNode(0);
            if ($node && $node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        });

        $bodyText = trim(preg_replace('/\s+/', ' ', $crawler->filter('body')->text('')));

        $stopName = null;
        if (preg_match('/Nome\s+Fermata:\s*([^0-9]{2,}?)\s*(?:LINEA|$)/iu', $bodyText, $m)) {
            $stopName = trim($m[1]);
        }

        $pageStationId = $stationId;
        if (preg_match('/N\.\s*Fermata:\s*([A-Z0-9]+)/iu', $bodyText, $m)) {
            $pageStationId = $m[1];
        }

        $updatedAt = null;
        if (preg_match('/(\d{2})\/(\d{2})\/(\d{2})\s+(\d{2}):(\d{2})/', $bodyText, $m)) {
            $y = (int)$m[3];
            $y += $y >= 70 ? 1900 : 2000;
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', sprintf('%04d-%02d-%02d %02d:%02d', $y, $m[2], $m[1], $m[4], $m[5]), new \DateTimeZone('Europe/Rome'));
            if ($dt) {
                $updatedAt = $dt->format(\DateTimeInterface::ATOM);
            }
        }

        $notices = [];
        if (preg_match('/ATTENZIONE[^\.!]*[\.!]?/iu', $bodyText, $m)) {
            $notices[] = trim($m[0]);
        }
        $notices = array_values(array_unique(array_filter($notices)));

        $entries = [];
        $crawler->filter('table')->each(function (Crawler $table) use (&$entries) {
            $table->filter('tr')->each(function (Crawler $tr) use (&$entries) {
                $tds = $tr->filter('td');
                if ($tds->count() < 3) {
                    return;
                }
                $c0 = trim(preg_replace('/\s+/', ' ', $tds->eq(0)->text('')));
                $c1raw = $tds->eq(1);
                $c1 = trim(preg_replace('/\s+/', ' ', $c1raw->text('')));
                $c2 = trim(preg_replace('/\s+/', ' ', $tds->eq(2)->text('')));

                if ($c0 === '' || preg_match('/^(LINEA|N\.\s*Fermata)/iu', $c0)) {
                    return;
                }
                if (!preg_match('/^\d{1,2}:\d{2}$/', $c1)) {
                    return;
                }

                $flags = $this->detectFlags($c1raw);

                $entries[] = [
                    'line' => $c0,
                    'time' => $c1,
                    'realtime' => $flags['realtime'],
                    'scheduled' => $flags['scheduled'],
                    'departure' => $flags['departure'],
                    'route' => $c2,
                ];
            });
        });

        if (!$entries) {
            $entries = $this->fallbackParse($crawler);
        }

        return [
            'stop_id' => $pageStationId,
            'stop_name' => $stopName,
            'updated_at' => $updatedAt,
            'notices' => $notices,
            'entries' => $entries,
        ];
    }

    private function detectFlags(Crawler $timeCell): array
    {
        $icons = $timeCell->filter('img')->each(function (Crawler $i) {
            $alt = strtolower(trim($i->attr('alt') ?? ''));
            $title = strtolower(trim($i->attr('title') ?? ''));
            $src = strtolower(trim($i->attr('src') ?? ''));
            return $alt . ' ' . $title . ' ' . $src;
        });
        $blob = implode(' ', $icons);
        $realtime = preg_match('/tempo\s*reale|realtime|live|ritardo/iu', $blob) === 1;
        $scheduled = preg_match('/orario\s*programmato|programmato|scheduled/iu', $blob) === 1 || (!$realtime && $blob !== '');
        $departure = preg_match('/partenza|start/iu', $blob) === 1;
        return ['realtime' => $realtime, 'scheduled' => $scheduled ?: null, 'departure' => $departure];
    }

    private function fallbackParse(Crawler $crawler): array
    {
        $html = strip_tags($crawler->html(''));
        $parts = preg_split('/LINEA\s+ORARIO\s+PERCORSO/iu', $html);
        $block = $parts[1] ?? '';
        $tokens = preg_split('/\s{2,}/', trim(preg_replace('/\s+/', ' ', $block)));
        $out = [];
        for ($i = 0; $i + 2 < count($tokens); $i += 3) {
            $line = trim($tokens[$i]);
            $time = trim($tokens[$i + 1]);
            $route = trim($tokens[$i + 2]);
            if ($line !== '' && preg_match('/^\d{1,2}:\d{2}$/', $time)) {
                $out[] = [
                    'line' => $line,
                    'time' => $time,
                    'realtime' => null,
                    'scheduled' => null,
                    'departure' => null,
                    'route' => $route,
                ];
            }
        }
        return $out;
    }
}
