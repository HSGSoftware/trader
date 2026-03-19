<?php

declare(strict_types=1);

class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $path = dirname(__DIR__) . '/database.sqlite';
            self::$instance = new PDO('sqlite:' . $path);
            self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$instance->exec('PRAGMA journal_mode=WAL');
            self::migrate(self::$instance);
        }
        return self::$instance;
    }

    private static function migrate(PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS settings (
                key   TEXT PRIMARY KEY,
                value TEXT
            );

            CREATE TABLE IF NOT EXISTS trades (
                id                     INTEGER PRIMARY KEY AUTOINCREMENT,
                pair                   TEXT    NOT NULL,
                type                   TEXT    NOT NULL CHECK(type IN ('BUY','SELL')),
                entry_price            REAL    NOT NULL,
                exit_price             REAL,
                quantity               REAL    NOT NULL,
                pnl                    REAL,
                manipulation_risk_score INTEGER DEFAULT 0,
                status                 TEXT    NOT NULL DEFAULT 'open' CHECK(status IN ('open','closed')),
                ai_reason              TEXT,
                created_at             DATETIME DEFAULT CURRENT_TIMESTAMP,
                closed_at              DATETIME
            );

            CREATE TABLE IF NOT EXISTS logs (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                pair       TEXT,
                decision   TEXT,
                confidence INTEGER,
                manipulation_risk INTEGER,
                reason     TEXT,
                raw_data   TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $defaults = [
            'anthropic_api_key'    => '',
            'binance_api_key'      => '',
            'binance_api_secret'   => '',
            'cryptopanic_api_key'  => '',
            'lunarcrush_api_key'   => '',
            'active_pair'          => 'BTCUSDT',
            'available_pairs'      => 'BTCUSDT,ETHUSDT,BNBUSDT,SOLUSDT,XRPUSDT',
            'take_profit_pct'      => '5',
            'stop_loss_pct'        => '3',
            'weight_technical'     => '40',
            'weight_social'        => '20',
            'weight_news'          => '20',
            'weight_manipulation'  => '20',
            'ai_model'             => 'claude-sonnet-4-5',
            'virtual_balance'      => '10000',
            'trade_size_pct'       => '10',
            'bot_interval_sec'     => '60',
            'bot_running'          => '0',
        ];

        $stmt = $db->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (:k, :v)');
        foreach ($defaults as $k => $v) {
            $stmt->execute([':k' => $k, ':v' => $v]);
        }
    }

    public static function getSetting(string $key, mixed $default = null): mixed
    {
        $stmt = self::getInstance()->prepare('SELECT value FROM settings WHERE key = :k');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : $default;
    }

    public static function setSetting(string $key, mixed $value): void
    {
        $stmt = self::getInstance()->prepare(
            'INSERT INTO settings (key, value) VALUES (:k, :v)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute([':k' => $key, ':v' => $value]);
    }
}
