<?php

declare(strict_types=1);

class DataProvider
{
    private string $binanceBase    = 'https://api.binance.com';
    private string $cryptoPanicBase = 'https://cryptopanic.com/api/v1';
    private string $lunarCrushBase  = 'https://lunarcrush.com/api4/public';

    public function __construct(
        private readonly string $binanceKey,
        private readonly string $binanceSecret,
        private readonly string $cryptoPanicKey,
        private readonly string $lunarCrushKey,
    ) {}

    public function collectAll(string $pair): array
    {
        $symbol  = strtoupper($pair);
        $coin    = $this->extractBaseCoin($symbol);

        $price     = $this->fetchPrice($symbol);
        $orderBook = $this->fetchOrderBook($symbol);
        $obAnalysis = $this->analyzeOrderBook($orderBook);
        $news      = $this->fetchNews($coin);
        $social    = $this->fetchSocial($coin);

        return [
            'pair'          => $symbol,
            'price'         => $price,
            'order_book'    => $obAnalysis,
            'news'          => $news,
            'social'        => $social,
            'collected_at'  => date('c'),
        ];
    }

    private function fetchPrice(string $symbol): array
    {
        $url  = "{$this->binanceBase}/api/v3/ticker/24hr?symbol={$symbol}";
        $data = $this->get($url);

        return [
            'current'       => (float)($data['lastPrice']    ?? 0),
            'open'          => (float)($data['openPrice']    ?? 0),
            'high'          => (float)($data['highPrice']    ?? 0),
            'low'           => (float)($data['lowPrice']     ?? 0),
            'volume'        => (float)($data['volume']       ?? 0),
            'quote_volume'  => (float)($data['quoteVolume']  ?? 0),
            'change_pct'    => (float)($data['priceChangePercent'] ?? 0),
        ];
    }

    private function fetchOrderBook(string $symbol): array
    {
        $url  = "{$this->binanceBase}/api/v3/depth?symbol={$symbol}&limit=50";
        return $this->get($url);
    }

    private function analyzeOrderBook(array $raw): array
    {
        $bids = $raw['bids'] ?? [];
        $asks = $raw['asks'] ?? [];

        $totalBidQty = 0.0;
        $totalAskQty = 0.0;
        $maxBid      = ['price' => 0, 'qty' => 0];
        $maxAsk      = ['price' => 0, 'qty' => 0];

        foreach ($bids as [$price, $qty]) {
            $p = (float)$price;
            $q = (float)$qty;
            $totalBidQty += $q;
            if ($q > $maxBid['qty']) {
                $maxBid = ['price' => $p, 'qty' => $q];
            }
        }

        foreach ($asks as [$price, $qty]) {
            $p = (float)$price;
            $q = (float)$qty;
            $totalAskQty += $q;
            if ($q > $maxAsk['qty']) {
                $maxAsk = ['price' => $p, 'qty' => $q];
            }
        }

        $total      = $totalBidQty + $totalAskQty;
        $imbalance  = $total > 0 ? round(($totalBidQty - $totalAskQty) / $total * 100, 2) : 0;

        $avgBidQty  = count($bids) > 0 ? $totalBidQty / count($bids) : 0;
        $avgAskQty  = count($asks) > 0 ? $totalAskQty / count($asks) : 0;

        $bidWallRatio = $avgBidQty > 0 ? round($maxBid['qty'] / $avgBidQty, 2) : 0;
        $askWallRatio = $avgAskQty > 0 ? round($maxAsk['qty'] / $avgAskQty, 2) : 0;

        return [
            'total_bid_qty'    => round($totalBidQty, 4),
            'total_ask_qty'    => round($totalAskQty, 4),
            'imbalance_pct'    => $imbalance,
            'max_bid_wall'     => $maxBid,
            'max_ask_wall'     => $maxAsk,
            'bid_wall_ratio'   => $bidWallRatio,
            'ask_wall_ratio'   => $askWallRatio,
            'spoofing_suspect' => ($bidWallRatio > 10 || $askWallRatio > 10),
        ];
    }

    private function fetchNews(string $coin): array
    {
        if (empty($this->cryptoPanicKey)) {
            return ['available' => false, 'items' => []];
        }

        $url  = "{$this->cryptoPanicBase}/posts/?auth_token={$this->cryptoPanicKey}&currencies={$coin}&public=true";
        $data = $this->get($url);

        $items = [];
        foreach (array_slice($data['results'] ?? [], 0, 5) as $post) {
            $items[] = [
                'title'      => $post['title']              ?? '',
                'source'     => $post['source']['title']    ?? '',
                'votes_positive' => $post['votes']['positive'] ?? 0,
                'votes_negative' => $post['votes']['negative'] ?? 0,
                'published'  => $post['published_at']       ?? '',
            ];
        }

        return ['available' => true, 'items' => $items];
    }

    private function fetchSocial(string $coin): array
    {
        if (empty($this->lunarCrushKey)) {
            return ['available' => false];
        }

        $url  = "{$this->lunarCrushBase}/coins/{$coin}/v1";
        $headers = ["Authorization: Bearer {$this->lunarCrushKey}"];
        $data = $this->get($url, $headers);

        $d = $data['data'] ?? [];

        return [
            'available'          => true,
            'galaxy_score'       => $d['galaxy_score']       ?? null,
            'alt_rank'           => $d['alt_rank']           ?? null,
            'sentiment'          => $d['sentiment']          ?? null,
            'social_volume'      => $d['social_volume_24h']  ?? null,
            'social_dominance'   => $d['social_dominance']   ?? null,
        ];
    }

    private function extractBaseCoin(string $symbol): string
    {
        $stablecoins = ['USDT', 'USDC', 'BUSD', 'USD', 'BTC', 'ETH', 'BNB'];
        foreach ($stablecoins as $quote) {
            if (str_ends_with($symbol, $quote)) {
                return substr($symbol, 0, -strlen($quote));
            }
        }
        return $symbol;
    }

    private function get(string $url, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body     = curl_exec($ch);
        $err      = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("HTTP isteği başarısız: {$err} — URL: {$url}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Geçersiz JSON yanıtı (HTTP {$httpCode}): " . substr($url, 0, 60));
        }

        // Binance geo-kısıtlama kontrolü
        if (isset($decoded['code']) && $decoded['code'] === 0 && isset($decoded['msg'])) {
            throw new RuntimeException("Binance API erişim hatası: " . $decoded['msg']);
        }

        return $decoded;
    }
}
