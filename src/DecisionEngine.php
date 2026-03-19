<?php

declare(strict_types=1);

class DecisionEngine
{
    private string $apiBase = 'https://api.anthropic.com/v1/messages';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'claude-sonnet-4-5',
        private readonly int $weightTechnical    = 40,
        private readonly int $weightSocial       = 20,
        private readonly int $weightNews         = 20,
        private readonly int $weightManipulation = 20,
    ) {}

    public function analyze(array $marketData): array
    {
        $prompt = $this->buildPrompt($marketData);
        $raw    = $this->callClaude($prompt);
        $result = $this->parseResponse($raw);

        $result['raw_prompt']   = $prompt;
        $result['raw_response'] = $raw;

        return $result;
    }

    private function buildPrompt(array $d): string
    {
        $pair  = $d['pair'];
        $price = $d['price'];
        $ob    = $d['order_book'];
        $news  = $d['news'];
        $soc   = $d['social'];

        $newsText = 'API anahtarı yok veya veri çekilemedi.';
        if (!empty($news['items'])) {
            $lines = [];
            foreach ($news['items'] as $n) {
                $lines[] = "- [{$n['source']}] {$n['title']} (+{$n['votes_positive']}/-{$n['votes_negative']})";
            }
            $newsText = implode("\n", $lines);
        }

        $socialText = 'API anahtarı yok veya veri çekilemedi.';
        if (!empty($soc['available'])) {
            $socialText = "Galaxy Score: {$soc['galaxy_score']}, AltRank: {$soc['alt_rank']}, "
                        . "Sentiment: {$soc['sentiment']}, Social Volume 24h: {$soc['social_volume']}";
        }

        $weights = "Teknik: {$this->weightTechnical}%, Sosyal: {$this->weightSocial}%, "
                 . "Haber: {$this->weightNews}%, Manipülasyon: {$this->weightManipulation}%";

        return <<<PROMPT
Parite: {$pair}
Zaman: {$d['collected_at']}

=== TEKNİK VERİLER ===
Güncel Fiyat: {$price['current']}
24s Değişim: %{$price['change_pct']}
24s Yüksek: {$price['high']}  |  Düşük: {$price['low']}
Hacim (coin): {$price['volume']}  |  Hacim (USDT): {$price['quote_volume']}

=== EMİR DEFTERİ ANALİZİ ===
Toplam Alış Miktarı: {$ob['total_bid_qty']}
Toplam Satış Miktarı: {$ob['total_ask_qty']}
Dengesizlik (Alış - Satış): %{$ob['imbalance_pct']}
En Büyük Alış Duvarı: {$ob['max_bid_wall']['qty']} adet @ {$ob['max_bid_wall']['price']} (ortalamaya oranı: {$ob['bid_wall_ratio']}x)
En Büyük Satış Duvarı: {$ob['max_ask_wall']['qty']} adet @ {$ob['max_ask_wall']['price']} (ortalamaya oranı: {$ob['ask_wall_ratio']}x)
Spoofing Şüphesi: {$this->boolText($ob['spoofing_suspect'])}

=== HABERLER (CryptoPanic) ===
{$newsText}

=== SOSYAL MEDYA (LunarCrush) ===
{$socialText}

=== KARAR AĞIRLIKLARI ===
{$weights}

Yukarıdaki verileri analiz et. Sadece aşağıdaki JSON formatında yanıt ver, başka hiçbir şey yazma:
{
  "decision": "BUY" | "SELL" | "HOLD",
  "confidence": 0-100,
  "manipulation_risk": 0-100,
  "reason": "Kısa teknik ve manipülasyon analizi özeti (max 200 kelime)"
}
PROMPT;
    }

    private function callClaude(string $prompt): string
    {
        $systemPrompt = 'Sen rasyonel ve şüpheci bir kripto analiz uzmanısın. '
            . 'Sana sunulan emir defterindeki büyük duvarların "spoofing" (sahte emir) olup olmadığını, '
            . 'haberlerin "pump-and-dump" amacı taşıyıp taşımadığını analiz et. '
            . 'Kararını YALNIZCA geçerli bir JSON nesnesi olarak döndür. '
            . 'JSON dışında hiçbir metin, açıklama veya markdown kod bloğu kullanma.';

        $body = json_encode([
            'model'      => $this->model,
            'max_tokens' => 512,
            'system'     => $systemPrompt,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        $ch = curl_init($this->apiBase);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
        ]);

        $response = curl_exec($ch);
        $err      = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("Anthropic API curl hatası: {$err}");
        }

        $decoded = json_decode($response, true);

        if ($httpCode !== 200) {
            $msg = $decoded['error']['message'] ?? $response;
            throw new RuntimeException("Anthropic API hatası ({$httpCode}): {$msg}");
        }

        return $decoded['content'][0]['text'] ?? '';
    }

    private function parseResponse(string $raw): array
    {
        $text = trim($raw);

        // JSON kod bloğu içinde gelirse temizle
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        // Süslü parantezler arasını bul
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start !== false && $end !== false) {
            $text = substr($text, $start, $end - $start + 1);
        }

        $data = json_decode($text, true);

        if (!is_array($data)) {
            return [
                'decision'         => 'HOLD',
                'confidence'       => 0,
                'manipulation_risk' => 50,
                'reason'           => 'AI yanıtı parse edilemedi: ' . substr($raw, 0, 200),
            ];
        }

        return [
            'decision'          => in_array($data['decision'] ?? '', ['BUY', 'SELL', 'HOLD'])
                                    ? $data['decision'] : 'HOLD',
            'confidence'        => (int)($data['confidence']       ?? 0),
            'manipulation_risk' => (int)($data['manipulation_risk'] ?? 0),
            'reason'            => (string)($data['reason']         ?? ''),
        ];
    }

    private function boolText(bool $v): string
    {
        return $v ? 'EVET' : 'Hayır';
    }
}
