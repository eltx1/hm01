<?php

namespace App\Services\MobileApps;

use Illuminate\Validation\ValidationException;

final class AppStoreUrl
{
    public function parse(string $url, ?string $package = null): array
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user'], $parts['pass'])
            || isset($parts['user']) || isset($parts['port']) || preg_match('/[\s\\\\]/', $url)) $this->invalid();
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);
        $platform = 'android'; $id = $package;
        if ($host === 'play.google.com' && $path === '/store/apps/details') {
            $store = 'google_play'; $id = $query['id'] ?? null;
            $url = 'https://play.google.com/store/apps/details?id='.(is_string($id) ? $id : '');
        } elseif ($host === 'apps.apple.com' && preg_match('#/id([0-9]+)$#D', $path, $match)) {
            $store = 'apple'; $platform = 'ios'; $id = $match[1]; $url = 'https://apps.apple.com/app/id'.$id;
        } elseif (in_array($host, ['galaxystore.samsung.com', 'apps.samsung.com'], true) && preg_match('#^/(?:detail|appquery/appDetail\.as)/?([^/]*)$#', $path, $match)) {
            $store = 'samsung'; $id = $query['appId'] ?? ($match[1] ?: $package);
        } elseif (in_array($host, ['www.amazon.com', 'amazon.com'], true) && preg_match('#/(?:dp|gp/product)/[A-Z0-9]{10}(?:/|$)#', $path)) {
            $store = 'amazon';
        } elseif (in_array($host, ['store.oppomobile.com', 'app.market.xiaomi.com', 'global.app.mi.com', 'h5.appstore.vivo.com.cn', 'appstore.vivo.com.cn'], true) && (($path !== '' && $path !== '/') || preg_match('#^/appDetail/[0-9]+#i', $parts['fragment'] ?? ''))) {
            $store = match ($host) { 'store.oppomobile.com' => 'oppo', 'app.market.xiaomi.com', 'global.app.mi.com' => 'xiaomi', default => 'vivo' };
        } else $this->invalid();
        if (! is_string($id) || strlen($id) > 255 || ($platform === 'android'
                ? ! preg_match('/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+$/D', $id)
                : ! preg_match('/^[1-9][0-9]{1,19}$/D', $id))) {
            throw ValidationException::withMessages(['package_name' => 'Enter the exact Android package name (for example com.company.app) for this store. Apple apps use their numeric store ID.']);
        }
        return ['store' => $store, 'platform' => $platform, 'store_app_id' => $id, 'store_url' => $url];
    }

    public function domain(string $value): string
    {
        $domain = strtolower(rtrim(trim($value), '.'));
        if (strlen($domain) > 253 || ! filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || ! str_contains($domain, '.') || filter_var($domain, FILTER_VALIDATE_IP)
            || preg_match('/\.(?:localhost|local|internal|invalid|test)$/D', $domain)) {
            throw ValidationException::withMessages(['developer_domain' => 'Enter the public root domain of the developer website listed in the store, without a URL path.']);
        }
        return $domain;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['store_url' => 'Use a public HTTPS app listing from a supported mobile app store.']);
    }
}
