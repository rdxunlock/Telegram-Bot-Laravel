<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $chat_id
 * @property int $mid
 */
class TBilibiliSubscribes extends BaseModel
{
    protected $table = 'bilibili_subscribes';

    /**
     * @param int $chat_id
     * @param int $mid
     * @return Builder|Model|false
     */
    public static function addSubscribe(int $chat_id, int $mid): Builder|Model|false
    {
        $data = Cache::get("DB::TBilibiliSubscribes::bilibili_subscribes::$chat_id");
        if (is_array($data)) {
            foreach ($data as $item) {
                if ($item['mid'] === $mid) {
                    return false;
                }
            }
        }
        $data = self::query()
            ->select('id')
            ->where([
                'chat_id' => $chat_id,
                'mid' => $mid,
            ])
            ->first();
        if ($data == null) {
            $data = self::query()
                ->create([
                    'chat_id' => $chat_id,
                    'mid' => $mid,
                ]);
            Cache::forget("DB::TBilibiliSubscribes::bilibili_subscribes");
            Cache::forget("DB::TBilibiliSubscribes::bilibili_subscribes::$chat_id");
            return $data;
        }
        return false;
    }

    /**
     * @param int $chat_id
     * @return mixed
     */
    public static function removeAllSubscribe(int $chat_id): int
    {
        $int = self::query()
            ->where('chat_id', $chat_id)
            ->delete();
        Cache::forget("DB::TBilibiliSubscribes::bilibili_subscribes");
        Cache::forget("DB::TBilibiliSubscribes::bilibili_subscribes::$chat_id");
        return $int;
    }

    /**
     * @param int $chat_id
     * @param int $mid
     * @return int
     */
    public static function removeSubscribe(int $chat_id, int $mid): int
    {
        $int = self::query()
            ->where([
                'chat_id' => $chat_id,
                'mid' => $mid,
            ])
            ->delete();
        Cache::forget("DB::TBilibiliSubscribes::bilibili_subscribes");
        Cache::forget("DB::TBilibiliSubscribes::bilibili_subscribes::$chat_id");
        return $int;
    }

    /**
     * @return array
     */
    public static function getAllSubscribe(): array
    {
        $data = Cache::get("DB::TBilibiliSubscribes::bilibili_subscribes");
        if (is_array($data)) {
            return $data;
        }
        $data = self::query()
            ->select('chat_id', 'mid')
            ->get()
            ->toArray();
        Cache::put("DB::TBilibiliSubscribes::bilibili_subscribes", $data, Carbon::now()->addMinutes(5));
        return $data;
    }

    /**
     * @param int $chatId
     * @return array
     */
    public static function getAllSubscribeByChat(int $chatId): array
    {
        $data = Cache::get("DB::TBilibiliSubscribes::bilibili_subscribes::$chatId");
        if (is_array($data)) {
            return $data;
        }
        $data = self::query()
            ->select('mid')
            ->where('chat_id', $chatId)
            ->get()
            ->toArray();
        Cache::put("DB::TBilibiliSubscribes::bilibili_subscribes::$chatId", $data, Carbon::now()->addMinutes(5));
        return $data;
    }
}
