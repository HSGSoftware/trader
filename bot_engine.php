<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/DataProvider.php';
require_once __DIR__ . '/src/DecisionEngine.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'run';

try {
    match ($action) {
        'run'         => handleRun(),
        'status'      => handleStatus(),
        'close_trade' => handleCloseTrade(),
        'save_settings' => handleSaveSettings(),
        'get_logs'    => handleGetLogs(),
        'get_trades'  => handleGetTrades(),
        default       => throw new InvalidArgumentException("Bilinmeyen action: {$action}"),
    };
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// ─── Handlers ──────────────────────────────────────────────────────────────

function handleRun(): void
{
    $db = Database::getInstance();

    $anthropicKey   = Database::getSetting('anthropic_api_key',   '');
    $binanceKey     = Database::getSetting('binance_api_key',     '');
    $binanceSecret  = Database::getSetting('binance_api_secret',  '');
    $cpKey          = Database::getSetting('cryptopanic_api_key', '');
    $lcKey          = Database::getSetting('lunarcrush_api_key',  '');
    $pair           = $_GET['pair'] ?? $_POST['pair'] ?? Database::getSetting('active_pair', 'BTCUSDT');
    $pair           = strtoupper(trim($pair));
    $model          = Database::getSetting('ai_model',            'claude-sonnet-4-5');
    $tpPct          = (float)Database::getSetting('take_profit_pct', 5);
    $slPct          = (float)Database::getSetting('stop_loss_pct',   3);
    $tradeSizePct   = (float)Database::getSetting('trade_size_pct',  10);
    $wTech          = (int)Database::getSetting('weight_technical',   40);
    $wSocial        = (int)Database::getSetting('weight_social',      20);
    $wNews          = (int)Database::getSetting('weight_news',        20);
    $wManip         = (int)Database::getSetting('weight_manipulation',20);

    if (empty($anthropicKey)) {
        echo json_encode(['success' => false, 'error' => 'Anthropic API anahtarı eksik.']);
        return;
    }

    // Veri topla
    $provider = new DataProvider($binanceKey, $binanceSecret, $cpKey, $lcKey);
    $market   = $provider->collectAll($pair);

    $currentPrice = $market['price']['current'];

    // Önce açık işlemleri TP/SL kontrol et
    checkOpenTrades($db, $currentPrice, $tpPct, $slPct);

    // AI kararı
    $engine   = new DecisionEngine($anthropicKey, $model, $wTech, $wSocial, $wNews, $wManip);
    $decision = $engine->analyze($market);

    // Log
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
        if (!$openTrade) {
            $amount   = ($balance * $tradeSizePct / 100) / $currentPrice;
            $spent    = $amount * $currentPrice;

            if ($balance >= $spent) {
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
        'decision'          => $decision['decision'],
        'confidence'        => $decision['confidence'],
        'manipulation_risk' => $decision['manipulation_risk'],
        'reason'            => $decision['reason'],
        'trade'             => $tradeResult,
        'balance'           => (float)Database::getSetting('virtual_balance', 10000),
        'order_book'        => $market['order_book'],
    ]);
}

function handleStatus(): void
{
    $db    = Database::getInstance();
    $pair  = Database::getSetting('active_pair', 'BTCUSDT');

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

    echo json_encode([
        'success'     => true,
        'balance'     => (float)Database::getSetting('virtual_balance', 10000),
        'active_pair' => $pair,
        'open_trades' => $openTrades,
        'stats'       => $stats,
        'last_log'    => $lastLog,
    ]);
}

function handleCloseTrade(): void
{
    $db      = Database::getInstance();
    $tradeId = (int)($_POST['trade_id'] ?? 0);

    $trade = $db->prepare("SELECT * FROM trades WHERE id = :id AND status = 'open'");
    $trade->execute([':id' => $tradeId]);
    $row = $trade->fetch();

    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Açık işlem bulunamadı.']);
        return;
    }

    // Binance'den güncel fiyat
    $pair  = $row['pair'];
    $url   = "https://api.binance.com/api/v3/ticker/price?symbol={$pair}";
    $ch    = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $resp  = curl_exec($ch);
    curl_close($ch);
    $priceData    = json_decode($resp, true);
    $currentPrice = (float)($priceData['price'] ?? $row['entry_price']);

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

    $logs = $db->prepare(
        'SELECT id, pair, decision, confidence, manipulation_risk, reason, created_at
         FROM logs ORDER BY created_at DESC LIMIT :lim OFFSET :off'
    );
    $logs->bindValue(':lim', $limit, PDO::PARAM_INT);
    $logs->bindValue(':off', $offset, PDO::PARAM_INT);
    $logs->execute();

    echo json_encode(['success' => true, 'logs' => $logs->fetchAll()]);
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

// ─── Helpers ───────────────────────────────────────────────────────────────

function getOpenTrade(PDO $db, string $pair): array|false
{
    $stmt = $db->prepare("SELECT * FROM trades WHERE pair = :pair AND status = 'open' LIMIT 1");
    $stmt->execute([':pair' => $pair]);
    return $stmt->fetch();
}

function closeTrade(PDO $db, array $trade, float $exitPrice, string $note): array
{
    $pnl     = ($exitPrice - $trade['entry_price']) * $trade['quantity'];
    $pnlPct  = (($exitPrice - $trade['entry_price']) / $trade['entry_price']) * 100;

    $upd = $db->prepare(
        'UPDATE trades SET status = :s, exit_price = :ep, pnl = :pnl, closed_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $upd->execute([':s' => 'closed', ':ep' => $exitPrice, ':pnl' => $pnl, ':id' => $trade['id']]);

    $balance = (float)Database::getSetting('virtual_balance', 10000);
    Database::setSetting('virtual_balance', $balance + ($trade['quantity'] * $exitPrice));

    return [
        'trade_id'   => $trade['id'],
        'pair'       => $trade['pair'],
        'entry'      => $trade['entry_price'],
        'exit'       => $exitPrice,
        'pnl'        => round($pnl, 4),
        'pnl_pct'    => round($pnlPct, 2),
        'note'       => $note,
    ];
}

function checkOpenTrades(PDO $db, float $currentPrice, float $tpPct, float $slPct): void
{
    $stmt = $db->query("SELECT * FROM trades WHERE status = 'open'");
    $trades = $stmt->fetchAll();

    foreach ($trades as $trade) {
        $pct = (($currentPrice - $trade['entry_price']) / $trade['entry_price']) * 100;

        if ($pct >= $tpPct) {
            closeTrade($db, $trade, $currentPrice, "Kar al (TP) +{$tpPct}%");
        } elseif ($pct <= -$slPct) {
            closeTrade($db, $trade, $currentPrice, "Zarar durdur (SL) -{$slPct}%");
        }
    }
}
