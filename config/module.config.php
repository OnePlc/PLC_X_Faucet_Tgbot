<?php
/**
 * module.config.php - Telegram Bot Config
 *
 * Main Config File for Faucet Telegram Bot Module
 *
 * @category Config
 * @package Faucet\Tgbot
 * @author Verein onePlace
 * @copyright (C) 2021  Verein onePlace <admin@1plc.ch>
 * @license https://opensource.org/licenses/BSD-3-Clause
 * @version 1.0.0
 * @since 1.0.0
 */

namespace OnePlace\Faucet\Tgbot;

use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;
use Laminas\ServiceManager\Factory\InvokableFactory;

return [
    # Livechat Module - Routes
    'router' => [
        'routes' => [
            # Telegram Update WebHook
            'faucet-tgbotupdate' => [
                'type'    => Literal::class,
                'options' => [
                    'route' => '/telegram/bot/update',
                    'defaults' => [
                        'controller' => Controller\TelegramController::class,
                        'action'     => 'tgbhook',
                    ],
                ],
            ],
        ],
    ],

    # View Settings
    'view_manager' => [
        'template_path_stack' => [
            'tgbot' => __DIR__ . '/../view',
        ],
    ],

    # Translator
    'translator' => [
        'locale' => 'de_DE',
        'translation_file_patterns' => [
            [
                'type'     => 'gettext',
                'base_dir' => __DIR__ . '/../language',
                'pattern'  => '%s.mo',
            ],
        ],
    ],
];
