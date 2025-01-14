<?php

namespace App\Console\Schedule\WellKnownSoftwareUpdateSubscribe\Softwares;

use App\Common\Config;
use App\Console\Schedule\WellKnownSoftwareUpdateSubscribe\Common;
use App\Console\Schedule\WellKnownSoftwareUpdateSubscribe\Software;
use App\Console\Schedule\WellKnownSoftwareUpdateSubscribe\SoftwareInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use JetBrains\PhpStorm\ArrayShape;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;

class PHP implements SoftwareInterface
{
    /**
     * @return string
     */
    public function getVersion(): string
    {
        $data = $this->getJson();
        if (!is_array($data)) {
            return Common::getLastVersion(Software::PHP);
        }
        $major = 0;
        $minor = 0;
        $patch = 0;
        foreach ($data as $branch) {
            if (preg_match('/^PHP-(\d+)\.(\d+)\.(\d+)$/i', $branch['name'], $matches)) {
                if ($matches[1] > $major) {
                    $major = $matches[1];
                    $minor = $matches[2];
                    $patch = $matches[3];
                } elseif ($matches[1] == $major && $matches[2] > $minor) {
                    $minor = $matches[2];
                    $patch = $matches[3];
                } elseif ($matches[1] == $major && $matches[2] == $minor && $matches[3] > $patch) {
                    $patch = $matches[3];
                }
            }
        }
        return "$major.$minor.$patch";
    }

    /**
     * @return array|int|false
     */
    private function getJson(): array|int|false
    {
        $headers = Config::CURL_HEADERS;
        $ts = Carbon::now()->getTimestamp();
        $headers['User-Agent'] .= " Telegram-PHP-Subscriber-Runner/$ts";
        $last_modified = Common::getLastModified(Software::PHP);
        if ($last_modified) {
            $headers['If-Modified-Since'] = $last_modified;
        }
        $get = Http::
        withHeaders($headers)
            ->accept('application/vnd.github+json')
            ->withToken(env('GITHUB_TOKEN'))
            ->get('https://api.github.com/repos/php/php-src/tags?per_page=100');
        $last_modified = $get->header('last-modified');
        Common::cacheLastModified(Software::PHP, $last_modified);
        if ($get->status() == 200) {
            return $get->json();
        }
        if ($get->status() == 304) {
            return 304;
        }
        return false;
    }

    /**
     * @param int $chat_id
     * @param string $version
     * @return array
     */
    #[ArrayShape([
        'chat_id' => 'int',
        'text' => 'string',
        'reply_markup' => InlineKeyboard::class,
    ])]
    public function generateMessage(int $chat_id, string $version): array
    {
        $emoji = Common::emoji();
        $message = [
            'chat_id' => $chat_id,
            'text' => "$emoji A new version of PHP($version) is now available.",
            'reply_markup' => new InlineKeyboard([]),
        ];
        $button1 = new InlineKeyboardButton([
            'text' => 'View',
            'url' => 'https://www.php.net/downloads.php',
        ]);
        $button2 = new InlineKeyboardButton([
            'text' => 'Download',
            'url' => "https://www.php.net/distributions/php-$version.tar.gz",
        ]);
        $button3 = new InlineKeyboardButton([
            'text' => 'What\'s NEW',
            'url' => "https://github.com/php/php-src/blob/php-$version/NEWS",
        ]);
        $message['reply_markup']->addRow($button1, $button2);
        $message['reply_markup']->addRow($button3);
        return $message;
    }
}
