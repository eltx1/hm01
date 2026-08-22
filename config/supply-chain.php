<?php

return [
    'manager_domain' => env('SUPPLY_CHAIN_MANAGER_DOMAIN', 'horusmedia.net'),
    'contact_email' => env('SUPPLY_CHAIN_CONTACT_EMAIL', 'adops@horusmedia.net'),
    'contact_address' => env('SUPPLY_CHAIN_CONTACT_ADDRESS', 'Horus Media LLC, 30 N Gould St Ste N, Sheridan, WY 82801, US'),
    'tag_id' => env('SUPPLY_CHAIN_TAG_ID'),
    'managed_ads_txt_base_url' => rtrim(env('SUPPLY_CHAIN_MANAGED_ADS_TXT_BASE_URL', 'https://cdn.horusmedia.net'), '/'),
    'canonical_sellers_json_url' => env('SUPPLY_CHAIN_CANONICAL_SELLERS_JSON_URL', 'https://horusmedia.net/sellers.json'),
    'sellers_json_proxy_target' => env('SUPPLY_CHAIN_SELLERS_JSON_PROXY_TARGET', 'https://cdn.horusmedia.net/sellers.json'),
];
