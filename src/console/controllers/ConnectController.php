<?php

namespace justinholtweb\exactly\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\exactly\Plugin;
use yii\console\ExitCode;

/**
 * Inspect the Exact Online connection from the command line.
 *
 * Plugin console commands are not reachable through `craft help exactly` — they are listed under
 * plain `craft help` and run as `exactly/connect/status`.
 */
class ConnectController extends Controller
{
    /**
     * Show what Exactly is connected to, and how much of the call budget is left.
     */
    public function actionStatus(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $connection = $plugin->getOauth()->getConnection();

        $this->stdout("Exact Online connection\n", Console::BOLD);
        $this->stdout(str_repeat('-', 52) . "\n");

        $this->row('Region', $settings->getBaseUrl());
        $this->row('Client ID', $settings->getParsedClientId() !== '' ? 'configured' : 'MISSING');
        $this->row('Redirect URI', $settings->getRedirectUri());

        if ($connection === null) {
            $this->stdout("\nNot connected. Connect in the control panel, under Settings → Plugins → Exactly.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->row('Connected as', $connection->describe() ?: '(unknown)');
        $this->row('Division', (string)($plugin->getOauth()->getDivision() ?? '(none selected)'));
        $this->row('Access token', $connection->accessTokenIsUsable() ? 'valid' : 'expired (will refresh)');

        $days = $connection->getDaysUntilExpiry();

        if ($days !== null) {
            $this->row('Reconnect within', $days . ' days', $days < 5 ? Console::FG_RED : Console::FG_GREEN);
        }

        if ($connection->minutelyLimit !== null) {
            $this->row('Calls this minute', ($connection->minutelyRemaining ?? '?') . ' / ' . $connection->minutelyLimit . ' left');
        }

        if ($connection->dailyLimit !== null) {
            $this->row('Calls today', ($connection->dailyRemaining ?? '?') . ' / ' . $connection->dailyLimit . ' left');
        }

        $this->stdout("\n");

        $status = $plugin->getDivisions()->getStatus();

        $this->stdout($status['message'] . "\n", $status['connected'] ? Console::FG_GREEN : Console::FG_RED);

        return $status['connected'] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * List the administrations this connection can write to.
     */
    public function actionDivisions(): int
    {
        $divisions = Plugin::getInstance()->getDivisions()->getDivisions();

        if (!$divisions) {
            $this->stderr("No administrations came back. Is Exactly connected?\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $current = Plugin::getInstance()->getOauth()->getDivision();

        foreach ($divisions as $division) {
            $marker = $division['code'] === $current ? ' *' : '  ';
            $this->stdout(sprintf(
                "%s %-10s %-40s %s %s\n",
                $marker,
                $division['code'],
                mb_substr($division['name'], 0, 40),
                $division['currency'],
                $division['country'],
            ));
        }

        $this->stdout("\n* is the division currently in use.\n", Console::FG_GREY);

        return ExitCode::OK;
    }

    /**
     * Check a VAT number against the EU VIES service.
     */
    public function actionCheckVat(string $vatNumber): int
    {
        $result = Plugin::getInstance()->getVat()->validateWithVies($vatNumber);

        $this->stdout(match ($result) {
            true => "Registered.\n",
            false => "Not registered.\n",
            default => "VIES could not be reached.\n",
        }, match ($result) {
            true => Console::FG_GREEN,
            false => Console::FG_RED,
            default => Console::FG_YELLOW,
        });

        return $result === true ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    private function row(string $label, string $value, ?int $color = null): void
    {
        $this->stdout(str_pad($label, 20));
        $this->stdout($value . "\n", ...($color !== null ? [$color] : []));
    }
}
