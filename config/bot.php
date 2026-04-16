<?php

return [
    'style' => env('OPENAI_CARD_STYLE', 'wildberries'),
    'text_model' => env('OPENAI_TEXT_MODEL', 'gpt-4o-mini'),
    'vision_model' => env('OPENAI_VISION_MODEL', 'gpt-5-mini'),
    'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-5'),
    'image_generation_model' => env('OPENAI_IMAGE_GENERATION_MODEL', 'gpt-image-1'),
    'image_size' => env('OPENAI_IMAGE_SIZE', '1024x1024'),
    'image_quality' => env('OPENAI_IMAGE_QUALITY', 'high'),

    'prompt' => env('OPENAI_CARD_PROMPT', <<<PROMPT
Создай современную инфографическую карточку товара для маркетплейса на основе загруженного изображения товара.

Нужен чистый и продающий визуал, похожий на качественную карточку товара для Ozon / Wildberries, но БЕЗ цены, БЕЗ скидки, БЕЗ кнопки "Купить", БЕЗ CTA-элементов.

Требования:
- формат 1:1
- товар должен быть крупным и главным объектом композиции
- карточка должна выглядеть как качественная инфографика товара
- использовать аккуратные акцентные формы, плашки, подложки, иконки, графические элементы
- добавить заголовок товара
- добавить 3 коротких преимущества товара
- текст только на русском языке
- текст короткий, читаемый, коммерческий
- сильная визуальная иерархия
- современный e-commerce стиль
- композиция должна быть визуально насыщенной, но аккуратной
- фон чистый, современный, без визуального шума

Строго запрещено:
- не рисовать цену
- не рисовать цифры стоимости
- не рисовать старую цену
- не рисовать скидку или процент скидки
- не рисовать кнопку "Купить"
- не рисовать CTA-элементы
- не рисовать слова "Купить", "Заказать", "Акция", "Скидка", "Хит продаж"
- не делать шаблон "товар слева и текст справа"
- не делать пустой минимализм
- не делать стиль Apple / luxury / premium mockup
- не использовать английский язык
- не добавлять посторонние предметы
- не менять товар на другой
- не делать мелкий нечитаемый текст

Обязательно:
- сохранить узнаваемость исходного товара
- улучшить подачу товара
- сделать карточку визуально привлекательной
- выделить только название и преимущества
- карточка должна быть готова для дальнейшего наложения цены кодом, если потребуется позже
PROMPT),

    'style_prompts' => [
        'wildberries' => 'Стиль Wildberries: современная яркая карточка товара, мягкие градиенты, аккуратные акцентные элементы, 3 коротких преимущества, без цены, без скидок, без кнопок.',
        'ozon' => 'Стиль Ozon: яркая современная инфографика товара, крупный товар, чистый фон, выразительные плашки и формы, 3 коротких преимущества, без цены, без скидок, без кнопок и без CTA.',
        'minimal' => 'Чистая современная инфографика товара, крупный объект, минимум текста, 2-3 преимущества, без цены и без кнопок.',
        'techno' => 'Современная технологичная карточка товара, крупный товар, иконки и инфографика, выразительная композиция, без цены, без скидок и без кнопок.',
    ],

    'description_prompt' => env(
        'OPENAI_DESCRIPTION_PROMPT',
        'Сделай короткое продающее описание на русском для Telegram-поста. 2-3 короткие строки, без воды, без выдуманных характеристик, без цены если она не передана отдельно.'
    ),

    'enrichment_prompt' => env('OPENAI_ENRICHMENT_PROMPT', <<<PROMPT
Ты анализируешь фото товара и должен вернуть JSON для карточки маркетплейса.
Верни строго JSON без пояснений и без markdown.

Формат:
{
  "title": "краткое название товара на русском",
  "price": null,
  "features": ["преимущество 1", "преимущество 2", "преимущество 3"],
  "description": "1-2 короткие фразы для поста"
}

Правила:
- только русский язык
- кратко и по делу
- если не уверен, оставь поле null
- features должны быть короткими (2-4 слова)
- не придумывай цену
PROMPT),

    'wb_image_analysis_prompt' => env('OPENAI_WB_IMAGE_ANALYSIS_PROMPT', <<<PROMPT
Ты анализируешь фото товара для поиска на Wildberries.
Верни строго JSON без пояснений и без markdown.

Формат:
{
  "category": "",
  "brand": "",
  "model": "",
  "color": "",
  "visible_text": [],
  "key_features": []
}

Правила:
- Не придумывай модель, если она не читается на фото.
- Если виден бренд, укажи его.
- category должна быть обычным названием типа товара на русском, если его можно определить по изображению.
- visible_text: все читаемые надписи/логотипы.
- key_features: 3-6 коротких признаков (форма, материал, детали).
PROMPT),

    'wb_query_prompt' => env('OPENAI_WB_QUERY_PROMPT', <<<PROMPT
На основе атрибутов товара сгенерируй 4-6 поисковых запросов для Wildberries.
Ответь строго JSON-массивом строк, без пояснений.

Требования:
- один запрос с брендом и моделью (если есть),
- один запрос с русским типом товара в начале,
- один запрос по ключевым признакам на русском,
- один запрос без бренда, но с визуальными признаками,
- не копируй длинные названия с упаковки целиком,
- запросы должны выглядеть как обычный поиск пользователя на Wildberries.
PROMPT),

    'wb_match_prompt' => env('OPENAI_WB_MATCH_PROMPT', <<<PROMPT
Сравни кандидатов Wildberries с исходным фото и верни JSON строго по формату:
{
  "status": "EXACT_MATCH | CLOSE_MATCH | NOT_FOUND",
  "best_match": { "title": "", "url": "", "score": 0, "price": "" },
  "alternatives": [
    { "title": "", "url": "", "score": 0, "price": "" }
  ]
}

EXACT_MATCH только если совпадают:
- бренд,
- модель/серия,
- тип товара,
- визуальная форма,
- цвет.

Если совпадает лишь часть признаков — CLOSE_MATCH.
Если кандидаты относятся к другой категории товара — это NOT_FOUND.
PROMPT),

    'wb_minimal_prompt' => env('OPENAI_WB_MINIMAL_PROMPT', <<<PROMPT
Проанализируй изображение товара и сформируй точный запрос для Wildberries.

Верни ТОЛЬКО JSON:

{
  "product_type": "",
  "search_query": ""
}

ЖЁСТКИЕ ПРАВИЛА:

- Ответ на русском языке. Английские слова нельзя (кроме бренда/модели).
- product_type ОБЯЗАТЕЛЬНО на русском: это конкретный тип предмета на фото.
- search_query ОБЯЗАТЕЛЬНО начинается с product_type.
- Длина search_query: 2–6 слов.

ЕСЛИ НА ФОТО ШЛЕМ:
- product_type = "мотошлем" (или "шлем", если не мото).
- В search_query добавь конструктивный тип: "интеграл"/"открытый"/"модуляр", если видно.

ФОРМУЛА:
product_type + бренд + конструктивный тип + характеристика

Где:
- конструктивный тип = вид товара (интеграл, накладные, беспроводные и т.п.)
- характеристика = цвет/материал (только если видно)

ЗАПРЕЩЕНО:
- начинать с бренда/модели
- английские слова (full face, black, gaming и т.д.)
- длинные названия моделей

ПРИМЕРЫ:
"мотошлем acerbis интеграл черный"
"наушники mchoose v9 черные"
"рюкзак кожаный черный"
PROMPT),

    'wb' => [
        'enabled' => env('WB_SEARCH_ENABLED', true),
        'use_playwright' => env('WB_USE_PLAYWRIGHT', true),
        'search_api_url' => env('WB_SEARCH_API_URL', 'https://search.wb.ru/exactmatch/ru/common/v4/search?appType=1&curr=rub&dest=-1257786&lang=ru&locale=ru&query=%s&resultset=catalog&sort=popular&page=1&spp=0'),
        'timeout' => env('WB_HTTP_TIMEOUT', 15),
        'playwright_timeout_ms' => env('WB_PLAYWRIGHT_TIMEOUT_MS', 15000),
        'playwright_remote_nodes' => array_values(array_filter(explode(',', env('WB_PLAYWRIGHT_REMOTE_NODES', '')))),
        'playwright_remote_timeout_ms' => env('WB_PLAYWRIGHT_REMOTE_TIMEOUT_MS', 60000),
        'playwright_remote_busy_ttl' => env('WB_PLAYWRIGHT_REMOTE_BUSY_TTL', 45),
        'playwright_remote_cooldown_sec' => env('WB_PLAYWRIGHT_REMOTE_COOLDOWN_SEC', 2),
        'user_agent' => env('WB_HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36'),
        'proxy_url' => env('WB_PROXY_URL', ''),
        'proxy_list' => env('WB_PROXY_LIST', ''),
        'proxy_host' => env('WB_PROXY_HOST', ''),
        'proxy_pool' => env('WB_PROXY_POOL', ''),
        'proxy_ports' => env('WB_PROXY_PORTS', '10000-10999'),
        'proxy_schemes' => env('WB_PROXY_SCHEMES', 'socks5,http'),
        'proxy_sample' => env('WB_PROXY_SAMPLE', 6),
        'proxy_fallback' => env('WB_PROXY_FALLBACK', true),
        'request_delay_ms' => env('WB_REQUEST_DELAY_MS', 0),
    ],

    'aidentika' => [
        'base_url' => env('AIDENTIKA_BASE_URL', 'https://api.aidentika.com/api/v1/public'),
        'api_key' => env('AIDENTIKA_API_KEY', ''),
        'category_id' => env('AIDENTIKA_CATEGORY_ID', 'default'),
        'concept_id' => env('AIDENTIKA_CONCEPT_ID', 'default'),
        'webhook_secret' => env('AIDENTIKA_WEBHOOK_SECRET', ''),
        'webhook_enabled' => env('AIDENTIKA_WEBHOOK_ENABLED', false),
    ],
];
