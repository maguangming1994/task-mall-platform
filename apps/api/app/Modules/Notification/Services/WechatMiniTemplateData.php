<?php

namespace App\Modules\Notification\Services;

use Illuminate\Validation\ValidationException;

class WechatMiniTemplateData
{
    private const KEY_PATTERN = '/^(thing|character_string|phrase|name|phone_number|amount|number|date|time|letter|symbol|car_number)\d+$/';

    /** @return array<string,string> */
    public function decode(string $body): array
    {
        $payload = json_decode($body, true);
        if (! is_array($payload) || $payload === [] || array_is_list($payload)) {
            throw ValidationException::withMessages(['body' => '微信订阅模板正文必须是非空的关键词 JSON 对象。']);
        }

        $result = [];
        foreach ($payload as $key => $value) {
            if (! is_string($key) || ! preg_match(self::KEY_PATTERN, $key)) {
                throw ValidationException::withMessages(['body' => '微信关键词必须使用 thing1、phrase2、time3 等合法格式。']);
            }
            $template = is_array($value) ? ($value['value'] ?? null) : $value;
            if (! is_string($template)) {
                throw ValidationException::withMessages(['body' => '微信订阅模板每个关键词的内容必须是字符串。']);
            }
            $result[$key] = $template;
        }

        return $result;
    }

    public function isValid(string $body): bool
    {
        try {
            $this->decode($body);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /** @param array<string,string> $variables
     * @return array<string,array{value:string}>
     */
    public function render(string $body, array $variables): array
    {
        $replace = collect($variables)
            ->mapWithKeys(fn (string $value, string $key): array => ['{{'.$key.'}}' => $value])
            ->all();

        return collect($this->decode($body))->mapWithKeys(function (string $template, string $key) use ($replace): array {
            $value = preg_replace('/\s+/u', ' ', trim(strtr($template, $replace))) ?? '';

            return [$key => ['value' => rtrim(mb_substr($value, 0, $this->maximumLength($key)))]];
        })->all();
    }

    private function maximumLength(string $key): int
    {
        return match (true) {
            str_starts_with($key, 'phrase'), str_starts_with($key, 'symbol') => 5,
            str_starts_with($key, 'car_number') => 8,
            str_starts_with($key, 'name') => 10,
            str_starts_with($key, 'amount') => 12,
            str_starts_with($key, 'phone_number') => 17,
            str_starts_with($key, 'thing') => 20,
            default => 32,
        };
    }
}
