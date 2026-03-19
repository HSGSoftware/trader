<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';

// Tüm ayarları çek
$db = Database::getInstance();
$allSettings = $db->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);

$availableModels = [
    'claude-opus-4-5'        => 'Claude Opus 4.5',
    'claude-sonnet-4-5'      => 'Claude Sonnet 4.5',
    'claude-haiku-3-5'       => 'Claude Haiku 3.5',
    'claude-3-7-sonnet-20250219' => 'Claude 3.7 Sonnet',
    'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet',
    'claude-3-5-haiku-20241022'  => 'Claude 3.5 Haiku',
];

$pairs = array_map('trim', explode(',', $allSettings['available_pairs'] ?? 'BTCUSDT'));

function s(string $key, string $default = ''): string {
    global $allSettings;
    return htmlspecialchars($allSettings[$key] ?? $default, ENT_QUOTES);
}
?>
<!DOCTYPE html>
<html lang="tr" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kripto Trading Bot</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root {
    --bg-card: #1a1d23;
    --bg-card2: #12151a;
    --border-color: #2a2d35;
    --accent: #6c63ff;
    --accent2: #00d4aa;
    --danger: #ff4d6d;
    --warning: #ffa837;
}
body { background: #0d0f14; font-family: 'Segoe UI', sans-serif; }
.card { background: var(--bg-card); border: 1px solid var(--border-color); }
.card-header { background: var(--bg-card2); border-bottom: 1px solid var(--border-color); }
.nav-pills .nav-link.active { background: var(--accent); }
.nav-pills .nav-link { color: #adb5bd; }
.badge-buy  { background: #00d4aa22; color: #00d4aa; border: 1px solid #00d4aa44; }
.badge-sell { background: #ff4d6d22; color: #ff4d6d; border: 1px solid #ff4d6d44; }
.badge-hold { background: #ffa83722; color: #ffa837; border: 1px solid #ffa83744; }
.stat-card { background: linear-gradient(135deg, var(--bg-card), var(--bg-card2)); border: 1px solid var(--border-color); border-radius: 12px; }
.pnl-positive { color: #00d4aa; }
.pnl-negative { color: #ff4d6d; }
.bot-pulse { animation: pulse 2s infinite; }
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50%       { opacity: 0.4; }
}
.risk-bar .progress-bar {
    transition: width 0.5s ease;
}
.log-item { border-left: 3px solid var(--border-color); transition: border-color 0.2s; }
.log-item:hover { border-left-color: var(--accent); }
.log-item.buy  { border-left-color: #00d4aa; }
.log-item.sell { border-left-color: #ff4d6d; }
.log-item.hold { border-left-color: #ffa837; }
.table { --bs-table-bg: transparent; --bs-table-border-color: var(--border-color); }
#botStatusBadge.running { background: #00d4aa22; color: #00d4aa; border: 1px solid #00d4aa; }
#botStatusBadge.stopped { background: #ff4d6d22; color: #ff4d6d; border: 1px solid #ff4d6d; }
.countdown-ring { font-size: 0.75rem; color: #6c757d; }
</style>
</head>
<body>

<nav class="navbar navbar-dark px-4 py-3 mb-4" style="background:#0a0c10; border-bottom:1px solid var(--border-color);">
    <span class="navbar-brand fw-bold fs-5">
        <i class="bi bi-graph-up-arrow me-2" style="color:var(--accent)"></i>Kripto Trading Bot
    </span>
    <div class="d-flex align-items-center gap-3">
        <select id="pairSelector" class="form-select form-select-sm" style="width:140px; background:#1a1d23; border-color:var(--border-color); color:#fff;">
            <?php foreach ($pairs as $p): ?>
                <option value="<?= htmlspecialchars($p) ?>" <?= $p === ($allSettings['active_pair'] ?? 'BTCUSDT') ? 'selected' : '' ?>>
                    <?= htmlspecialchars($p) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span id="priceDisplay" class="text-muted small me-1" style="font-family:monospace">—</span>
        <span id="botStatusBadge" class="badge rounded-pill px-3 py-2 stopped">
            <i class="bi bi-circle-fill me-1" style="font-size:8px"></i>BOT DURDU
        </span>
        <button id="btnToggleBot" class="btn btn-sm btn-outline-light">
            <i class="bi bi-play-fill me-1"></i>Başlat
        </button>
    </div>
</nav>

<div class="container-fluid px-4">

    <!-- Üst istatistikler -->
    <div class="row g-3 mb-4" id="statsRow">
        <div class="col-6 col-md-3">
            <div class="stat-card p-3">
                <div class="text-muted small mb-1"><i class="bi bi-wallet2 me-1"></i>Bakiye</div>
                <div class="fs-4 fw-bold" id="statBalance">$0.00</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card p-3">
                <div class="text-muted small mb-1"><i class="bi bi-currency-bitcoin me-1"></i>Güncel Fiyat</div>
                <div class="fs-4 fw-bold" id="statPrice">—</div>
                <div id="statPriceChange" class="small text-muted"></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card p-3">
                <div class="text-muted small mb-1"><i class="bi bi-bar-chart me-1"></i>Toplam K/Z</div>
                <div class="fs-4 fw-bold" id="statTotalPnl">$0.00</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card p-3">
                <div class="text-muted small mb-1"><i class="bi bi-trophy me-1"></i>Kazanma Oranı</div>
                <div class="fs-4 fw-bold" id="statWinRate">—</div>
            </div>
        </div>
    </div>

    <!-- Ana paneller -->
    <ul class="nav nav-pills mb-3" id="mainTabs">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="pill" href="#tabDashboard"><i class="bi bi-grid me-1"></i>Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#tabTrades"><i class="bi bi-list-ul me-1"></i>İşlemler</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#tabLogs"><i class="bi bi-journal-text me-1"></i>AI Logları</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#tabSettings"><i class="bi bi-gear me-1"></i>Ayarlar</a></li>
    </ul>

    <div class="tab-content">

        <!-- Dashboard -->
        <div class="tab-pane fade show active" id="tabDashboard">
            <div class="row g-3">

                <!-- Sol: AI son karar -->
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-cpu me-1"></i>Son AI Kararı</span>
                            <small class="text-muted" id="lastDecisionTime">—</small>
                        </div>
                        <div class="card-body">
                            <div id="botErrorAlert" class="alert alert-danger py-2 px-3 mb-3 d-none" style="font-size:0.85rem">
                                <i class="bi bi-exclamation-triangle me-1"></i><span id="botErrorMsg"></span>
                            </div>
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <span id="decisionBadge" class="badge fs-5 px-4 py-2 badge-hold">HOLD</span>
                                <div>
                                    <div class="text-muted small">Güven</div>
                                    <div class="fw-bold" id="decisionConf">0%</div>
                                </div>
                            </div>
                            <p class="text-muted mb-3" id="decisionReason" style="font-size:0.875rem; line-height:1.6">Bot başlatılmadı.</p>

                            <!-- Manipülasyon Radarı -->
                            <div class="mt-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <small class="text-muted"><i class="bi bi-shield-exclamation me-1"></i>Manipülasyon Riski</small>
                                    <small id="manipRiskVal">0/100</small>
                                </div>
                                <div class="progress risk-bar" style="height:10px; background:#1a1d23;">
                                    <div id="manipRiskBar" class="progress-bar" role="progressbar" style="width:0%; background: linear-gradient(90deg, #00d4aa, #ffa837, #ff4d6d)"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sağ: Aktif işlemler -->
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header"><i class="bi bi-activity me-1"></i>Açık İşlemler</div>
                        <div class="card-body p-0">
                            <div id="openTradesContainer" class="p-3 text-muted text-center py-5">
                                Açık işlem yok
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bot durumu + emir defteri -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header"><i class="bi bi-layers me-1"></i>Emir Defteri Analizi</div>
                        <div class="card-body" id="obPanel">
                            <div class="text-muted text-center py-3">Veri bekleniyor...</div>
                        </div>
                    </div>
                </div>

                <!-- Son 5 log özeti -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header"><i class="bi bi-clock-history me-1"></i>Son Kararlar</div>
                        <div class="card-body p-0">
                            <div id="recentLogsContainer" style="max-height:260px; overflow-y:auto;">
                                <div class="p-3 text-muted text-center">Veri bekleniyor...</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- İşlemler -->
        <div class="tab-pane fade" id="tabTrades">
            <div class="card">
                <div class="card-header d-flex justify-content-between">
                    <span><i class="bi bi-list-check me-1"></i>Tüm İşlemler</span>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-secondary active" onclick="loadTrades('all',this)">Tümü</button>
                        <button class="btn btn-outline-secondary" onclick="loadTrades('open',this)">Açık</button>
                        <button class="btn btn-outline-secondary" onclick="loadTrades('closed',this)">Kapandı</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="tradesTableContainer">
                        <div class="p-4 text-center text-muted">Yükleniyor...</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- AI Logları -->
        <div class="tab-pane fade" id="tabLogs">
            <div class="card">
                <div class="card-header d-flex justify-content-between">
                    <span><i class="bi bi-journal-richtext me-1"></i>AI Karar Logları</span>
                    <button class="btn btn-sm btn-outline-secondary" onclick="loadLogs()"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
                <div class="card-body p-0">
                    <div id="logsContainer" style="max-height:600px; overflow-y:auto;">
                        <div class="p-4 text-center text-muted">Yükleniyor...</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ayarlar -->
        <div class="tab-pane fade" id="tabSettings">
            <form id="settingsForm">
                <div class="row g-3">

                    <!-- API Anahtarları -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header"><i class="bi bi-key me-1"></i>API Anahtarları</div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label text-muted small">Anthropic API Key</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="inp_anthropic" name="anthropic_api_key" value="<?= s('anthropic_api_key') ?>" placeholder="sk-ant-...">
                                        <button type="button" class="btn btn-outline-secondary" onclick="testApi('anthropic','inp_anthropic','res_anthropic')">
                                            <i class="bi bi-plug me-1"></i>Test
                                        </button>
                                    </div>
                                    <div id="res_anthropic" class="mt-1"></div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted small">Binance API Key</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="inp_binance" name="binance_api_key" value="<?= s('binance_api_key') ?>">
                                        <button type="button" class="btn btn-outline-secondary" onclick="testApi('binance','inp_binance','res_binance')">
                                            <i class="bi bi-plug me-1"></i>Test
                                        </button>
                                    </div>
                                    <div id="res_binance" class="mt-1"></div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted small">Binance Secret</label>
                                    <input type="password" class="form-control" name="binance_api_secret" value="<?= s('binance_api_secret') ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted small">CryptoPanic API Key <span class="text-muted">(opsiyonel)</span></label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="inp_cryptopanic" name="cryptopanic_api_key" value="<?= s('cryptopanic_api_key') ?>">
                                        <button type="button" class="btn btn-outline-secondary" onclick="testApi('cryptopanic','inp_cryptopanic','res_cryptopanic')">
                                            <i class="bi bi-plug me-1"></i>Test
                                        </button>
                                    </div>
                                    <div id="res_cryptopanic" class="mt-1"></div>
                                </div>
                                <div class="mb-0">
                                    <label class="form-label text-muted small">LunarCrush API Key <span class="text-muted">(opsiyonel)</span></label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="inp_lunarcrush" name="lunarcrush_api_key" value="<?= s('lunarcrush_api_key') ?>">
                                        <button type="button" class="btn btn-outline-secondary" onclick="testApi('lunarcrush','inp_lunarcrush','res_lunarcrush')">
                                            <i class="bi bi-plug me-1"></i>Test
                                        </button>
                                    </div>
                                    <div id="res_lunarcrush" class="mt-1"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Model ve Parite -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header"><i class="bi bi-robot me-1"></i>Model ve Parite</div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label text-muted small">AI Modeli</label>
                                    <select class="form-select" name="ai_model">
                                        <?php foreach ($availableModels as $val => $label): ?>
                                            <option value="<?= $val ?>" <?= ($allSettings['ai_model'] ?? '') === $val ? 'selected' : '' ?>>
                                                <?= $label ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted small">Aktif Parite</label>
                                    <select class="form-select" name="active_pair" id="settingsPair">
                                        <?php foreach ($pairs as $p): ?>
                                            <option value="<?= htmlspecialchars($p) ?>" <?= $p === ($allSettings['active_pair'] ?? 'BTCUSDT') ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($p) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted small">Kullanılabilir Pariteler (virgülle)</label>
                                    <input type="text" class="form-control" name="available_pairs" value="<?= s('available_pairs') ?>" placeholder="BTCUSDT,ETHUSDT,SOLUSDT">
                                </div>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label class="form-label text-muted small">Bot Aralığı (sn)</label>
                                        <input type="number" class="form-control" name="bot_interval_sec" value="<?= s('bot_interval_sec','60') ?>" min="30">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label text-muted small">İşlem Büyüklüğü (%)</label>
                                        <input type="number" class="form-control" name="trade_size_pct" value="<?= s('trade_size_pct','10') ?>" min="1" max="100">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Strateji -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header"><i class="bi bi-sliders me-1"></i>Risk Yönetimi</div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-6">
                                        <label class="form-label text-muted small">Kar Al (%)</label>
                                        <input type="number" step="0.1" class="form-control" name="take_profit_pct" value="<?= s('take_profit_pct','5') ?>">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label text-muted small">Zarar Durdur (%)</label>
                                        <input type="number" step="0.1" class="form-control" name="stop_loss_pct" value="<?= s('stop_loss_pct','3') ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Karar Ağırlıkları -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header"><i class="bi bi-pie-chart me-1"></i>Karar Ağırlıkları</div>
                            <div class="card-body">
                                <div id="weightInputs">
                                    <?php
                                    $weights = [
                                        'weight_technical'    => ['Teknik Analiz', 40],
                                        'weight_social'       => ['Sosyal Medya',  20],
                                        'weight_news'         => ['Haberler',      20],
                                        'weight_manipulation' => ['Manipülasyon',  20],
                                    ];
                                    foreach ($weights as $name => [$label, $def]): ?>
                                        <div class="mb-2">
                                            <div class="d-flex justify-content-between">
                                                <label class="form-label text-muted small mb-1"><?= $label ?></label>
                                                <small class="text-muted weight-total-label" id="lbl_<?= $name ?>">
                                                    <?= s($name, (string)$def) ?>%
                                                </small>
                                            </div>
                                            <input type="range" class="form-range weight-range" name="<?= $name ?>"
                                                   min="0" max="100"
                                                   value="<?= s($name, (string)$def) ?>"
                                                   oninput="updateWeightLabel('<?= $name ?>', this.value)">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="text-end">
                                    <small class="text-muted">Toplam: <span id="totalWeight"><?= array_sum(array_map(fn($k,$d) => (int)($allSettings[$k] ?? $d[1]), array_keys($weights), $weights)) ?></span>%</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary px-5">
                            <i class="bi bi-save me-1"></i>Ayarları Kaydet
                        </button>
                        <span id="saveStatus" class="ms-3 text-muted small"></span>
                    </div>

                </div>
            </form>
        </div>

    </div>
</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ─── State ──────────────────────────────────────────────────────────────────
const state = {
    running:      false,
    intervalId:   null,
    countdownId:  null,
    countdown:    0,
    intervalSec:  <?= (int)($allSettings['bot_interval_sec'] ?? 60) ?>,
    lastDecision: null,
    lastOBData:   null,
};

// ─── Bot Toggle ──────────────────────────────────────────────────────────────
document.getElementById('btnToggleBot').addEventListener('click', () => {
    if (state.running) stopBot();
    else startBot();
});

function startBot() {
    state.running    = true;
    state.intervalSec = parseInt(document.querySelector('[name=bot_interval_sec]')?.value || 60);
    updateBotUI();
    runBotCycle();
    state.intervalId  = setInterval(runBotCycle, state.intervalSec * 1000);
    startCountdown();
}

function stopBot() {
    state.running = false;
    clearInterval(state.intervalId);
    clearInterval(state.countdownId);
    updateBotUI();
}

function updateBotUI() {
    const badge = document.getElementById('botStatusBadge');
    const btn   = document.getElementById('btnToggleBot');
    if (state.running) {
        badge.textContent = '';
        badge.innerHTML   = '<i class="bi bi-circle-fill me-1 bot-pulse" style="font-size:8px"></i>BOT ÇALIŞIYOR';
        badge.className   = 'badge rounded-pill px-3 py-2 running';
        btn.innerHTML     = '<i class="bi bi-stop-fill me-1"></i>Durdur';
        btn.className     = 'btn btn-sm btn-outline-danger';
    } else {
        badge.textContent = '';
        badge.innerHTML   = '<i class="bi bi-circle-fill me-1" style="font-size:8px"></i>BOT DURDU';
        badge.className   = 'badge rounded-pill px-3 py-2 stopped';
        btn.innerHTML     = '<i class="bi bi-play-fill me-1"></i>Başlat';
        btn.className     = 'btn btn-sm btn-outline-light';
    }
}

function startCountdown() {
    clearInterval(state.countdownId);
    state.countdown = state.intervalSec;
    state.countdownId = setInterval(() => {
        state.countdown--;
        const badge = document.getElementById('botStatusBadge');
        if (state.running && state.countdown > 0) {
            badge.innerHTML = `<i class="bi bi-circle-fill me-1 bot-pulse" style="font-size:8px"></i>BOT ÇALIŞIYOR <small class="opacity-75">(${state.countdown}s)</small>`;
        } else if (state.countdown <= 0) {
            state.countdown = state.intervalSec;
        }
    }, 1000);
}

// ─── Bot Cycle ───────────────────────────────────────────────────────────────
async function runBotCycle() {
    const pair = document.getElementById('pairSelector').value;
    hideBotError();
    try {
        const resp = await fetch(`bot_engine.php?action=run&pair=${pair}`);
        let data;
        try {
            data = await resp.json();
        } catch {
            const text = await resp.text().catch(() => `HTTP ${resp.status}`);
            showBotError('Sunucu yanıtı JSON değil: ' + text.substring(0, 150));
            return;
        }
        if (!data.success) {
            showBotError(data.error || 'Bilinmeyen hata');
            return;
        }
        state.lastDecision = data;
        hideBotError();
        showDecision(data);
        updateStats(data);
        refreshOpenTrades();
        refreshRecentLogs();
        refreshStats();
        if (data.order_book) updateOBPanel(data.order_book);
    } catch(e) {
        showBotError('Sunucu bağlantı hatası: ' + e.message);
    }
}

function showBotError(msg) {
    document.getElementById('botErrorMsg').textContent = msg;
    document.getElementById('botErrorAlert').classList.remove('d-none');
}

function hideBotError() {
    document.getElementById('botErrorAlert').classList.add('d-none');
}

// ─── UI Updates ──────────────────────────────────────────────────────────────
function showDecision(d) {
    const badge = document.getElementById('decisionBadge');
    badge.textContent = d.decision;
    badge.className = `badge fs-5 px-4 py-2 badge-${d.decision.toLowerCase()}`;

    document.getElementById('decisionConf').textContent   = `${d.confidence ?? 0}%`;
    document.getElementById('decisionReason').textContent = d.reason || '—';
    document.getElementById('lastDecisionTime').textContent = new Date().toLocaleTimeString('tr-TR');

    const risk = d.manipulation_risk ?? 0;
    document.getElementById('manipRiskVal').textContent = `${risk}/100`;
    document.getElementById('manipRiskBar').style.width = `${risk}%`;
}

function updateStats(data) {
    if (data.balance !== undefined)
        document.getElementById('statBalance').textContent =
            '$' + Number(data.balance).toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2});
    if (data.price !== undefined && data.price > 0) {
        const priceStr = '$' + Number(data.price).toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2});
        document.getElementById('statPrice').textContent = priceStr;
        const chg = parseFloat(data.change_pct) || 0;
        const chgEl = document.getElementById('statPriceChange');
        if (chgEl) {
            chgEl.textContent = (chg >= 0 ? '▲' : '▼') + ' ' + Math.abs(chg).toFixed(2) + '%';
            chgEl.className   = 'small ' + (chg >= 0 ? 'pnl-positive' : 'pnl-negative');
        }
        document.getElementById('priceDisplay').textContent = priceStr;
    }
}

async function refreshStats() {
    const resp = await fetch('bot_engine.php?action=status');
    const data = await resp.json();
    if (!data.success) return;

    document.getElementById('statBalance').textContent =
        '$' + Number(data.balance).toLocaleString('tr-TR', {minimumFractionDigits:2});

    if (data.price && data.price.current > 0) {
        const pr    = data.price;
        const prStr = '$' + Number(pr.current).toLocaleString('tr-TR', {minimumFractionDigits:2});
        document.getElementById('statPrice').textContent = prStr;
        document.getElementById('priceDisplay').textContent = prStr;
        const chgEl = document.getElementById('statPriceChange');
        if (chgEl) {
            const chg = parseFloat(pr.change_pct) || 0;
            chgEl.textContent = (chg >= 0 ? '▲' : '▼') + ' ' + Math.abs(chg).toFixed(2) + '%';
            chgEl.className   = 'small ' + (chg >= 0 ? 'pnl-positive' : 'pnl-negative');
        }
    }

    const s = data.stats;
    if (s) {
        const pnl = parseFloat(s.total_pnl) || 0;
        const el  = document.getElementById('statTotalPnl');
        el.textContent = (pnl >= 0 ? '+' : '') + '$' + Math.abs(pnl).toLocaleString('tr-TR', {minimumFractionDigits:2});
        el.className   = 'fs-4 fw-bold ' + (pnl >= 0 ? 'pnl-positive' : 'pnl-negative');

        const total = (parseInt(s.wins)||0) + (parseInt(s.losses)||0);
        document.getElementById('statWinRate').textContent =
            total > 0 ? Math.round((s.wins / total) * 100) + '%' : '—';
    }
}

async function fetchAndShowPrice() {
    const pair = document.getElementById('pairSelector').value;
    try {
        const resp = await fetch(`bot_engine.php?action=get_price&pair=${pair}`);
        const data = await resp.json();
        if (data.success && data.price && data.price.current > 0) {
            const pr    = data.price;
            const prStr = '$' + Number(pr.current).toLocaleString('tr-TR', {minimumFractionDigits:2});
            document.getElementById('statPrice').textContent = prStr;
            document.getElementById('priceDisplay').textContent = prStr;
            const chgEl = document.getElementById('statPriceChange');
            if (chgEl) {
                const chg = parseFloat(pr.change_pct) || 0;
                chgEl.textContent = (chg >= 0 ? '▲' : '▼') + ' ' + Math.abs(chg).toFixed(2) + '%';
                chgEl.className   = 'small ' + (chg >= 0 ? 'pnl-positive' : 'pnl-negative');
            }
        }
    } catch(e) {}
}

async function refreshOpenTrades() {
    const resp = await fetch('bot_engine.php?action=get_trades&status=open');
    const data = await resp.json();
    const cont = document.getElementById('openTradesContainer');

    if (!data.trades || data.trades.length === 0) {
        cont.innerHTML = '<div class="text-muted text-center py-5">Açık işlem yok</div>';
        return;
    }

    let html = '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr>'
        + '<th>Parite</th><th>Giriş</th><th>Miktar</th><th>Tarih</th><th></th>'
        + '</tr></thead><tbody>';

    for (const t of data.trades) {
        const date = new Date(t.created_at + 'Z').toLocaleString('tr-TR');
        html += `<tr>
            <td><span class="badge" style="background:#6c63ff22;color:#6c63ff">${t.pair}</span></td>
            <td>$${parseFloat(t.entry_price).toLocaleString('tr-TR', {minimumFractionDigits:2})}</td>
            <td>${parseFloat(t.quantity).toFixed(6)}</td>
            <td><small class="text-muted">${date}</small></td>
            <td><button class="btn btn-xs btn-outline-danger py-0 px-2" style="font-size:0.75rem"
                onclick="closeTrade(${t.id})">Kapat</button></td>
        </tr>`;
    }
    html += '</tbody></table></div>';
    cont.innerHTML = html;
}

async function refreshRecentLogs() {
    const resp = await fetch('bot_engine.php?action=get_logs&limit=5');
    const data = await resp.json();
    const cont = document.getElementById('recentLogsContainer');

    if (!data.logs || data.logs.length === 0) {
        cont.innerHTML = '<div class="p-3 text-muted text-center">Kayıt yok</div>';
        return;
    }

    let html = '';
    for (const l of data.logs) {
        const dt = new Date(l.created_at + 'Z').toLocaleString('tr-TR');
        const decisionClass = l.decision.toLowerCase();
        html += `<div class="log-item ${decisionClass} px-3 py-2 border-bottom">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge badge-${decisionClass} me-2">${l.decision}</span>
                    <small class="text-muted">${l.pair}</small>
                    <small class="ms-2 text-muted">Güven: ${l.confidence}% | Risk: ${l.manipulation_risk}%</small>
                </div>
                <small class="text-muted">${dt}</small>
            </div>
            <small class="text-secondary mt-1 d-block">${l.reason || ''}</small>
        </div>`;
    }
    cont.innerHTML = html;
}

function updateOBPanel(ob) {
    if (!ob) return;
    const imb   = parseFloat(ob.imbalance_pct) || 0;
    const color = imb > 0 ? '#00d4aa' : '#ff4d6d';
    const spoof = ob.spoofing_suspect ? '<span class="badge bg-danger">Spoofing Şüphesi!</span>' : '<span class="badge bg-success">Normal</span>';

    document.getElementById('obPanel').innerHTML = `
        <div class="row g-2 text-center">
            <div class="col-4">
                <div class="text-muted small">Alış Miktarı</div>
                <div class="fw-bold text-success">${parseFloat(ob.total_bid_qty).toFixed(2)}</div>
            </div>
            <div class="col-4">
                <div class="text-muted small">Satış Miktarı</div>
                <div class="fw-bold text-danger">${parseFloat(ob.total_ask_qty).toFixed(2)}</div>
            </div>
            <div class="col-4">
                <div class="text-muted small">Dengesizlik</div>
                <div class="fw-bold" style="color:${color}">${imb > 0 ? '+' : ''}${imb}%</div>
            </div>
        </div>
        <hr class="my-2" style="border-color:var(--border-color)">
        <div class="row g-2 text-center">
            <div class="col-6">
                <div class="text-muted small">En Büyük Alış Duvarı</div>
                <div class="small">${parseFloat(ob.max_bid_wall?.qty||0).toFixed(2)} adet</div>
                <div class="text-muted small">Oran: ${ob.bid_wall_ratio}x</div>
            </div>
            <div class="col-6">
                <div class="text-muted small">En Büyük Satış Duvarı</div>
                <div class="small">${parseFloat(ob.max_ask_wall?.qty||0).toFixed(2)} adet</div>
                <div class="text-muted small">Oran: ${ob.ask_wall_ratio}x</div>
            </div>
        </div>
        <div class="text-center mt-2">${spoof}</div>
    `;
}

// ─── Trades Tab ──────────────────────────────────────────────────────────────
async function loadTrades(status = 'all', btn = null) {
    if (btn) {
        document.querySelectorAll('.btn-group .btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
    }
    const resp = await fetch(`bot_engine.php?action=get_trades&status=${status}`);
    const data = await resp.json();
    const cont = document.getElementById('tradesTableContainer');

    if (!data.trades || data.trades.length === 0) {
        cont.innerHTML = '<div class="p-5 text-center text-muted">İşlem kaydı yok</div>';
        return;
    }

    let html = `<div class="table-responsive"><table class="table table-hover mb-0">
        <thead><tr>
            <th>#</th><th>Parite</th><th>Tür</th><th>Giriş</th><th>Çıkış</th>
            <th>Miktar</th><th>K/Z</th><th>Manip. Risk</th><th>Durum</th><th>Tarih</th>
        </tr></thead><tbody>`;

    for (const t of data.trades) {
        const pnl    = parseFloat(t.pnl) || 0;
        const pnlCls = pnl >= 0 ? 'pnl-positive' : 'pnl-negative';
        const pnlTxt = t.pnl !== null ? ((pnl >= 0 ? '+' : '') + '$' + pnl.toFixed(2)) : '—';
        const dt     = new Date(t.created_at + 'Z').toLocaleString('tr-TR');
        const statusBadge = t.status === 'open'
            ? '<span class="badge bg-success bg-opacity-25 text-success">Açık</span>'
            : '<span class="badge bg-secondary bg-opacity-25 text-secondary">Kapandı</span>';

        html += `<tr>
            <td class="text-muted">#${t.id}</td>
            <td><span class="badge" style="background:#6c63ff22;color:#6c63ff">${t.pair}</span></td>
            <td><span class="badge badge-${t.type.toLowerCase()}">${t.type}</span></td>
            <td>$${parseFloat(t.entry_price).toLocaleString('tr-TR',{minimumFractionDigits:2})}</td>
            <td>${t.exit_price ? '$'+parseFloat(t.exit_price).toLocaleString('tr-TR',{minimumFractionDigits:2}) : '—'}</td>
            <td>${parseFloat(t.quantity).toFixed(6)}</td>
            <td class="${pnlCls} fw-bold">${pnlTxt}</td>
            <td>
                <div class="progress" style="height:6px;width:60px">
                    <div class="progress-bar" style="width:${t.manipulation_risk_score}%;background:linear-gradient(90deg,#00d4aa,#ff4d6d)"></div>
                </div>
                <small class="text-muted">${t.manipulation_risk_score}%</small>
            </td>
            <td>${statusBadge}</td>
            <td><small class="text-muted">${dt}</small></td>
        </tr>`;
    }

    html += '</tbody></table></div>';
    cont.innerHTML = html;
}

// ─── Logs Tab ────────────────────────────────────────────────────────────────
async function loadLogs() {
    const resp = await fetch('bot_engine.php?action=get_logs&limit=50');
    const data = await resp.json();
    const cont = document.getElementById('logsContainer');

    if (!data.logs || data.logs.length === 0) {
        cont.innerHTML = '<div class="p-5 text-center text-muted">Log kaydı yok</div>';
        return;
    }

    let html = '';
    for (const l of data.logs) {
        const dt  = new Date(l.created_at + 'Z').toLocaleString('tr-TR');
        const dcl = l.decision.toLowerCase();
        html += `<div class="log-item ${dcl} px-4 py-3 border-bottom">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge badge-${dcl} px-3">${l.decision}</span>
                    <span class="badge bg-dark border" style="border-color:var(--border-color)!important">${l.pair}</span>
                    <span class="text-muted small">Güven: <strong>${l.confidence}%</strong></span>
                    <span class="text-muted small">Manip. Risk: <strong>${l.manipulation_risk}%</strong></span>
                </div>
                <small class="text-muted">${dt}</small>
            </div>
            <p class="mb-0 mt-2 text-secondary small" style="line-height:1.6">${l.reason || ''}</p>
        </div>`;
    }
    cont.innerHTML = html;
}

// ─── Close Trade ─────────────────────────────────────────────────────────────
async function closeTrade(id) {
    const fd = new FormData();
    fd.append('action', 'close_trade');
    fd.append('trade_id', id);
    const resp = await fetch('bot_engine.php', { method: 'POST', body: fd });
    const data = await resp.json();
    if (data.success) {
        refreshOpenTrades();
        refreshStats();
        loadTrades('all');
    } else {
        alert('Hata: ' + data.error);
    }
}

// ─── Pair Selector ───────────────────────────────────────────────────────────
document.getElementById('pairSelector').addEventListener('change', function() {
    const fd = new FormData();
    fd.append('action', 'save_settings');
    fd.append('active_pair', this.value);
    fetch('bot_engine.php', { method: 'POST', body: fd });
    fetchAndShowPrice();
});

// ─── Settings ────────────────────────────────────────────────────────────────
document.getElementById('settingsForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    fd.append('action', 'save_settings');

    const status = document.getElementById('saveStatus');
    status.textContent = 'Kaydediliyor...';
    status.className   = 'ms-3 text-muted small';

    const resp = await fetch('bot_engine.php', { method: 'POST', body: fd });
    const data = await resp.json();

    if (data.success) {
        status.textContent = 'Kaydedildi!';
        status.className   = 'ms-3 text-success small';
        // Navbar pair selector'ı güncelle
        const newPairs = document.querySelector('[name=available_pairs]').value.split(',').map(p => p.trim());
        const sel = document.getElementById('pairSelector');
        const cur = sel.value;
        sel.innerHTML = newPairs.map(p => `<option value="${p}" ${p===cur?'selected':''}>${p}</option>`).join('');

        // Interval güncelle
        state.intervalSec = parseInt(document.querySelector('[name=bot_interval_sec]').value) || 60;
    } else {
        status.textContent = 'Hata!';
        status.className   = 'ms-3 text-danger small';
    }
    setTimeout(() => { status.textContent = ''; }, 3000);
});

function updateWeightLabel(name, val) {
    document.getElementById('lbl_' + name).textContent = val + '%';
    const ranges = document.querySelectorAll('.weight-range');
    let total = 0;
    ranges.forEach(r => { total += parseInt(r.value) || 0; });
    document.getElementById('totalWeight').textContent = total;
}

// ─── Tab event listeners ──────────────────────────────────────────────────────
document.querySelector('[href="#tabTrades"]').addEventListener('click', () => loadTrades('all'));
document.querySelector('[href="#tabLogs"]').addEventListener('click', () => loadLogs());

// ─── API Test ────────────────────────────────────────────────────────────────
async function testApi(api, inputId, resultId) {
    const key    = document.getElementById(inputId).value.trim();
    const el     = document.getElementById(resultId);
    el.innerHTML = '<span class="text-muted small"><i class="bi bi-hourglass-split me-1"></i>Test ediliyor...</span>';

    const fd = new FormData();
    fd.append('action', 'test_api');
    fd.append('api', api);
    fd.append('key', key);

    try {
        const resp = await fetch('bot_engine.php', { method: 'POST', body: fd });
        let data;
        try {
            data = await resp.json();
        } catch {
            const text = await resp.text().catch(() => `HTTP ${resp.status}`);
            el.innerHTML = `<span class="text-danger small"><i class="bi bi-x-circle me-1"></i>Sunucu yanıtı: ${text.substring(0, 120)}</span>`;
            return;
        }
        if (data.success) {
            el.innerHTML = `<span class="text-success small"><i class="bi bi-check-circle me-1"></i>${data.message}</span>`;
        } else {
            el.innerHTML = `<span class="text-danger small"><i class="bi bi-x-circle me-1"></i>${data.error}</span>`;
        }
    } catch(e) {
        el.innerHTML = `<span class="text-danger small"><i class="bi bi-x-circle me-1"></i>İstek başarısız: ${e.message}</span>`;
    }
}

// ─── Init ────────────────────────────────────────────────────────────────────
refreshStats();
refreshOpenTrades();
refreshRecentLogs();
fetchAndShowPrice();

// Fiyatı her 30 saniyede yenile (bot çalışmasa bile)
setInterval(fetchAndShowPrice, 30000);
</script>
</body>
</html>
