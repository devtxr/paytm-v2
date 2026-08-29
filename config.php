<?php
/**
 * TelePulse - Configuration File
 */
return [
    'bot_token' => 'YOUR_BOT_TOKEN_HERE',
    'chat_id' => 'YOUR_CHAT_ID_HERE',
    'data_file' => __DIR__ . '/state.json',
    'log_file' => __DIR__ . '/telepulse.log',
    'monitors' => [
        [
            'id' => 'mon-1',
            'name' => 'Telegram News Channel (@telegram)',
            'type' => 'telegram_channel',
            'target' => 'telegram',
            'interval' => 60,
            'timeout' => 8,
        ],
        [
            'id' => 'mon-2',
            'name' => 'Durov\'s Channel (@durov)',
            'type' => 'telegram_channel',
            'target' => 'durov',
            'interval' => 60,
            'timeout' => 8,
        ],
        [
            'id' => 'mon-3',
            'name' => 'BotFather Health Check (@BotFather)',
            'type' => 'telegram_bot',
            'target' => 'BotFather',
            'interval' => 30,
            'timeout' => 8,
        ],
        [
            'id' => 'mon-4',
            'name' => 'Community Support Bot Webhook',
            'type' => 'telegram_webhook',
            'target' => 'https://api.telegram.org/bot-status-probe',
            'interval' => 60,
            'timeout' => 8,
        ],
        [
            'id' => 'mon-5',
            'name' => 'Telegram Mini App Showcase',
            'type' => 'telegram_miniapp',
            'target' => 'https://t.me/wallet',
            'interval' => 120,
            'timeout' => 8,
        ]
    ]
];
