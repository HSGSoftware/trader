<?php

declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/DataProvider.php';
require_once __DIR__ . '/src/DecisionEngine.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'run';

try {
    match ($action) {
        'run'           => handleRun(),
        'status'        => handleStatus(),
        'get_price'     => handleGetPrice(),
        'scan_pairs'    => handleScanPairs(),
        'close_trade'   => handleCloseTrade(),
        'save_settings' => handleSaveSettings(),
        'get_logs'      => handleGetLogs(),
        'get_trades'    => handleGetTrades(),
        'test_api'      => handleTestApi(),
        'ping'          => handlePing(),
        default         => throw new InvalidArgumentException("Bilinmeyen action: {$action}"),
    };
} catch (Throwable $e) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

ob_end_flush();

// ─── Ana Bot Döngüsü ────────────────────────────────────────────────────────

function handleRun(): void
{
    $db = Database::getInstance();

    $anthropicKey    = Database::getSetting('anthropic_api_key',    '');
    $binanceKey      = Database::getSetting('binance_api_key',      '');
    $binanceSecret   = Database::getSetting('binance_api_secret',   '');
    $cpKey           = Database::getSetting('cryptopanic_api_key',  '');
    $lcKey           = Database::getSetting('lunarcrush_api_key',   '');
    $availablePairs  = array_map('trim', explode(',', Database::getSetting('available_pairs', 'BTCUSDT,ETHUSDT,SOLUSDT')));
    $model           = Database::getSetting('ai_model',             'claude-sonnet-4-5');
    $tpPct           = (float)Database::getSetting('take_profit_pct',      5);
    $slPct           = (float)Database::getSetting('stop_loss_pct',        3);
    $tradeSizePct    = (float)Database::getSetting('trade_size_pct',       20);
    $wTech           = (int)Database::getSetting('weight_technical',       35);
    $wSocial         = (int)Database::getSetting('weight_social',          20);
    $wNews           = (int)Database::getSetting('weight_news',            20);
    $wManip          = (int)Database::getSetting('weight_manipulation',    25);
    $maxConcurrent   = (int)Database::getSetting('max_concurrent_trades',  3);
    $minOpScore      = (int)Database::getSetting('min_opportunity_score',  25);
    $autoSelect      = (bool)(int)Database::getSetting('auto_pair_select', 1);

    if (empty($anthropicKey)) {
        echo json_encode(['success' => false, 'error' => 'Anthropic API anahtarı girilmemiş.']);
        return;
    }

    $provider = new DataProvider($binanceKey, $binanceSecret, $cpKey, $lcKey);

    // 1. Tüm pariteleri tara
    $scanData = $provider->scanPairs($availablePairs);

    // 2. Açık işlemleri TP/SL kontrol et (tüm pariteler)
    $openTrades = getOpenTradesAll($db);
    foreach ($openTrades as $trade) {
        $currentPrice = $scanData[$trade['pair']]['price'] ?? null;
        if ($currentPrice && $currentPrice > 0) {
            checkAndCloseTrade($db, $trade, $currentPrice, $tpPct, $slPct);
        }
    }

    // 3. Açık işlem sayısını yeniden say
    $openCount = countOpenTrades($db);

    // 4. Analiz edilecek pariteleri seç
    $pairsToAnalyze = selectPairsForAnalysis(
        $scanData,
        $db,
        $maxConcurrent,
        $openCount,
        $minOpScore,
        $autoSelect,
        $availablePairs
    );

    if (empty($pairsToAnalyze)) {
        echo json_encode([
            'success'    => true,
            'message'    => 'Yeterli fırsat puanı olan parite bulunamadı veya maksimum açık işlem sayısına ulaşıldı.',
            'scan'       => array_values($scanData),
            'analyses'   => [],
            'balance'    => (float)Database::getSetting('virtual_balance', 10000),
            'open_count' => $openCount,
        ]);
        return;
    }

    // 5. Her seçilen parite için tam analiz + AI kararı
    $engine   = new DecisionEngine($anthropicKey, $model, $wTech, $wSocial, $wNews, $wManip);
    $analyses = [];

    foreach ($pairsToAnalyze as $pair) {
        $market       = $provider->collectAll($pair);
        $currentPrice = $market['price']['current'];

        if ($currentPrice <= 0) {
            $analyses[] = ['pair' => $pair, 'error' => 'Fiyat alınamadı'];
            continue;
        }

        $decision = $engine->analyze($market, $scanData);

        // Log kaydet
        $stmt = $db->prepare(
            'INSERT INTO logs (pair, decision, confidence, manipulation_risk, reason, raw_data)
             VALUES (:pair, :dec, :conf, :manip, :reason, :raw)'
        );
        $stmt->execute([
            ':pair'   => $pair,
            ':dec'    => $decision['decision'],
            ':conf'   => $decision['confidence'],
            ':manip'  => $decision['manipulation_risk'],
            ':reason' => $decision['reason'],
            ':raw'    => json_encode($market),
        ]);

        // Trade işlemi
        $tradeResult = null;
        $balance     = (float)Database::getSetting('virtual_balance', 10000);

        if ($decision['decision'] === 'BUY') {
            $openTrade = getOpenTrade($db, $pair);
            if (!$openTrade && $openCount < $maxConcurrent) {
                $amount = ($balance * $tradeSizePct / 100) / $currentPrice;
                $spent  = $amount * $currentPrice;
                if ($balance >= $spent && $amount > 0) {
                    $ins = $db->prepare(
                        'INSERT INTO trades (pair, type, entry_price, quantity, manipulation_risk_score, ai_reason)
                         VALUES (:pair, :type, :price, :qty, :manip, :reason)'
                    );
                    $ins->execute([
                        ':pair'   => $pair,
                        ':type'   => 'BUY',
                        ':price'  => $currentPrice,
                        ':qty'    => $amount,
                        ':manip'  => $decision['manipulation_risk'],
                        ':reason' => $decision['reason'],
                    ]);
                    Database::setSetting('virtual_balance', $balance - $spent);
                    $openCount++;
                    $tradeResult = ['action' => 'BUY', 'price' => $currentPrice, 'qty' => $amount, 'spent' => $spent];
                }
            }
        } elseif ($decision['decision'] === 'SELL') {
            $openTrade = getOpenTrade($db, $pair);
            if ($openTrade) {
                $tradeResult = closeTrade($db, $openTrade, $currentPrice, 'AI SELL kararı');
                $openCount   = max(0, $openCount - 1);
            }
        }

        $analyses[] = [
            'pair'               => $pair,
            'price'              => $currentPrice,
            'price_source'       => $market['price']['source'] ?? '?',
            'change_pct'         => $market['price']['change_pct'] ?? 0,
            'pump_score'         => $scanData[$pair]['pump_score']        ?? 0,
            'opportunity_score'  => $scanData[$pair]['opportunity_score'] ?? 0,
            'decision'           => $decision['decision'],
            'confidence'         => $decision['confidence'],
            'manipulation_risk'  => $decision['manipulation_risk'],
            'pump_and_dump_risk' => $decision['pump_and_dump_risk'] ?? 0,
            'reason'             => $decision['reason'],
            'trade'              => $tradeResult,
            'order_book'         => $market['order_book'],
        ];
    }

    echo json_encode([
        'success'    => true,
        'scan'       => array_values($scanData),
        'analyses'   => $analyses,
        'balance'    => (float)Database::getSetting('virtual_balance', 10000),
        'open_count' => countOpenTrades($db),
    ]);
}

// ─── Parite Tarama (sadece tarama, AI yok) ──────────────────────────────────

function handleScanPairs(): void
{
    $pairs    = array_map('trim', explode(',', Database::getSetting('available_pairs', 'BTCUSDT')));
    $binKey   = Database::getSetting('binance_api_key', '');
    $binSec   = Database::getSetting('binance_api_secret', '');
    $provider = new DataProvider($binKey, $binSec, '', '');
    $scan     = $provider->scanPairs($pairs);

    echo json_encode(['success' => true, 'scan' => array_values($scan)]);
}

// ─── Fiyat ──────────────────────────────────────────────────────────────────

function handleGetPrice(): void
{
    $pair     = strtoupper(trim($_GET['pair'] ?? Database::getSetting('active_pair', 'BTCUSDT')));
    $provider = new DataProvider('', '', '', '');
    $price    = $provider->getPrice($pair);
    echo json_encode(['success' => true, 'pair' => $pair, 'price' => $price]);
}

// ─── Durum ──────────────────────────────────────────────────────────────────

function handleStatus(): void
{
    $db   = Database::getInstance();
    $pair = Database::getSetting('active_pair', 'BTCUSDT');

    $openTrades = $db->query("SELECT * FROM trades WHERE status = 'open' ORDER BY created_at DESC")->fetchAll();
    $stats      = $db->query(
        "SELECT COUNT(*) as total,
                SUM(CASE WHEN pnl > 0 THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN pnl < 0 THEN 1 ELSE 0 END) as losses,
                SUM(pnl) as total_pnl
         FROM trades WHERE status = 'closed'"
    )->fetch();
    $lastLog = $db->query("SELECT * FROM logs ORDER BY created_at DESC LIMIT 1")->fetch();

    $currentPrice = null;
    try {
        $provider     = new DataProvider('', '', '', '');
        $currentPrice = $provider->getPrice($pair);
    } catch (Throwable) {}

    echo json_encode([
        'success'     => true,
        'balance'     => (float)Database::getSetting('virtual_balance', 10000),
        'active_pair' => $pair,
        'open_trades' => $openTrades,
        'stats'       => $stats,
        'last_log'    => $lastLog,
        'price'       => $currentPrice,
    ]);
}

// ─── Manuel İşlem Kapatma ───────────────────────────────────────────────────

function handleCloseTrade(): void
{
    $db      = Database::getInstance();
    $tradeId = (int)($_POST['trade_id'] ?? 0);

    $stmt = $db->prepare("SELECT * FROM trades WHERE id = :id AND status = 'open'");
    $stmt->execute([':id' => $tradeId]);
    $row = $stmt->fetch();

    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Açık işlem bulunamadı.']);
        return;
    }

    $currentPrice = $row['entry_price'];
    try {
        $provider     = new DataProvider('', '', '', '');
        $priceData    = $provider->getPrice($row['pair']);
        $currentPrice = $priceData['current'] > 0 ? $priceData['current'] : $row['entry_price'];
    } catch (Throwable) {}

    $result = closeTrade($db, $row, $currentPrice, 'Manuel kapama');
    echo json_encode(['success' => true, 'trade' => $result]);
}

// ─── Ayar Kaydet ────────────────────────────────────────────────────────────

function handleSaveSettings(): void
{
    $fields = [
        'anthropic_api_key', 'binance_api_key', 'binance_api_secret',
        'cryptopanic_api_key', 'lunarcrush_api_key',
        'active_pair', 'available_pairs',
        'take_profit_pct', 'stop_loss_pct',
        'weight_technical', 'weight_social', 'weight_news', 'weight_manipulation',
        'ai_model', 'trade_size_pct', 'bot_interval_sec',
        'max_concurrent_trades', 'min_opportunity_score', 'auto_pair_select',
    ];
    foreach ($fields as $f) {
        if (isset($_POST[$f])) Database::setSetting($f, $_POST[$f]);
    }
    echo json_encode(['success' => true]);
}

// ─── Loglar ─────────────────────────────────────────────────────────────────

function handleGetLogs(): void
{
    $db     = Database::getInstance();
    $limit  = min((int)($_GET['limit'] ?? 20), 100);
    $offset = (int)($_GET['offset'] ?? 0);

    $stmt = $db->prepare(
        'SELECT id, pair, decision, confidence, manipulation_risk, reason, created_at
         FROM logs ORDER BY created_at DESC LIMIT :lim OFFSET :off'
    );
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    echo json_encode(['success' => true, 'logs' => $stmt->fetchAll()]);
}

// ─── İşlemler ───────────────────────────────────────────────────────────────

function handleGetTrades(): void
{
    $db     = Database::getInstance();
    $status = $_GET['status'] ?? 'all';
    $where  = match ($status) {
        'open'   => "WHERE status = 'open'",
        'closed' => "WHERE status = 'closed'",
        default  => '',
    };
    $trades = $db->query("SELECT * FROM trades {$where} ORDER BY created_at DESC LIMIT 50")->fetchAll();
    echo json_encode(['success' => true, 'trades' => $trades]);
}

// ─── API Test ────────────────────────────────────────────────────────────────

function handleTestApi(): void
{
    $api = $_POST['api'] ?? $_GET['api'] ?? '';
    $key = trim($_POST['key'] ?? '');

    switch ($api) {
        case 'anthropic':
            if (empty($key)) { echo json_encode(['success' => false, 'error' => 'API key boş']); return; }
            $model   = Database::getSetting('ai_model', 'claude-sonnet-4-5');
            $reqBody = json_encode(['model' => $model, 'max_tokens' => 10, 'messages' => [['role' => 'user', 'content' => 'test']]]);
            $r       = curlGet('https://api.anthropic.com/v1/messages', ['Content-Type: application/json', "x-api-key: {$key}", 'anthropic-version: 2023-06-01'], $reqBody);
            if ($r['error']) { echo json_encode(['success' => false, 'error' => "Curl hatası: {$r['error']}"]); return; }
            $data = json_decode($r['body'], true);
            echo $r['code'] === 200
                ? json_encode(['success' => true, 'message' => "Anthropic bağlantısı başarılı. Model: {$model}"])
                : json_encode(['success' => false, 'error' => $data['error']['message'] ?? "HTTP {$r['code']}"]);
            break;

        case 'binance':
            $r    = curlGet('https://api.binance.com/api/v3/time');
            if ($r['error']) { echo json_encode(['success' => false, 'error' => "Curl: {$r['error']}"]); return; }
            $data = json_decode($r['body'], true);
            echo ($r['code'] === 200 && isset($data['serverTime']))
                ? json_encode(['success' => true, 'message' => 'Binance API erişilebilir. Sunucu saati: ' . date('H:i:s', intdiv($data['serverTime'], 1000))])
                : json_encode(['success' => false, 'error' => ($data['msg'] ?? "HTTP {$r['code']}") . ' — Fiyat CoinGecko fallback ile alınacak.']);
            break;

        case 'cryptopanic':
            if (empty($key)) { echo json_encode(['success' => false, 'error' => 'API key boş']); return; }
            $r    = curlGet("https://cryptopanic.com/api/v1/posts/?auth_token={$key}&public=true&kind=news");
            if ($r['error']) { echo json_encode(['success' => false, 'error' => "Curl: {$r['error']}"]); return; }
            $data = json_decode($r['body'], true);
            echo ($r['code'] === 200 && isset($data['results']))
                ? json_encode(['success' => true, 'message' => 'CryptoPanic bağlantısı başarılı. ' . count($data['results']) . ' haber alındı.'])
                : json_encode(['success' => false, 'error' => $data['detail'] ?? $data['error'] ?? "HTTP {$r['code']}"]);
            break;

        case 'lunarcrush':
            if (empty($key)) { echo json_encode(['success' => false, 'error' => 'API key boş']); return; }
            $r    = curlGet('https://lunarcrush.com/api4/public/coins/btc/v1', ["Authorization: Bearer {$key}"]);
            if ($r['error']) { echo json_encode(['success' => false, 'error' => "Curl: {$r['error']}"]); return; }
            $data = json_decode($r['body'], true);
            echo ($r['code'] === 200 && isset($data['data']))
                ? json_encode(['success' => true, 'message' => 'LunarCrush bağlantısı başarılı. BTC Galaxy Score: ' . ($data['data']['galaxy_score'] ?? '?')])
                : json_encode(['success' => false, 'error' => $data['error'] ?? $data['message'] ?? "HTTP {$r['code']}"]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => "Bilinmeyen API: {$api}"]);
    }
}

// ─── Ping ────────────────────────────────────────────────────────────────────

function handlePing(): void
{
    echo json_encode([
        'success'    => true,
        'php'        => PHP_VERSION,
        'curl'       => extension_loaded('curl'),
        'pdo_sqlite' => extension_loaded('pdo_sqlite'),
        'time'       => date('c'),
    ]);
}

// ─── Curl Yardımcısı ────────────────────────────────────────────────────────

function curlGet(string $url, array $headers = [], ?string $postBody = null): array
{
    foreach ([true, false] as $ssl) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => $ssl,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
            CURLOPT_FOLLOWLOCATION => true,
        ];
        if ($postBody !== null) {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = $postBody;
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!$err) return ['body' => $resp, 'code' => $code, 'error' => ''];
        if (!$ssl)  return ['body' => '', 'code' => 0, 'error' => $err];
    }
    return ['body' => '', 'code' => 0, 'error' => 'curl failed'];
}

// ─── Yardımcı Fonksiyonlar ──────────────────────────────────────────────────

function selectPairsForAnalysis(
    array $scanData,
    PDO $db,
    int $maxConcurrent,
    int $openCount,
    int $minScore,
    bool $autoSelect,
    array $allPairs
): array {
    $slots = $maxConcurrent - $openCount;
    if ($slots <= 0) return [];

    if (!$autoSelect) {
        $activePair = Database::getSetting('active_pair', 'BTCUSDT');
        return [$activePair];
    }

    $candidates = [];
    foreach ($scanData as $pair => $sd) {
        if (getOpenTrade($db, $pair)) continue;
        if ($sd['opportunity_score'] < $minScore) continue;
        $candidates[$pair] = $sd['opportunity_score'];
    }

    arsort($candidates);
    return array_keys(array_slice($candidates, 0, $slots, true));
}

function getOpenTrade(PDO $db, string $pair): array|false
{
    $stmt = $db->prepare("SELECT * FROM trades WHERE pair = :pair AND status = 'open' LIMIT 1");
    $stmt->execute([':pair' => $pair]);
    return $stmt->fetch();
}

function getOpenTradesAll(PDO $db): array
{
    return $db->query("SELECT * FROM trades WHERE status = 'open'")->fetchAll();
}

function countOpenTrades(PDO $db): int
{
    return (int)$db->query("SELECT COUNT(*) FROM trades WHERE status = 'open'")->fetchColumn();
}

function closeTrade(PDO $db, array $trade, float $exitPrice, string $note): array
{
    $pnl    = ($exitPrice - $trade['entry_price']) * $trade['quantity'];
    $pnlPct = $trade['entry_price'] > 0
        ? (($exitPrice - $trade['entry_price']) / $trade['entry_price']) * 100
        : 0;

    $db->prepare(
        'UPDATE trades SET status = :s, exit_price = :ep, pnl = :pnl, closed_at = CURRENT_TIMESTAMP WHERE id = :id'
    )->execute([':s' => 'closed', ':ep' => $exitPrice, ':pnl' => $pnl, ':id' => $trade['id']]);

    $balance = (float)Database::getSetting('virtual_balance', 10000);
    Database::setSetting('virtual_balance', $balance + ($trade['quantity'] * $exitPrice));

    return ['trade_id' => $trade['id'], 'pair' => $trade['pair'], 'entry' => $trade['entry_price'],
            'exit' => $exitPrice, 'pnl' => round($pnl, 4), 'pnl_pct' => round($pnlPct, 2), 'note' => $note];
}

function checkAndCloseTrade(PDO $db, array $trade, float $currentPrice, float $tpPct, float $slPct): void
{
    if ($trade['entry_price'] <= 0) return;
    $pct = (($currentPrice - $trade['entry_price']) / $trade['entry_price']) * 100;
    if ($pct >= $tpPct)   closeTrade($db, $trade, $currentPrice, "TP +{$tpPct}%");
    elseif ($pct <= -$slPct) closeTrade($db, $trade, $currentPrice, "SL -{$slPct}%");
}
