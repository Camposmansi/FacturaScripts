<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Controller;

use FacturaScripts\Core\Template\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\Auralis\Lib\AuralisChangelogReader;
use FacturaScripts\Plugins\Auralis\Lib\AuralisTwigFunctions;
use FacturaScripts\Plugins\Auralis\Lib\Traits\AuralisAjaxCsrfSeedTrait;

/** Endpoint ligero de las novedades de Auralis. Las alertas de agenda viven en AuralisAgenda. */
class AuralisNotificationsAjax extends Controller
{
    use AuralisAjaxCsrfSeedTrait;

    public function getPageData(): array
    {
        AuralisTwigFunctions::register();
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['submenu'] = '';
        $data['title'] = 'auralis-inapp-notifications';
        $data['icon'] = 'fa-solid fa-bell';
        $data['showonmenu'] = false;
        return $data;
    }

    public function run(): void
    {
        parent::run();
        $action = (string)$this->request()->query('action', '') ?: (string)$this->request()->input('action', '');
        $nick = (string)($this->user->nick ?? '');
        $this->ensureAjaxCsrfSeed();

        if ($action === 'ping' || $action === 'load') {
            $this->json([
                'ok' => true,
                'can_calendar' => false,
                'count' => 0,
                'has_unread_news' => AuralisChangelogReader::hasUnreadNews($nick),
                'next_alarm_seconds' => null,
                'items' => [],
                'popups' => [],
                'changelog' => AuralisChangelogReader::entries(),
                'pollSeconds' => 90,
                'snoozeMinutes' => [],
                'enabled' => true,
                'push' => ['enabled' => false],
            ]);
            return;
        }

        if ($action === 'changelog') {
            $all = (string)$this->request()->inputOrQuery('all', '') === '1';
            $this->json(['ok' => true, 'entries' => $all ? AuralisChangelogReader::allEntries() : AuralisChangelogReader::entries()]);
            return;
        }

        if ($action === 'mark-news-seen' && $this->validateFormToken()) {
            $this->json([
                'ok' => AuralisChangelogReader::markNewsSeen($nick),
                'has_unread_news' => false,
                'last_seen_version' => AuralisChangelogReader::latestVersion(),
                'last_seen_news_cursor' => AuralisChangelogReader::latestCursor(),
            ]);
            return;
        }

        $this->json(['ok' => false, 'message' => Tools::trans('invalid-request')], 400);
    }

    protected function validateFormToken(): bool
    {
        $this->ensureAjaxCsrfSeed();
        return parent::validateFormToken();
    }

    private function json(array $payload, int $status = 200): void
    {
        $payload['newToken'] = $this->ajaxCsrfNewToken();
        if ($status !== 200) {
            $this->response()->setHttpCode($status);
        }
        $this->response()->json($payload);
    }
}
