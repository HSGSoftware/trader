<?php

declare(strict_types=1);

class DataProvider
{
    private string $binanceBase     = 'https://api.binance.com';
    private string $cryptoPanicBase = 'https://cryptopanic.com/api/v1';
    private string $lunarCrushBase  = 'https://lunarcrush.com/api4/public';
    private string $coinGeckoBase   = 'https://api.coingecko.com/api/v3';

    private array $coinGeckoIds = [
        'BTC'   => 'bitcoin',
        'ETH'   => 'ethereum',
        'BNB'   => 'binancecoin',
        'SOL'   => 'solana',
        'XRP'   => 'ripple',
        'ADA'   => 'cardano',
        'DOT'   => 'polkadot',
        'LINK'  => 'chainlink',
        'AVAX'  => 'avalanche-2',
        'MATIC' => 'matic-network',
        'DOGE'  => 'dogecoin',
        'SHIB'  => 'shiba-inu',
        'LTC'   => 'litecoin',
        'TRX'   => 'tron',
        'ATOM'  => 'cosmos',
        'UNI'   => 'uniswap',
        'TON'   => 'the-open-network',
        'SUI'   => 'sui',
        'APT'   => 'aptos',
    ];

    public function __construct(
        private readonly string $binanceKey,
        private readonly string $binanceSecret,
        private readonly string $cryptoPanicKey,
        private readonly string $lunarCrushKey,
    ) {}

    public function collectAll(string $pair): array
    {
        $symbol     = strtoupper($pair);
        $coin       = $this->extractBaseCoin($symbol);
        $price      = $this->fetchPrice($symbol);
        $obAnalysis = $this->fetchAndAnalyzeOrderBook($symbol);
        $news       = $this->fetchNews($coin);
        $social     = $this->fetchSocial($coin);

        return [
            'pair'         => $symbol,
            'price'        => $price,
            'order_book'   => $obAnalysis,
            'news'         => $news,
            'social'       => $social,
            'collected_at' => date('c'),
        ];
    }

    public function getPrice(string $symbol): array
    {
        return $this->fetchPrice(strtoupper($symbol));
    }

    private function fetchPrice(string $symbol): array
    {
        try {
            $url  = "{$this->binanceBase}/api/v3/ticker/24hr?symbol={$symbol}";
            $data = $this->get($url);
            return [
                'current'      => (float)($data['lastPrice']          ?? 0),
                'open'         => (float)($data['openPrice']          ?? 0),
                'high'         => (float)($data['highPrice']          ?? 0),
                'low'          => (float)($data['lowPrice']           ?? 0),
                'volume'       => (float)($data['volume']             ?? 0),
                'quote_volume' => (float)($data['quoteVolume']        ?? 0),
                'change_pct'   => (float)($data['priceChangePercent'] ?? 0),
                'source'       => 'binance',
            ];
        } catch (RuntimeException) {
            return $this->fetchPriceCoinGecko($symbol);
        }
    }

    private function fetchPriceCoinGecko(string $symbol): array
    {
        $coin   = $this->extractBaseCoin($symbol);
        $coinId = $this->coinGeckoIds[$coin] ?? strtolower($coin);

        $url  = "{$this->coinGeckoBase}/coins/markets"
              . "?vs_currency=usd&ids={$coinId}&order=market_cap_desc"
              . "&per_page=1&page=1&sparkline=false&price_change_percentage=24h";

        $data = $this->get($url, [], true);
        $d    = $data[0] ?? [];

        return [
            'current'      => (float)($d['current_price']             ?? 0),
            'open'         => 0.0,
            'high'         => (float)($d['high_24h']                  ?? 0),
            'low'          => (float)($d['low_24h']                   ?? 0),
            'volume'       => (float)($d['total_volume']              ?? 0),
            'quote_volume' => (float)($d['total_volume']              ?? 0),
            'change_pct'   => (float)($d['price_change_percentage_24h'] ?? 0),
            'source'       => 'coingecko',
        ];
    }

    private function fetchAndAnalyzeOrderBook(string $symbol): array
    {
        try {
            $url = "{$this->binanceBase}/api/v3/depth?symbol={$symbol}&limit=50";
            $raw = $this->get($url);
        } catch (RuntimeException) {
            $raw = ['bids' => [], 'asks' => []];
        }

        return $this->analyzeOrderBook($raw);
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
            $q = (float)$qty;
            $totalBidQty += $q;
            if ($q > $maxBid['qty']) {
                $maxBid = ['price' => (float)$price, 'qty' => $q];
            }
        }

        foreach ($asks as [$price, $qty]) {
            $q = (float)$qty;
            $totalAskQty += $q;
            if ($q > $maxAsk['qty']) {
                $maxAsk = ['price' => (float)$price, 'qty' => $q];
            }
        }

        $total        = $totalBidQty + $totalAskQty;
        $imbalance    = $total > 0 ? round(($totalBidQty - $totalAskQty) / $total * 100, 2) : 0;
        $avgBidQty    = count($bids) > 0 ? $totalBidQty / count($bids) : 0;
        $avgAskQty    = count($asks) > 0 ? $totalAskQty / count($asks) : 0;
        $bidWallRatio = $avgBidQty > 0 ? round($maxBid['qty'] / $avgBidQty, 2) : 0;
        $askWallRatio = $avgAskQty > 0 ? round($maxAsk['qty'] / $avgAskQty, 2) : 0;

        return [
            'total_bid_qty'   => round($totalBidQty, 4),
            'total_ask_qty'   => round($totalAskQty, 4),
            'imbalance_pct'   => $imbalance,
            'max_bid_wall'    => $maxBid,
            'max_ask_wall'    => $maxAsk,
            'bid_wall_ratio'  => $bidWallRatio,
            'ask_wall_ratio'  => $askWallRatio,
            'spoofing_suspect'=> ($bidWallRatio > 10 || $askWallRatio > 10),
            'data_available'  => !empty($bids),
        ];
    }

    private function fetchNews(string $coin): array
    {
        if (empty($this->cryptoPanicKey)) {
            return ['available' => false, 'items' => []];
        }
        try {
            $url   = "{$this->cryptoPanicBase}/posts/?auth_token={$this->cryptoPanicKey}&currencies={$coin}&public=true";
            $data  = $this->get($url);
            $items = [];
            foreach (array_slice($data['results'] ?? [], 0, 5) as $post) {
                $items[] = [
                    'title'          => $post['title']              ?? '',
                    'source'         => $post['source']['title']    ?? '',
                    'votes_positive' => $post['votes']['positive']  ?? 0,
                    'votes_negative' => $post['votes']['negative']  ?? 0,
                    'published'      => $post['published_at']       ?? '',
                ];
            }
            return ['available' => true, 'items' => $items];
        } catch (RuntimeException $e) {
            return ['available' => false, 'items' => [], 'error' => $e->getMessage()];
        }
    }

    private function fetchSocial(string $coin): array
    {
        if (empty($this->lunarCrushKey)) {
            return ['available' => false];
        }
        try {
            $url     = "{$this->lunarCrushBase}/coins/{$coin}/v1";
            $headers = ["Authorization: Bearer {$this->lunarCrushKey}"];
            $data    = $this->get($url, $headers);
            $d       = $data['data'] ?? [];
            return [
                'available'        => true,
                'galaxy_score'     => $d['galaxy_score']      ?? null,
                'alt_rank'         => $d['alt_rank']          ?? null,
                'sentiment'        => $d['sentiment']         ?? null,
                'social_volume'    => $d['social_volume_24h'] ?? null,
                'social_dominance' => $d['social_dominance']  ?? null,
            ];
        } catch (RuntimeException $e) {
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }

    public function extractBaseCoin(string $symbol): string
    {
        foreach (['USDT', 'USDC', 'BUSD', 'USD', 'BTC', 'ETH', 'BNB'] as $quote) {
            if (str_ends_with($symbol, $quote)) {
                return substr($symbol, 0, -strlen($quote));
            }
        }
        return $symbol;
    }

    private function get(string $url, array $headers = [], bool $browserAgent = false): array
    {
        $baseOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
            CURLOPT_FOLLOWLOCATION => true,
        ];
        if ($browserAgent) {
            $baseOpts[CURLOPT_USERAGENT] = 'Mozilla/5.0 (compatible; CryptoBot/1.0)';
        }

        foreach ([true, false] as $verifySsl) {
            $ch = curl_init($url);
            curl_setopt_array($ch, $baseOpts + [CURLOPT_SSL_VERIFYPEER => $verifySsl]);
            $body     = curl_exec($ch);
            $err      = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($err) {
                if ($verifySsl) continue;
                throw new RuntimeException("HTTP isteği başarısız: {$err}");
            }
            break;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Geçersiz JSON yanıtı (HTTP {$httpCode})");
        }

        if (isset($decoded['code']) && $decoded['code'] === 0 && isset($decoded['msg'])) {
            throw new RuntimeException("Binance erişim engeli: " . $decoded['msg']);
        }

        return $decoded;
    }
}
