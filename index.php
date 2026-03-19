<?php
declare(strict_types=1);
require_once __DIR__ . '/src/Database.php';

$db          = Database::getInstance();
$allSettings = $db->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);

$availableModels = [
    'claude-opus-4-5'            => 'Claude Opus 4.5',
    'claude-sonnet-4-5'          => 'Claude Sonnet 4.5',
    'claude-haiku-3-5'           => 'Claude Haiku 3.5',
    'claude-3-7-sonnet-20250219' => 'Claude 3.7 Sonnet',
    'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet',
    'claude-3-5-haiku-20241022'  => 'Claude 3.5 Haiku',
];

$pairs = array_map('trim', explode(',', $allSettings['available_pairs'] ?? 'BTCUSDT'));

function s(string $key, string $default = ''): string {
    global $allSettings;
    return htmlspecialchars($allSettings[$key] ?? $default, ENT_QUOTES);
}
function si(string $key, int $default = 0): int {
    global $allSettings;
    return (int)($allSettings[$key] ?? $default);
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
    --bg: #0d0f14;
    --card: #1a1d23;
    --card2: #12151a;
    --border: #2a2d35;
    --accent: #6c63ff;
    --green: #00d4aa;
    --red: #ff4d6d;
    --warn: #ffa837;
}
body { background: var(--bg); font-family: 'Segoe UI', sans-serif; }
.card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; }
.card-header { background: var(--card2); border-bottom: 1px solid var(--border); border-radius: 12px 12px 0 0 !important; }
.nav-pills .nav-link.active { background: var(--accent); }
.nav-pills .nav-link { color: #adb5bd; }
.badge-buy  { background: #00d4aa22; color: var(--green); border: 1px solid #00d4aa44; }
.badge-sell { background: #ff4d6d22; color: var(--red);   border: 1px solid #ff4d6d44; }
.badge-hold { background: #ffa83722; color: var(--warn);  border: 1px solid #ffa83744; }
.pnl\+ { color: var(--green); }
.pnl- { color: var(--red); }
.stat-card { background: linear-gradient(135deg, var(--card), var(--card2)); border: 1px solid var(--border); border-radius: 12px; }
.bot-pulse { animation: pulse 1.5s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.3} }
.log-item { border-left: 3px solid var(--border); }
.log-item.buy  { border-left-color: var(--green); }
.log-item.sell { border-left-color: var(--red); }
.log-item.hold { border-left-color: var(--warn); }
#botBadge.running { background:#00d4aa22; color:var(--green); border:1px solid var(--green); }
#botBadge.stopped { background:#ff4d6d22; color:var(--red);   border:1px solid var(--red); }
.table { --bs-table-bg:transparent; --bs-table-border-color:var(--border); }
.scan-row-pump  { background: rgba(255,77,109,.07) !important; }
.scan-row-opp   { background: rgba(0,212,170,.07) !important; }
.pump-badge { font-size:.7rem; }
</style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar px-4 py-2 mb-4" style="background:#0a0c10;border-bottom:1px solid var(--border)">
    <span class="navbar-brand fw-bold">
        <i class="bi bi-graph-up-arrow me-2" style="color:var(--accent)"></i>Kripto Bot
    </span>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <span id="priceDisplay" class="text-muted small" style="font-family:monospace">—</span>
        <span id="botBadge" class="badge rounded-pill px-3 py-2 stopped">
            <i class="bi bi-circle-fill me-1" style="font-size:7px"></i>DURDU
        </span>
        <button id="btnBot" class="btn btn-sm btn-outline-light">
            <i class="bi bi-play-fill me-1"></i>Başlat
        </button>
    </div>
</nav>

<div class="container-fluid px-4">

    <!-- İstatistik kartları -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card p-3">
                <div class="text-muted small mb-1"><i class="bi bi-wallet2 me-1"></i>Bakiye</div>
                <div class="fs-4 fw-bold" id="statBalance">$0.00</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card p-3">
                <div class="text-muted small mb-1"><i class="bi bi-layers me-1"></i>Açık İşlem</div>
                <div class="fs-4 fw-bold" id="statOpenCount">0</div>
                <div class="small text-muted">/ <?= si('max_concurrent_trades', 3) ?> maks</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card p-3">
                <div class="text-muted small mb-1"><i class="bi bi-bar-chart me-1"></i>Toplam K/Z</div>
                <div class="fs-4 fw-bold" id="statPnl">$0.00</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card p-3">
                <div class="text-muted small mb-1"><i class="bi bi-trophy me-1"></i>Kazanma</div>
                <div class="fs-4 fw-bold" id="statWin">—</div>
            </div>
        </div>
    </div>

    <!-- Tab navigasyon -->
    <ul class="nav nav-pills mb-3" id="tabs">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="pill" href="#tabDash"><i class="bi bi-grid me-1"></i>Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#tabScanner"><i class="bi bi-radar me-1"></i>Tarayıcı</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#tabTrades"><i class="bi bi-list-ul me-1"></i>İşlemler</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#tabLogs"><i class="bi bi-journal-text me-1"></i>Loglar</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#tabSettings"><i class="bi bi-gear me-1"></i>Ayarlar</a></li>
    </ul>

    <div class="tab-content">

        <!-- Dashboard -->
        <div class="tab-pane fade show active" id="tabDash">
            <div class="row g-3">

                <!-- AI Kararları -->
                <div class="col-12">
                    <div id="botErrorAlert" class="alert alert-danger py-2 px-3 d-none">
                        <i class="bi bi-exclamation-triangle me-1"></i><span id="botErrorMsg"></span>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-cpu me-1"></i>Son AI Analiz Sonuçları</span>
                            <small class="text-muted" id="lastRunTime">—</small>
                        </div>
                        <div class="card-body p-0">
                            <div id="analysesContainer" class="p-3 text-muted text-center py-4">
                                Bot başlatılmadı.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Açık İşlemler -->
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header"><i class="bi bi-activity me-1"></i>Açık İşlemler</div>
                        <div class="card-body p-0">
                            <div id="openTradesContainer" class="p-3 text-muted text-center py-4">Açık işlem yok</div>
                        </div>
                    </div>
                </div>

                <!-- Son Kararlar -->
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header"><i class="bi bi-clock-history me-1"></i>Son Kararlar</div>
                        <div class="card-body p-0">
                            <div id="recentLogsContainer" style="max-height:280px;overflow-y:auto">
                                <div class="p-3 text-muted text-center">Bekleniyor...</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Parite Tarayıcısı -->
        <div class="tab-pane fade" id="tabScanner">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-radar me-1"></i>Parite Tarayıcısı</span>
                    <button class="btn btn-sm btn-outline-secondary" onclick="runManualScan()">
                        <i class="bi bi-arrow-clockwise me-1"></i>Tara
                    </button>
                </div>
                <div class="card-body p-0">
                    <div id="scannerContainer">
                        <div class="p-4 text-center text-muted">Tarama için butona bas veya botu başlat.</div>
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
                <div class="card-body p-0" id="tradesTableContainer">
                    <div class="p-4 text-center text-muted">Yükleniyor...</div>
                </div>
            </div>
        </div>

        <!-- Loglar -->
        <div class="tab-pane fade" id="tabLogs">
            <div class="card">
                <div class="card-header d-flex justify-content-between">
                    <span><i class="bi bi-journal-richtext me-1"></i>AI Karar Logları</span>
                    <button class="btn btn-sm btn-outline-secondary" onclick="loadLogs()"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
                <div class="card-body p-0">
                    <div id="logsContainer" style="max-height:600px;overflow-y:auto">
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
                                <?php
                                $apiFields = [
                                    ['anthropic_api_key', 'Anthropic API Key', 'password', 'sk-ant-...', 'anthropic'],
                                    ['binance_api_key',   'Binance API Key',   'text',     '',           'binance'],
                                    ['binance_api_secret','Binance Secret',    'password', '',           ''],
                                    ['cryptopanic_api_key','CryptoPanic API Key (opsiyonel)', 'text', '', 'cryptopanic'],
                                    ['lunarcrush_api_key', 'LunarCrush API Key (opsiyonel)',  'text', '', 'lunarcrush'],
                                ];
                                foreach ($apiFields as [$name, $label, $type, $ph, $testApi]): ?>
                                    <div class="mb-3">
                                        <label class="form-label text-muted small"><?= $label ?></label>
                                        <div class="input-group">
                                            <input type="<?= $type ?>" class="form-control" id="inp_<?= $name ?>"
                                                   name="<?= $name ?>" value="<?= s($name) ?>" placeholder="<?= $ph ?>">
                                            <?php if ($testApi): ?>
                                            <button type="button" class="btn btn-outline-secondary"
                                                    onclick="testApi('<?= $testApi ?>','inp_<?= $name ?>','res_<?= $name ?>')">
                                                <i class="bi bi-plug me-1"></i>Test
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($testApi): ?><div id="res_<?= $name ?>" class="mt-1"></div><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Model, Parite, Bot -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header"><i class="bi bi-robot me-1"></i>Model & Parite</div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label text-muted small">AI Modeli</label>
                                    <select class="form-select" name="ai_model">
                                        <?php foreach ($availableModels as $val => $lbl): ?>
                                            <option value="<?= $val ?>" <?= s('ai_model') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted small">Taranacak Pariteler (virgülle)</label>
                                    <input type="text" class="form-control" name="available_pairs" value="<?= s('available_pairs') ?>" placeholder="BTCUSDT,ETHUSDT,...">
                                    <div class="form-text">Bot bunların içinden en iyi fırsatı seçer.</div>
                                </div>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label class="form-label text-muted small">Maks. Eş Zamanlı İşlem</label>
                                        <input type="number" class="form-control" name="max_concurrent_trades" value="<?= s('max_concurrent_trades','3') ?>" min="1" max="10">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label text-muted small">Min. Fırsat Skoru</label>
                                        <input type="number" class="form-control" name="min_opportunity_score" value="<?= s('min_opportunity_score','25') ?>" min="0" max="100">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label text-muted small">Bot Aralığı (sn)</label>
                                        <input type="number" class="form-control" name="bot_interval_sec" value="<?= s('bot_interval_sec','60') ?>" min="30">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label text-muted small">İşlem Büyüklüğü (%)</label>
                                        <input type="number" class="form-control" name="trade_size_pct" value="<?= s('trade_size_pct','20') ?>" min="1" max="100">
                                    </div>
                                </div>
                                <div class="form-check form-switch mt-3">
                                    <input class="form-check-input" type="checkbox" name="auto_pair_select" id="autoSelect" value="1" <?= s('auto_pair_select','1')==='1' ? 'checked' : '' ?>>
                                    <label class="form-check-label text-muted" for="autoSelect">Otomatik Parite Seçimi</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Risk Yönetimi -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header"><i class="bi bi-shield-half me-1"></i>Risk Yönetimi</div>
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
                                <?php
                                $wItems = [
                                    'weight_technical'   => ['Teknik Analiz', 35],
                                    'weight_social'      => ['Sosyal Medya',  20],
                                    'weight_news'        => ['Haberler',      20],
                                    'weight_manipulation'=> ['Manipülasyon/P&D', 25],
                                ];
                                foreach ($wItems as $wk => [$wl, $wd]): ?>
                                    <div class="mb-2">
                                        <div class="d-flex justify-content-between">
                                            <label class="form-label text-muted small mb-1"><?= $wl ?></label>
                                            <small class="text-muted" id="lbl_<?= $wk ?>"><?= s($wk,(string)$wd) ?>%</small>
                                        </div>
                                        <input type="range" class="form-range weight-range" name="<?= $wk ?>"
                                               min="0" max="100" value="<?= s($wk,(string)$wd) ?>"
                                               oninput="updateWeightLabel('<?= $wk ?>',this.value)">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary px-5">
                            <i class="bi bi-save me-1"></i>Kaydet
                        </button>
                        <span id="saveStatus" class="ms-3 small"></span>
                    </div>
                </div>
            </form>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const state = {
    running:     false,
    intervalId:  null,
    countdownId: null,
    countdown:   0,
    intervalSec: <?= si('bot_interval_sec', 60) ?>,
};

// ─── Bot Toggle ───────────────────────────────────────────────────────────────
document.getElementById('btnBot').addEventListener('click', () => {
    state.running ? stopBot() : startBot();
});

function startBot() {
    state.intervalSec = parseInt(document.querySelector('[name=bot_interval_sec]')?.value || 60);
    state.running = true;
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
    const badge = document.getElementById('botBadge');
    const btn   = document.getElementById('btnBot');
    if (state.running) {
        badge.className = 'badge rounded-pill px-3 py-2 running';
        badge.innerHTML = '<i class="bi bi-circle-fill me-1 bot-pulse" style="font-size:7px"></i>ÇALIŞIYOR';
        btn.innerHTML   = '<i class="bi bi-stop-fill me-1"></i>Durdur';
        btn.className   = 'btn btn-sm btn-outline-danger';
    } else {
        badge.className = 'badge rounded-pill px-3 py-2 stopped';
        badge.innerHTML = '<i class="bi bi-circle-fill me-1" style="font-size:7px"></i>DURDU';
        btn.innerHTML   = '<i class="bi bi-play-fill me-1"></i>Başlat';
        btn.className   = 'btn btn-sm btn-outline-light';
    }
}

function startCountdown() {
    clearInterval(state.countdownId);
    state.countdown = state.intervalSec;
    state.countdownId = setInterval(() => {
        state.countdown--;
        if (state.running && state.countdown > 0) {
            document.getElementById('botBadge').innerHTML =
                `<i class="bi bi-circle-fill me-1 bot-pulse" style="font-size:7px"></i>ÇALIŞIYOR <small class="opacity-75">${state.countdown}s</small>`;
        }
        if (state.countdown <= 0) state.countdown = state.intervalSec;
    }, 1000);
}

// ─── Bot Cycle ────────────────────────────────────────────────────────────────
async function runBotCycle() {
    hideBotError();
    try {
        const resp = await fetch('bot_engine.php?action=run');
        let data;
        try { data = await resp.json(); }
        catch { showBotError('Sunucu yanıtı JSON değil: ' + (await resp.text().catch(() => ''))); return; }

        if (!data.success) { showBotError(data.error || 'Hata'); return; }

        document.getElementById('lastRunTime').textContent = new Date().toLocaleTimeString('tr-TR');

        if (data.balance !== undefined)
            document.getElementById('statBalance').textContent =
                '$' + Number(data.balance).toLocaleString('tr-TR', {minimumFractionDigits:2});
        if (data.open_count !== undefined)
            document.getElementById('statOpenCount').textContent = data.open_count;

        renderAnalyses(data.analyses || []);
        renderScanTable(data.scan || []);
        refreshOpenTrades();
        refreshRecentLogs();
        refreshStats();

    } catch(e) { showBotError('Bağlantı hatası: ' + e.message); }
}

function showBotError(msg) {
    document.getElementById('botErrorMsg').textContent = msg;
    document.getElementById('botErrorAlert').classList.remove('d-none');
}
function hideBotError() {
    document.getElementById('botErrorAlert').classList.add('d-none');
}

// ─── Analizleri Göster ────────────────────────────────────────────────────────
function renderAnalyses(analyses) {
    const cont = document.getElementById('analysesContainer');
    if (!analyses.length) {
        cont.innerHTML = '<div class="p-3 text-muted text-center">Bu döngüde analiz edilecek uygun parite bulunamadı.</div>';
        return;
    }
    let html = '<div class="row g-3 p-3">';
    for (const a of analyses) {
        if (a.error) {
            html += `<div class="col-md-6"><div class="card p-3"><span class="text-danger">${a.pair}: ${a.error}</span></div></div>`;
            continue;
        }
        const dec   = (a.decision || 'HOLD').toLowerCase();
        const chg   = parseFloat(a.change_pct || 0);
        const chgCl = chg >= 0 ? 'pnl+' : 'pnl-';
        html += `
        <div class="col-md-6 col-lg-4">
          <div class="card p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div>
                <span class="badge" style="background:#6c63ff22;color:#6c63ff;font-size:.85rem">${a.pair}</span>
                <span class="badge badge-${dec} ms-1">${a.decision}</span>
              </div>
              <small class="text-muted">${a.price_source || ''}</small>
            </div>
            <div class="d-flex gap-3 mb-2">
              <div><div class="text-muted" style="font-size:.7rem">Fiyat</div>
                   <div class="fw-bold">$${fmtPrice(a.price)}</div></div>
              <div><div class="text-muted" style="font-size:.7rem">24s</div>
                   <div class="fw-bold ${chgCl}">${chg>=0?'+':''}${chg.toFixed(2)}%</div></div>
              <div><div class="text-muted" style="font-size:.7rem">Güven</div>
                   <div class="fw-bold">${a.confidence}%</div></div>
            </div>
            <!-- Göstergeler -->
            <div class="mb-1">
              <div class="d-flex justify-content-between mb-1" style="font-size:.7rem">
                <span class="text-muted">Manip. Risk</span><span>${a.manipulation_risk}/100</span>
              </div>
              <div class="progress" style="height:5px;background:#1a1d23">
                <div class="progress-bar bg-warning" style="width:${a.manipulation_risk}%"></div>
              </div>
            </div>
            <div class="mb-2">
              <div class="d-flex justify-content-between mb-1" style="font-size:.7rem">
                <span class="text-muted">P&D Risk</span><span>${a.pump_and_dump_risk||0}/100</span>
              </div>
              <div class="progress" style="height:5px;background:#1a1d23">
                <div class="progress-bar" style="width:${a.pump_and_dump_risk||0}%;background:linear-gradient(90deg,#00d4aa,#ffa837,#ff4d6d)"></div>
              </div>
            </div>
            <p class="text-muted mb-0" style="font-size:.78rem;line-height:1.5">${a.reason || ''}</p>
            ${a.trade ? `<div class="mt-2 alert alert-${a.trade.action==='BUY'?'success':'danger'} py-1 px-2 mb-0" style="font-size:.75rem">
              <strong>${a.trade.action}</strong> @ $${fmtPrice(a.trade.price)}
              ${a.trade.pnl !== undefined ? ` → K/Z: ${a.trade.pnl>=0?'+':''}$${a.trade.pnl?.toFixed(2)}` : ''}
            </div>` : ''}
          </div>
        </div>`;
    }
    html += '</div>';
    cont.innerHTML = html;
}

// ─── Tarama Tablosu ──────────────────────────────────────────────────────────
function renderScanTable(scan) {
    if (!scan.length) return;
    const cont = document.getElementById('scannerContainer');

    let html = `<div class="table-responsive">
    <table class="table table-sm table-hover mb-0">
      <thead><tr>
        <th>Parite</th><th>Fiyat</th><th>1s</th><th>24s</th><th>Hacim</th>
        <th>P&D Skoru</th><th>Fırsat Skoru</th>
      </tr></thead><tbody>`;

    for (const r of scan) {
        const c1   = parseFloat(r.change_1h  || 0);
        const c24  = parseFloat(r.change_24h || 0);
        const rowCl = r.pump_score > 60 ? 'scan-row-pump' : (r.opportunity_score > 60 ? 'scan-row-opp' : '');
        html += `<tr class="${rowCl}">
          <td><strong>${r.pair}</strong>
            ${r.pump_score > 60 ? '<span class="badge bg-danger pump-badge ms-1">PUMP</span>' : ''}
            ${r.opportunity_score > 60 ? '<span class="badge bg-success pump-badge ms-1">HOT</span>' : ''}
          </td>
          <td>$${fmtPrice(r.price)}</td>
          <td class="${c1>=0?'pnl+':'pnl-'}">${c1>=0?'+':''}${c1.toFixed(2)}%</td>
          <td class="${c24>=0?'pnl+':'pnl-'}">${c24>=0?'+':''}${c24.toFixed(2)}%</td>
          <td>$${fmtVol(r.volume_24h)}</td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="progress flex-grow-1" style="height:6px">
                <div class="progress-bar bg-danger" style="width:${r.pump_score}%"></div>
              </div>
              <small>${r.pump_score}</small>
            </div>
          </td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="progress flex-grow-1" style="height:6px">
                <div class="progress-bar bg-success" style="width:${r.opportunity_score}%"></div>
              </div>
              <small>${r.opportunity_score}</small>
            </div>
          </td>
        </tr>`;
    }
    html += '</tbody></table></div>';
    cont.innerHTML = html;
}

async function runManualScan() {
    document.getElementById('scannerContainer').innerHTML = '<div class="p-4 text-center text-muted"><i class="bi bi-hourglass-split me-2"></i>Taranıyor...</div>';
    try {
        const resp = await fetch('bot_engine.php?action=scan_pairs');
        const data = await resp.json();
        if (data.success) renderScanTable(data.scan || []);
        else document.getElementById('scannerContainer').innerHTML = `<div class="p-4 text-danger">${data.error}</div>`;
    } catch(e) {
        document.getElementById('scannerContainer').innerHTML = `<div class="p-4 text-danger">Hata: ${e.message}</div>`;
    }
}

// ─── Açık İşlemler ───────────────────────────────────────────────────────────
async function refreshOpenTrades() {
    const resp = await fetch('bot_engine.php?action=get_trades&status=open');
    const data = await resp.json();
    const cont = document.getElementById('openTradesContainer');

    if (!data.trades?.length) {
        cont.innerHTML = '<div class="p-4 text-muted text-center">Açık işlem yok</div>';
        return;
    }

    let html = '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr>'
        + '<th>Parite</th><th>Giriş</th><th>Miktar</th><th>Tarih</th><th></th></tr></thead><tbody>';
    for (const t of data.trades) {
        html += `<tr>
          <td><span class="badge" style="background:#6c63ff22;color:#6c63ff">${t.pair}</span></td>
          <td>$${fmtPrice(t.entry_price)}</td>
          <td>${parseFloat(t.quantity).toFixed(6)}</td>
          <td><small class="text-muted">${new Date(t.created_at+'Z').toLocaleString('tr-TR')}</small></td>
          <td><button class="btn btn-outline-danger py-0 px-2" style="font-size:.75rem"
              onclick="manualClose(${t.id})">Kapat</button></td>
        </tr>`;
    }
    html += '</tbody></table></div>';
    cont.innerHTML = html;
}

// ─── Son Loglar ───────────────────────────────────────────────────────────────
async function refreshRecentLogs() {
    const resp = await fetch('bot_engine.php?action=get_logs&limit=5');
    const data = await resp.json();
    const cont = document.getElementById('recentLogsContainer');
    if (!data.logs?.length) { cont.innerHTML = '<div class="p-3 text-muted text-center">Kayıt yok</div>'; return; }

    cont.innerHTML = data.logs.map(l => {
        const dc = l.decision.toLowerCase();
        return `<div class="log-item ${dc} px-3 py-2 border-bottom">
          <div class="d-flex justify-content-between">
            <div><span class="badge badge-${dc} me-1">${l.decision}</span>
                 <small class="text-muted">${l.pair}</small>
                 <small class="ms-2 text-muted">G:${l.confidence}% M:${l.manipulation_risk}%</small></div>
            <small class="text-muted">${new Date(l.created_at+'Z').toLocaleTimeString('tr-TR')}</small>
          </div>
          <small class="text-secondary">${l.reason || ''}</small>
        </div>`;
    }).join('');
}

// ─── İstatistikler ────────────────────────────────────────────────────────────
async function refreshStats() {
    const resp = await fetch('bot_engine.php?action=status');
    const data = await resp.json();
    if (!data.success) return;

    document.getElementById('statBalance').textContent =
        '$' + Number(data.balance).toLocaleString('tr-TR', {minimumFractionDigits:2});
    document.getElementById('statOpenCount').textContent = data.open_trades?.length || 0;

    if (data.price?.current > 0) {
        const pr  = data.price;
        const str = '$' + Number(pr.current).toLocaleString('tr-TR', {minimumFractionDigits:2});
        document.getElementById('priceDisplay').textContent = str;
    }
    const s = data.stats;
    if (s) {
        const pnl = parseFloat(s.total_pnl) || 0;
        const el  = document.getElementById('statPnl');
        el.textContent = (pnl >= 0 ? '+' : '') + '$' + Math.abs(pnl).toLocaleString('tr-TR', {minimumFractionDigits:2});
        el.className   = 'fs-4 fw-bold ' + (pnl >= 0 ? 'pnl+' : 'pnl-');
        const total = (parseInt(s.wins)||0) + (parseInt(s.losses)||0);
        document.getElementById('statWin').textContent = total > 0 ? Math.round(s.wins/total*100)+'%' : '—';
    }
}

async function fetchPrice() {
    const pair = (document.querySelector('[name=available_pairs]')?.value?.split(',')[0] || 'BTCUSDT').trim();
    try {
        const resp = await fetch(`bot_engine.php?action=get_price&pair=${pair}`);
        const data = await resp.json();
        if (data.success && data.price?.current > 0)
            document.getElementById('priceDisplay').textContent =
                '$' + Number(data.price.current).toLocaleString('tr-TR', {minimumFractionDigits:2});
    } catch {}
}

// ─── İşlemler Tab ─────────────────────────────────────────────────────────────
async function loadTrades(status = 'all', btn = null) {
    if (btn) { document.querySelectorAll('[id=tabTrades] .btn-group .btn, .btn-group .btn').forEach(b => b.classList.remove('active')); btn.classList.add('active'); }
    const resp = await fetch(`bot_engine.php?action=get_trades&status=${status}`);
    const data = await resp.json();
    const cont = document.getElementById('tradesTableContainer');
    if (!data.trades?.length) { cont.innerHTML = '<div class="p-5 text-center text-muted">İşlem yok</div>'; return; }

    let html = `<div class="table-responsive"><table class="table table-hover mb-0">
      <thead><tr><th>#</th><th>Parite</th><th>Tür</th><th>Giriş</th><th>Çıkış</th><th>Miktar</th><th>K/Z</th><th>Durum</th><th>Tarih</th></tr></thead><tbody>`;

    for (const t of data.trades) {
        const pnl    = parseFloat(t.pnl) || 0;
        const pnlTxt = t.pnl !== null ? `${pnl>=0?'+':''}$${Math.abs(pnl).toFixed(2)}` : '—';
        html += `<tr>
          <td class="text-muted">#${t.id}</td>
          <td><span class="badge" style="background:#6c63ff22;color:#6c63ff">${t.pair}</span></td>
          <td><span class="badge badge-${t.type.toLowerCase()}">${t.type}</span></td>
          <td>$${fmtPrice(t.entry_price)}</td>
          <td>${t.exit_price ? '$'+fmtPrice(t.exit_price) : '—'}</td>
          <td>${parseFloat(t.quantity).toFixed(6)}</td>
          <td class="${pnl>=0?'pnl+':'pnl-'} fw-bold">${pnlTxt}</td>
          <td>${t.status === 'open'
               ? '<span class="badge bg-success bg-opacity-25 text-success">Açık</span>'
               : '<span class="badge bg-secondary bg-opacity-25 text-secondary">Kapandı</span>'}</td>
          <td><small class="text-muted">${new Date(t.created_at+'Z').toLocaleString('tr-TR')}</small></td>
        </tr>`;
    }
    html += '</tbody></table></div>';
    cont.innerHTML = html;
}

// ─── Loglar Tab ───────────────────────────────────────────────────────────────
async function loadLogs() {
    const resp = await fetch('bot_engine.php?action=get_logs&limit=50');
    const data = await resp.json();
    const cont = document.getElementById('logsContainer');
    if (!data.logs?.length) { cont.innerHTML = '<div class="p-5 text-center text-muted">Log yok</div>'; return; }

    cont.innerHTML = data.logs.map(l => {
        const dc = l.decision.toLowerCase();
        return `<div class="log-item ${dc} px-4 py-3 border-bottom">
          <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div class="d-flex gap-2 flex-wrap">
              <span class="badge badge-${dc} px-3">${l.decision}</span>
              <span class="badge bg-dark border" style="border-color:var(--border)!important">${l.pair}</span>
              <span class="text-muted small">Güven: <strong>${l.confidence}%</strong></span>
              <span class="text-muted small">Manip: <strong>${l.manipulation_risk}%</strong></span>
            </div>
            <small class="text-muted">${new Date(l.created_at+'Z').toLocaleString('tr-TR')}</small>
          </div>
          <p class="mb-0 mt-2 text-secondary small">${l.reason || ''}</p>
        </div>`;
    }).join('');
}

// ─── Manuel Kapat ─────────────────────────────────────────────────────────────
async function manualClose(id) {
    const fd = new FormData();
    fd.append('action', 'close_trade');
    fd.append('trade_id', id);
    const resp = await fetch('bot_engine.php', { method:'POST', body:fd });
    const data = await resp.json();
    if (data.success) { refreshOpenTrades(); refreshStats(); loadTrades('all'); }
    else alert('Hata: ' + data.error);
}

// ─── API Test ─────────────────────────────────────────────────────────────────
async function testApi(api, inputId, resultId) {
    const el  = document.getElementById(resultId);
    const key = document.getElementById(inputId)?.value.trim() || '';
    el.innerHTML = '<span class="text-muted small"><i class="bi bi-hourglass-split me-1"></i>Test ediliyor...</span>';
    const fd = new FormData();
    fd.append('action','test_api'); fd.append('api',api); fd.append('key',key);
    try {
        const resp = await fetch('bot_engine.php', { method:'POST', body:fd });
        let data;
        try { data = await resp.json(); }
        catch { el.innerHTML = `<span class="text-danger small">Yanıt: ${(await resp.text().catch(()=>'')).substring(0,100)}</span>`; return; }
        el.innerHTML = data.success
            ? `<span class="text-success small"><i class="bi bi-check-circle me-1"></i>${data.message}</span>`
            : `<span class="text-danger small"><i class="bi bi-x-circle me-1"></i>${data.error}</span>`;
    } catch(e) { el.innerHTML = `<span class="text-danger small">İstek başarısız: ${e.message}</span>`; }
}

// ─── Ayarlar ──────────────────────────────────────────────────────────────────
document.getElementById('settingsForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    // Checkbox
    if (!this.querySelector('[name=auto_pair_select]').checked) fd.set('auto_pair_select', '0');
    fd.append('action','save_settings');
    const st = document.getElementById('saveStatus');
    st.textContent = 'Kaydediliyor...'; st.className = 'ms-3 small text-muted';
    const resp = await fetch('bot_engine.php', { method:'POST', body:fd });
    const data = await resp.json();
    st.textContent = data.success ? 'Kaydedildi!' : 'Hata!';
    st.className   = `ms-3 small ${data.success?'text-success':'text-danger'}`;
    setTimeout(() => { st.textContent = ''; }, 3000);
});

function updateWeightLabel(k, v) {
    document.getElementById('lbl_'+k).textContent = v + '%';
}

// ─── Tab eventleri ────────────────────────────────────────────────────────────
document.querySelector('[href="#tabTrades"]').addEventListener('click', () => loadTrades('all'));
document.querySelector('[href="#tabLogs"]').addEventListener('click', () => loadLogs);
document.querySelector('[href="#tabScanner"]').addEventListener('click', () => runManualScan());

// ─── Yardımcılar ──────────────────────────────────────────────────────────────
function fmtPrice(v) {
    v = parseFloat(v) || 0;
    if (v >= 1000) return v.toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2});
    if (v >= 1)    return v.toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:4});
    return v.toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:8});
}
function fmtVol(v) {
    v = parseFloat(v) || 0;
    if (v >= 1e9) return (v/1e9).toFixed(1) + 'B';
    if (v >= 1e6) return (v/1e6).toFixed(1) + 'M';
    if (v >= 1e3) return (v/1e3).toFixed(1) + 'K';
    return v.toFixed(0);
}

// ─── Init ─────────────────────────────────────────────────────────────────────
refreshStats();
refreshOpenTrades();
refreshRecentLogs();
fetchPrice();
setInterval(fetchPrice, 30000);
loadTrades('all');
</script>
</body>
</html>
