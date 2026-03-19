<?php

declare(strict_types=1);

class DecisionEngine
{
    private string $apiBase = 'https://api.anthropic.com/v1/messages';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'claude-sonnet-4-5',
        private readonly int $weightTechnical    = 35,
        private readonly int $weightSocial       = 20,
        private readonly int $weightNews         = 20,
        private readonly int $weightManipulation = 25,
    ) {}

    public function analyze(array $marketData, array $scanContext = []): array
    {
        $prompt = $this->buildPrompt($marketData, $scanContext);
        $raw    = $this->callClaude($prompt);
        $result = $this->parseResponse($raw);
        $result['raw_prompt']   = $prompt;
        $result['raw_response'] = $raw;
        return $result;
    }

    private function buildPrompt(array $d, array $scanContext): string
    {
        $pair  = $d['pair'];
        $price = $d['price'];
        $ob    = $d['order_book'];
        $news  = $d['news'];
        $soc   = $d['social'];

        $newsText = 'API anahtarı yok veya haber çekilemedi.';
        if (!empty($news['items'])) {
            $lines = [];
            foreach ($news['items'] as $n) {
                $lines[] = "- [{$n['source']}] {$n['title']} (+{$n['votes_positive']}/-{$n['votes_negative']})";
            }
            $newsText = implode("\n", $lines);
        }

        $socialText = 'API anahtarı yok veya sosyal veri çekilemedi.';
        if (!empty($soc['available'])) {
            $socialText = "Galaxy Score: {$soc['galaxy_score']}, AltRank: {$soc['alt_rank']}, "
                        . "Sentiment: {$soc['sentiment']}, Social Volume 24h: {$soc['social_volume']}";
        }

        $weights = "Teknik: {$this->weightTechnical}%, Sosyal: {$this->weightSocial}%, "
                 . "Haber: {$this->weightNews}%, Manipülasyon/P&D: {$this->weightManipulation}%";

        // Tarama bağlamı
        $scanText = '';
        if (!empty($scanContext)) {
            $scanText = "\n=== TÜM PARİTE TARAMA SONUÇLARI ===\n";
            foreach ($scanContext as $sym => $sd) {
                $scanText .= sprintf(
                    "%s: Fiyat=$%s 1s=%+.2f%% 24s=%+.2f%% Hacim=$%s PumpSkor=%d OpSkor=%d\n",
                    $sym,
                    number_format($sd['price'], 2),
                    $sd['change_1h'],
                    $sd['change_24h'],
                    number_format($sd['volume_24h']),
                    $sd['pump_score'],
                    $sd['opportunity_score']
                );
            }
        }

        $pumpScore        = $scanContext[$pair]['pump_score']        ?? 'N/A';
        $opportunityScore = $scanContext[$pair]['opportunity_score'] ?? 'N/A';
        $change1h         = $scanContext[$pair]['change_1h']         ?? ($price['change_pct'] ?? 0);
        $priceSource      = $price['source'] ?? 'unknown';

        return <<<PROMPT
Analiz edilecek parite: {$pair}
Zaman: {$d['collected_at']}
Veri kaynağı: {$priceSource}

=== TEKNİK VERİLER ===
Güncel Fiyat: {$price['current']}
24s Değişim: %{$price['change_pct']}
1 Saatlik Değişim: %{$change1h}
24s Yüksek: {$price['high']} | 24s Düşük: {$price['low']}
Hacim (coin): {$price['volume']} | Hacim (USD): {$price['quote_volume']}

=== PUMP & DUMP ANALİZİ ===
PumpSkor (0-100): {$pumpScore}
FırsatSkoru (0-100): {$opportunityScore}

=== EMİR DEFTERİ ANALİZİ ===
Toplam Alış Miktarı: {$ob['total_bid_qty']}
Toplam Satış Miktarı: {$ob['total_ask_qty']}
Dengesizlik: %{$ob['imbalance_pct']}
En Büyük Alış Duvarı: {$ob['max_bid_wall']['qty']} adet @ {$ob['max_bid_wall']['price']} ({$ob['bid_wall_ratio']}x)
En Büyük Satış Duvarı: {$ob['max_ask_wall']['qty']} adet @ {$ob['max_ask_wall']['price']} ({$ob['ask_wall_ratio']}x)
Spoofing Şüphesi: {$this->boolText($ob['spoofing_suspect'])}
Order Book Verisi: {$this->boolText($ob['data_available'])}

=== HABERLER (CryptoPanic) ===
{$newsText}

=== SOSYAL MEDYA (LunarCrush) ===
{$socialText}
{$scanText}
=== KARAR AĞIRLIKLARI ===
{$weights}

Yukarıdaki verileri analiz et. Yalnızca geçerli bir JSON nesnesi döndür:
{
  "decision": "BUY" | "SELL" | "HOLD",
  "confidence": 0-100,
  "manipulation_risk": 0-100,
  "pump_and_dump_risk": 0-100,
  "reason": "Kısa teknik, P&D ve manipülasyon analizi (max 150 kelime)"
}
PROMPT;
    }

    private function callClaude(string $prompt): string
    {
        $systemPrompt = 'Sen rasyonel ve şüpheci bir kripto analiz uzmanısın. '
            . 'Görevlerin: (1) Emir defterindeki büyük duvarların "spoofing" olup olmadığını tespit et. '
            . '(2) Haberlerin ve sosyal medyanın "pump-and-dump" amacı taşıyıp taşımadığını analiz et. '
            . '(3) Pump sinyali varsa ve henüz erken aşamadaysa kısa vadeli BUY fırsatı olabilir — '
            . 'bunu göz ardı etme, sıkı bir stop-loss ile fırsatı değerlendir. '
            . '(4) Dump aşamasındaysa veya tebik edilmişse SELL veya HOLD ver. '
            . 'Kararını YALNIZCA geçerli JSON nesnesi olarak döndür. Başka metin yazma.';

        $body = json_encode([
            'model'      => $this->model,
            'max_tokens' => 600,
            'system'     => $systemPrompt,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        $ch = curl_init($this->apiBase);
        foreach ([true, false] as $ssl) {
            $ch = curl_init($this->apiBase);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_TIMEOUT        => 45,
                CURLOPT_SSL_VERIFYPEER => $ssl,
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
            if (!$err) break;
            if (!$ssl) throw new RuntimeException("Anthropic API bağlantı hatası: {$err}");
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
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start !== false && $end !== false) {
            $text = substr($text, $start, $end - $start + 1);
        }

        $data = json_decode($text, true);
        if (!is_array($data)) {
            return [
                'decision'          => 'HOLD',
                'confidence'        => 0,
                'manipulation_risk' => 50,
                'pump_and_dump_risk'=> 50,
                'reason'            => 'AI yanıtı parse edilemedi: ' . substr($raw, 0, 200),
            ];
        }

        return [
            'decision'           => in_array($data['decision'] ?? '', ['BUY', 'SELL', 'HOLD'])
                                     ? $data['decision'] : 'HOLD',
            'confidence'         => (int)($data['confidence']         ?? 0),
            'manipulation_risk'  => (int)($data['manipulation_risk']  ?? 0),
            'pump_and_dump_risk' => (int)($data['pump_and_dump_risk'] ?? 0),
            'reason'             => (string)($data['reason']          ?? ''),
        ];
    }

    private function boolText(bool $v): string
    {
        return $v ? 'EVET' : 'Hayır';
    }
}
