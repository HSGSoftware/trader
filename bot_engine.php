<?php

declare(strict_types=1);

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
        'close_trade'   => handleCloseTrade(),
        'save_settings' => handleSaveSettings(),
        'get_logs'      => handleGetLogs(),
        'get_trades'    => handleGetTrades(),
        'test_api'      => handleTestApi(),
        default         => throw new InvalidArgumentException("Bilinmeyen action: {$action}"),
    };
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// ─── Handlers ───────────────────────────────────────────────────────────────

function handleRun(): void
{
    $db = Database::getInstance();

    $anthropicKey  = Database::getSetting('anthropic_api_key',   '');
    $binanceKey    = Database::getSetting('binance_api_key',     '');
    $binanceSecret = Database::getSetting('binance_api_secret',  '');
    $cpKey         = Database::getSetting('cryptopanic_api_key', '');
    $lcKey         = Database::getSetting('lunarcrush_api_key',  '');
    $pair          = strtoupper(trim($_GET['pair'] ?? $_POST['pair'] ?? Database::getSetting('active_pair', 'BTCUSDT')));
    $model         = Database::getSetting('ai_model',            'claude-sonnet-4-5');
    $tpPct         = (float)Database::getSetting('take_profit_pct',    5);
    $slPct         = (float)Database::getSetting('stop_loss_pct',      3);
    $tradeSizePct  = (float)Database::getSetting('trade_size_pct',     10);
    $wTech         = (int)Database::getSetting('weight_technical',     40);
    $wSocial       = (int)Database::getSetting('weight_social',        20);
    $wNews         = (int)Database::getSetting('weight_news',          20);
    $wManip        = (int)Database::getSetting('weight_manipulation',  20);

    if (empty($anthropicKey)) {
        echo json_encode(['success' => false, 'error' => 'Anthropic API anahtarı girilmemiş. Lütfen Ayarlar sekmesinden ekleyin.']);
        return;
    }

    $provider = new DataProvider($binanceKey, $binanceSecret, $cpKey, $lcKey);
    $market   = $provider->collectAll($pair);

    $currentPrice = $market['price']['current'];

    if ($currentPrice <= 0) {
        echo json_encode(['success' => false, 'error' => "Fiyat verisi alınamadı ({$pair}). Parite adını kontrol edin."]);
        return;
    }

    checkOpenTrades($db, $pair, $currentPrice, $tpPct, $slPct);

    $engine   = new DecisionEngine($anthropicKey, $model, $wTech, $wSocial, $wNews, $wManip);
    $decision = $engine->analyze($market);

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

    $tradeResult = null;
    $balance     = (float)Database::getSetting('virtual_balance', 10000);

    if ($decision['decision'] === 'BUY') {
        $openTrade = getOpenTrade($db, $pair);
        if (!$openTrade) {
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
                $tradeResult = ['action' => 'BUY', 'price' => $currentPrice, 'qty' => $amount];
            }
        }
    } elseif ($decision['decision'] === 'SELL') {
        $openTrade = getOpenTrade($db, $pair);
        if ($openTrade) {
            $tradeResult = closeTrade($db, $openTrade, $currentPrice, 'AI SELL kararı');
        }
    }

    echo json_encode([
        'success'           => true,
        'pair'              => $pair,
        'price'             => $currentPrice,
        'price_source'      => $market['price']['source'] ?? 'unknown',
        'change_pct'        => $market['price']['change_pct'] ?? 0,
        'decision'          => $decision['decision'],
        'confidence'        => $decision['confidence'],
        'manipulation_risk' => $decision['manipulation_risk'],
        'reason'            => $decision['reason'],
        'trade'             => $tradeResult,
        'balance'           => (float)Database::getSetting('virtual_balance', 10000),
        'order_book'        => $market['order_book'],
    ]);
}

function handleGetPrice(): void
{
    $pair          = strtoupper(trim($_GET['pair'] ?? Database::getSetting('active_pair', 'BTCUSDT')));
    $binanceKey    = Database::getSetting('binance_api_key', '');
    $binanceSecret = Database::getSetting('binance_api_secret', '');
    $provider      = new DataProvider($binanceKey, $binanceSecret, '', '');
    $price         = $provider->getPrice($pair);

    echo json_encode([
        'success' => true,
        'pair'    => $pair,
        'price'   => $price,
    ]);
}

function handleStatus(): void
{
    $db   = Database::getInstance();
    $pair = Database::getSetting('active_pair', 'BTCUSDT');

    $openTrades = $db->query(
        "SELECT * FROM trades WHERE status = 'open' ORDER BY created_at DESC"
    )->fetchAll();

    $stats = $db->query(
        "SELECT COUNT(*) as total,
                SUM(CASE WHEN pnl > 0 THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN pnl < 0 THEN 1 ELSE 0 END) as losses,
                SUM(pnl) as total_pnl
         FROM trades WHERE status = 'closed'"
    )->fetch();

    $lastLog = $db->query(
        "SELECT * FROM logs ORDER BY created_at DESC LIMIT 1"
    )->fetch();

    // Mevcut fiyatı da döndür
    $currentPrice = null;
    try {
        $binanceKey    = Database::getSetting('binance_api_key', '');
        $binanceSecret = Database::getSetting('binance_api_secret', '');
        $provider      = new DataProvider($binanceKey, $binanceSecret, '', '');
        $priceData     = $provider->getPrice($pair);
        $currentPrice  = $priceData;
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

function handleSaveSettings(): void
{
    $fields = [
        'anthropic_api_key', 'binance_api_key', 'binance_api_secret',
        'cryptopanic_api_key', 'lunarcrush_api_key',
        'active_pair', 'available_pairs',
        'take_profit_pct', 'stop_loss_pct',
        'weight_technical', 'weight_social', 'weight_news', 'weight_manipulation',
        'ai_model', 'trade_size_pct', 'bot_interval_sec',
    ];

    foreach ($fields as $f) {
        if (isset($_POST[$f])) {
            Database::setSetting($f, $_POST[$f]);
        }
    }

    echo json_encode(['success' => true]);
}

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

function handleGetTrades(): void
{
    $db     = Database::getInstance();
    $status = $_GET['status'] ?? 'all';

    $where = '';
    if ($status === 'open')   $where = "WHERE status = 'open'";
    if ($status === 'closed') $where = "WHERE status = 'closed'";

    $trades = $db->query(
        "SELECT * FROM trades {$where} ORDER BY created_at DESC LIMIT 50"
    )->fetchAll();

    echo json_encode(['success' => true, 'trades' => $trades]);
}

function handleTestApi(): void
{
    $api = $_POST['api'] ?? $_GET['api'] ?? '';
    $key = trim($_POST['key'] ?? '');

    switch ($api) {
        case 'anthropic':
            if (empty($key)) {
                echo json_encode(['success' => false, 'error' => 'API key boş']);
                return;
            }
            $model = Database::getSetting('ai_model', 'claude-sonnet-4-5');
            $body  = json_encode([
                'model'      => $model,
                'max_tokens' => 10,
                'messages'   => [['role' => 'user', 'content' => 'test']],
            ]);
            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    "x-api-key: {$key}",
                    'anthropic-version: 2023-06-01',
                ],
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $data = json_decode($resp, true);
            if ($code === 200) {
                echo json_encode(['success' => true, 'message' => "Anthropic API bağlantısı başarılı. Model: {$model}"]);
            } else {
                $msg = $data['error']['message'] ?? "HTTP {$code}";
                echo json_encode(['success' => false, 'error' => $msg]);
            }
            break;

        case 'binance':
            $ch = curl_init('https://api.binance.com/api/v3/time');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $data = json_decode($resp, true);
            if ($code === 200 && isset($data['serverTime'])) {
                $ts = date('H:i:s', intdiv($data['serverTime'], 1000));
                echo json_encode(['success' => true, 'message' => "Binance API erişilebilir. Sunucu saati: {$ts}"]);
            } else {
                $msg = $data['msg'] ?? "HTTP {$code} — Geo-kısıtlı olabilir, fiyat CoinGecko'dan alınacak.";
                echo json_encode(['success' => false, 'error' => $msg]);
            }
            break;

        case 'cryptopanic':
            if (empty($key)) {
                echo json_encode(['success' => false, 'error' => 'API key boş']);
                return;
            }
            $url  = "https://cryptopanic.com/api/v1/posts/?auth_token={$key}&public=true";
            $ch   = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $data = json_decode($resp, true);
            if ($code === 200 && isset($data['results'])) {
                $count = count($data['results']);
                echo json_encode(['success' => true, 'message' => "CryptoPanic bağlantısı başarılı. {$count} haber alındı."]);
            } else {
                $msg = $data['detail'] ?? $data['error'] ?? "HTTP {$code}";
                echo json_encode(['success' => false, 'error' => $msg]);
            }
            break;

        case 'lunarcrush':
            if (empty($key)) {
                echo json_encode(['success' => false, 'error' => 'API key boş']);
                return;
            }
            $url = 'https://lunarcrush.com/api4/public/coins/bitcoin/v1';
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$key}"],
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $data = json_decode($resp, true);
            if ($code === 200 && isset($data['data'])) {
                $score = $data['data']['galaxy_score'] ?? '?';
                echo json_encode(['success' => true, 'message' => "LunarCrush bağlantısı başarılı. BTC Galaxy Score: {$score}"]);
            } else {
                $msg = $data['error'] ?? $data['message'] ?? "HTTP {$code}";
                echo json_encode(['success' => false, 'error' => $msg]);
            }
            break;

        default:
            echo json_encode(['success' => false, 'error' => "Bilinmeyen API: {$api}"]);
    }
}

// ─── Helpers ────────────────────────────────────────────────────────────────

function getOpenTrade(PDO $db, string $pair): array|false
{
    $stmt = $db->prepare("SELECT * FROM trades WHERE pair = :pair AND status = 'open' LIMIT 1");
    $stmt->execute([':pair' => $pair]);
    return $stmt->fetch();
}

function closeTrade(PDO $db, array $trade, float $exitPrice, string $note): array
{
    $pnl    = ($exitPrice - $trade['entry_price']) * $trade['quantity'];
    $pnlPct = $trade['entry_price'] > 0
        ? (($exitPrice - $trade['entry_price']) / $trade['entry_price']) * 100
        : 0;

    $upd = $db->prepare(
        'UPDATE trades SET status = :s, exit_price = :ep, pnl = :pnl, closed_at = CURRENT_TIMESTAMP WHERE id = :id'
    );
    $upd->execute([':s' => 'closed', ':ep' => $exitPrice, ':pnl' => $pnl, ':id' => $trade['id']]);

    $balance = (float)Database::getSetting('virtual_balance', 10000);
    Database::setSetting('virtual_balance', $balance + ($trade['quantity'] * $exitPrice));

    return [
        'trade_id' => $trade['id'],
        'pair'     => $trade['pair'],
        'entry'    => $trade['entry_price'],
        'exit'     => $exitPrice,
        'pnl'      => round($pnl, 4),
        'pnl_pct'  => round($pnlPct, 2),
        'note'     => $note,
    ];
}

function checkOpenTrades(PDO $db, string $pair, float $currentPrice, float $tpPct, float $slPct): void
{
    $stmt   = $db->query("SELECT * FROM trades WHERE status = 'open'");
    $trades = $stmt->fetchAll();

    foreach ($trades as $trade) {
        if ($trade['entry_price'] <= 0) continue;
        $pct = (($currentPrice - $trade['entry_price']) / $trade['entry_price']) * 100;

        if ($pct >= $tpPct) {
            closeTrade($db, $trade, $currentPrice, "Kar al (TP) +{$tpPct}%");
        } elseif ($pct <= -$slPct) {
            closeTrade($db, $trade, $currentPrice, "Zarar durdur (SL) -{$slPct}%");
        }
    }
}
