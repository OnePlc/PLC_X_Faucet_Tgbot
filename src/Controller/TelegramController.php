<?php
/**
 * TelegramController.php - Main Controller
 *
 * Telegram Bot Controller Faucet Module
 *
 * @category Controller
 * @package Faucet\Tgbot
 * @author Verein onePlace
 * @copyright (C) 2020  Verein onePlace <admin@1plc.ch>
 * @license https://opensource.org/licenses/BSD-3-Clause
 * @version 1.0.0
 * @since 1.0.0
 */

declare(strict_types=1);

namespace OnePlace\Faucet\Tgbot\Controller;

use Application\Controller\CoreEntityController;
use Application\Model\CoreEntityModel;
use Laminas\View\Model\ViewModel;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGateway;
use MongoDB\Driver\Server;
use OnePlace\User\Model\UserTable;
use OnePlace\Faucet\RPSServer\Controller\ServerController;

class TelegramController extends CoreEntityController
{
    /**
     * Faucet Table Object
     *
     * @since 1.0.0
     */
    protected $oTableGateway;

    protected static $aTranslatedMenus;

    /**
     * FaucetController constructor.
     *
     * @param AdapterInterface $oDbAdapter
     * @param UserTable $oTableGateway
     * @since 1.0.0
     */
    public function __construct(AdapterInterface $oDbAdapter,UserTable $oTableGateway,$oServiceManager)
    {
        $this->oTableGateway = $oTableGateway;
        $this->sSingleForm = 'faucet-tgbot-single';

        if(isset(CoreEntityController::$oSession->oUser)) {
            setlocale(LC_TIME, CoreEntityController::$oSession->oUser->lang);
        }

        parent::__construct($oDbAdapter,$oTableGateway,$oServiceManager);

        TelegramController::$aTranslatedMenus = [
            'de_CH' => [
                '🗿 Rock' => '🗿 Stei',
                '📝️️️ Paper' => '📝️️️ Papier',
                '✂️ Scissors' => '✂️ Schäre',
            ]
        ];

        if($oTableGateway) {
            # Attach TableGateway to Entity Models
            if(!isset(CoreEntityModel::$aEntityTables[$this->sSingleForm])) {
                CoreEntityModel::$aEntityTables[$this->sSingleForm] = $oTableGateway;
            }
        }
    }

    public function indexAction() {
        $this->layout('layout/json');

        echo 'Welcome to Telegram Bot API';

        return false;
    }

    private function loadTelegramPLCUser($iChatID) {
        $oPlcUserCheck = false;
        try {
            $oPlcUserCheck = $this->oTableGateway->getSingle($iChatID, 'telegram_chatid');
        } catch(\RuntimeException $e) {
            $aContent = [
                'chat_id' => $iChatID,
                'text' => "You are not logged in. Please /login",
            ];
            $aMsgData['reply'] = $aContent['text'];

            TelegramController::sendTelegramMessage($aContent);
        }

        return $oPlcUserCheck;
    }

    private static function translateTelegramMenu($sText, $sLang) {
        if(array_key_exists($sLang,TelegramController::$aTranslatedMenus)) {
            if(array_key_exists($sText,TelegramController::$aTranslatedMenus[$sLang])) {
                return TelegramController::$aTranslatedMenus[$sLang][$sText];
            } else {
                return $sText;
            }
        }
    }

    private function castTelegramRPSVote($iVote,$iChatID,$sEmote = '') {
        $oPlcUserCheck = $this->loadTelegramPLCUser($iChatID);
        if($oPlcUserCheck) {
            $oGamePrepared = ServerController::getPreparedRPSGame($oPlcUserCheck);
            if($oGamePrepared) {
                $aGameInfo = ServerController::startRPSGame($iVote,(float)$oGamePrepared->amount_bet,$oPlcUserCheck,'telegram');
                if($aGameInfo['state'] == 'success') {
                    $keyboard = [
                        'keyboard' => [
                            [
                                ['text' => '☑️️️ New Game'],
                                ['text' => '👁‍️️ My Games'],
                            ],
                            [
                                ['text' => '👁‍️️ Look for Games'],
                            ],
                            [
                                ['text' => '🏠‍️️ Back to Menu']
                            ]
                        ]
                    ];
                    $encodedKeyboard = json_encode($keyboard);
                    $aContent = [
                        'chat_id' => $iChatID,
                        'reply_markup' => $encodedKeyboard,
                        'text' => "Done! Your Game ".$sEmote." with bet of ".number_format((float)$oGamePrepared->amount_bet,2,'.','\'')." coins is launched - Good Luck!",
                    ];
                    $aMsgData['reply'] = $aContent['text'];
                } else {
                    $keyboard = [
                        'keyboard' => [
                            [
                                ['text' => '☑️️️ New Game'],
                                ['text' => '👁‍️️ My Games'],
                            ],
                            [
                                ['text' => '👁‍️️ Look for Games'],
                            ],
                            [
                                ['text' => '🏠‍️️ Back to Menu']
                            ]
                        ]
                    ];
                    $encodedKeyboard = json_encode($keyboard);
                    $aContent = [
                        'chat_id' => $iChatID,
                        'reply_markup' => $encodedKeyboard,
                        'text' => "Error while creating game: ".$aGameInfo['message'],
                    ];
                    $aMsgData['reply'] = $aContent['text'];
                }
            } else {
                $aContent = [
                    'chat_id' => $iChatID,
                    'text' => "Could not find prepared game. please restart with /start",
                ];
                $aMsgData['reply'] = $aContent['text'];
            }

            TelegramController::sendTelegramMessage($aContent);
        }
    }

    public function tgbhookAction()
    {
        $this->layout('layout/json');

        $oMetricTbl = $this->getCustomTable('core_metric');
        $oUserTbl = $this->getCustomTable('user');

        $oUpdate = json_decode($this->getRequest()->getContent());
        if(isset($oUpdate->message)) {
            $oMetricTbl->insert([
                'user_idfs' => 0,
                'action' => 'tgbot-update',
                'type' => 'success',
                'date' => date('Y-m-d H:i:s', time()),
                'comment' => json_encode($oUpdate),
            ]);
            //echo 'found chat';
            $iChatID = $oUpdate->message->chat->id;
            $oMsgTbl = $this->getCustomTable('faucet_tgbot_message');
            $oMsgCheck = $oMsgTbl->select([
                'msgkey' => $oUpdate->message->date,
                'user_idfs' => 1,
                'parse_next' => 0,
                'chat_id' => $iChatID,
            ]);
            if(count($oMsgCheck) == 0) {
                $aMsgData = [
                    'msgkey' => $oUpdate->message->date,
                    'user_idfs' => 1,
                    'chat_id' => $iChatID,
                    'date' => date('Y-m-d H:i:s', time()),
                    'message' => utf8_decode((isset($oUpdate->message->text)) ? $oUpdate->message->text : ''),
                    'parse_next' => 0,
                    'reply' => 'no reply',
                ];
                $oReplyCheck = $oMsgTbl->select([
                    'chat_id' => $iChatID,
                    'parse_next' => 1,
                ]);

                if(count($oReplyCheck) == 0) {
                    switch($oUpdate->message->text) {
                        case (preg_match('/Coins - Play.*/', $oUpdate->message->text) ? true : false) :
                            $oPlcUserCheck = $this->loadTelegramPLCUser($iChatID);
                            if($oPlcUserCheck) {
                                $iGameID = substr(explode(':', $oUpdate->message->text)[0], 1);
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "Whats your choice for Game #" . $iGameID . ' ?',
                                ];
                                $aGamesKB[] = [['text' => '🗿 Rock'], ['text' => '️️️ 📝️️️ Paper'], ['text' => '✂️ Scissors']];
                                $keyboard = [
                                    'keyboard' => $aGamesKB
                                ];
                                $aContent['reply_markup'] = json_encode($keyboard);
                                $aMsgData['reply'] = $iGameID;
                                $aMsgData['parse_next'] = 1;
                                $aMsgData['parse_type'] = 'rpsvote';
                                $oGame = ServerController::loadRPSGame($iGameID, 'client');
                                if($oGame->amount_bet > $oPlcUserCheck->token_balance) {
                                    $aContent = [
                                        'chat_id' => $iChatID,
                                        'text' => "Your balance is too low to join this game",
                                    ];
                                    $aMsgData['parse_next'] = 0;
                                    $aMsgData['reply'] = $aContent['text'];
                                    TelegramController::sendTelegramMessage($aContent);
                                } else {
                                    ServerController::joinRPSGame($iGameID, $oPlcUserCheck->getID());
                                    TelegramController::sendTelegramMessage($aContent);
                                }
                            } else {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "You are not logged in. Please /login",
                                ];
                                $aMsgData['reply'] = $aContent['text'];
                                TelegramController::sendTelegramMessage($aContent);
                            }
                            break;
                        case (preg_match('/Coins - Cancel.*/', $oUpdate->message->text) ? true : false) :
                            $oPlcUserCheck = $this->loadTelegramPLCUser($iChatID);
                            if($oPlcUserCheck) {
                                $iGameID = substr(explode(':',$oUpdate->message->text)[0],1);
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "Game #".$iGameID." cancelled",
                                ];
                                $aMsgData['reply'] = $aContent['text'];
                                if(is_numeric($iGameID) && $iGameID != '' && $iGameID != 0) {
                                    ServerController::cancelRPSGame($oPlcUserCheck,$iGameID);
                                    $aMyGames = ServerController::loadRPSGames($oPlcUserCheck);
                                    $aGamesKB = [];
                                    if(count($aMyGames) > 0) {
                                        foreach($aMyGames as $oGame) {
                                            $sEmote = '';
                                            switch($oGame->host_vote) {
                                                case 1:
                                                    $sEmote = '🗿 Rock';
                                                    break;
                                                case 2:
                                                    $sEmote = '📝️️️ Paper';
                                                    break;
                                                case 3:
                                                    $sEmote = '✂️ Scissors';
                                                    break;
                                                default:
                                                    break;
                                            }
                                            $aGamesKB[] = [['text' => '#'.$oGame->Match_ID.': '.$sEmote.' - '.TelegramController::timeElapsedString($oGame->date_created).' - '.$oGame->amount_bet.' Coins - Cancel']];
                                        }
                                    } else {
                                        $aGamesKB[] = [['text' => 'No Open Games']];
                                    }
                                    $aGamesKB[] = [['text' => '✊️️ Rock, Paper, Scissors']];
                                    $aGamesKB[] = [['text' => '🏠‍️️ Back to Menu']];
                                    $keyboard = [
                                        'keyboard' => $aGamesKB
                                    ];
                                    $aContent['reply_markup'] = json_encode($keyboard);
                                }
                                TelegramController::sendTelegramMessage($aContent);
                            } else {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "You are not logged in. Please /login",
                                ];
                                $aMsgData['reply'] = $aContent['text'];
                                TelegramController::sendTelegramMessage($aContent);
                            }
                            break;
                        case '👁‍️️ My Games':
                            $oPlcUserCheck = $this->loadTelegramPLCUser($iChatID);
                            if($oPlcUserCheck) {
                                $aMyGames = ServerController::loadRPSGames($oPlcUserCheck);
                                $aGamesKB = [];
                                if(count($aMyGames) > 0) {
                                    foreach($aMyGames as $oGame) {
                                        $sEmote = '';
                                        switch($oGame->host_vote) {
                                            case 1:
                                                $sEmote = '🗿 Rock';
                                                break;
                                            case 2:
                                                $sEmote = '📝️️️ Paper';
                                                break;
                                            case 3:
                                                $sEmote = '✂️ Scissors';
                                                break;
                                            default:
                                                break;
                                        }
                                        $aGamesKB[] = [['text' => '#'.$oGame->Match_ID.': '.$sEmote.' - '.TelegramController::timeElapsedString($oGame->date_created).' - '.$oGame->amount_bet.' Coins - Cancel']];
                                    }
                                    $iGames = count($aGamesKB);
                                } else {
                                    $aGamesKB[] = [['text' => 'No Open Games']];
                                    $iGames = (count($aGamesKB)-3);
                                }
                                if($iGames < 0) {
                                    $iGames = 0;
                                }
                                $aGamesKB[] = [['text' => '✊️️ Rock, Paper, Scissors']];
                                $aGamesKB[] = [['text' => '🏠‍️️ Back to Menu']];
                                $keyboard = [
                                    'keyboard' => $aGamesKB
                                ];
                                $encodedKeyboard = json_encode($keyboard);
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'reply_markup' => $encodedKeyboard,
                                    'text' => "You have ".$iGames.' open Games',
                                ];
                                $aMsgData['reply'] = $aContent['text'];

                                TelegramController::sendTelegramMessage($aContent);
                            }
                            break;
                        case '🗿 Rock':
                            $this->castTelegramRPSVote(1,$iChatID,'🗿');
                            break;
                        case '📝️️️ Paper':
                            $this->castTelegramRPSVote(2,$iChatID,'📝️️️');
                            break;
                        case '✂️ Scissors':
                            $this->castTelegramRPSVote(3,$iChatID,'✂️');
                            break;
                        case '☑️️️ New Game':
                            $aContent = [
                                'chat_id' => $iChatID,
                                'text' => "How much do you want to bet?",
                            ];
                            $aMsgData['parse_next'] = 1;
                            $aMsgData['parse_type'] = 'coins';
                            $aMsgData['reply'] = $aContent['text'];

                            TelegramController::sendTelegramMessage($aContent);
                            break;
                        case '👁‍️️ Look for Games':
                            $oPlcUserCheck = $this->loadTelegramPLCUser($iChatID);
                            if($oPlcUserCheck) {
                                $aMyGames = ServerController::loadRPSGames($oPlcUserCheck, 'client');
                                $aGamesKB = [];
                                $sText = '';
                                if(count($aMyGames) > 0) {
                                    foreach($aMyGames as $oGame) {
                                        try {
                                            $oHost = $this->oTableGateway->getSingle($oGame->host_user_idfs);
                                            $aGamesKB[] = [['text' => '#'.$oGame->Match_ID.': '.$oHost->getLabel().' - '.TelegramController::timeElapsedString($oGame->date_created).' - '.$oGame->amount_bet.' Coins - Play']];
                                        } catch(\RuntimeException $e) {

                                        }
                                    }
                                    $sText = "There are ".(count($aGamesKB)).' open Games';
                                } else {
                                    $sText = 'No Open Games at the moment';
                                }
                                $aGamesKB[] = [['text' => '✊️️ Rock, Paper, Scissors']];
                                $aGamesKB[] = [['text' => '🏠‍️️ Back to Menu']];
                                $keyboard = [
                                    'keyboard' => $aGamesKB
                                ];
                                $encodedKeyboard = json_encode($keyboard);
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'reply_markup' => $encodedKeyboard,
                                    'text' => $sText,
                                ];
                                $aMsgData['reply'] = $aContent['text'];

                                TelegramController::sendTelegramMessage($aContent);
                            }
                            break;
                        case '✊️️ Rock, Paper, Scissors':
                            $keyboard = [
                                'keyboard' => [
                                    [
                                        ['text' => '☑️️️ New Game'],
                                        ['text' => '👁‍️️ My Games'],
                                    ],
                                    [
                                        ['text' => '👁‍️️ Look for Games'],
                                    ],
                                    [
                                        ['text' => '🏠‍️️ Menu'],
                                    ]
                                ]
                            ];
                            $encodedKeyboard = json_encode($keyboard);
                            $aContent = [
                                'chat_id' => $iChatID,
                                'reply_markup' => $encodedKeyboard,
                                'text' => "Welcome to Rock, Paper Scissors",
                            ];
                            $aMsgData['reply'] = $aContent['text'];

                            TelegramController::sendTelegramMessage($aContent);
                            break;
                        case '⚔️️️ Games':
                            $keyboard = [
                                'keyboard' => [
                                    [
                                        ['text' => '✊️️ Rock, Paper, Scissors'],
                                    ]
                                ]
                            ];
                            $encodedKeyboard = json_encode($keyboard);
                            $aContent = [
                                'chat_id' => $iChatID,
                                'reply_markup' => $encodedKeyboard,
                                'text' => "What Game do you want to play?",
                            ];
                            $aMsgData['reply'] = $aContent['text'];

                            TelegramController::sendTelegramMessage($aContent);
                            break;
                        case '🔕 Game Notifications OFF':
                            $oPlcUserCheck = $this->loadTelegramPLCUser($iChatID);
                            if($oPlcUserCheck) {
                                $oSetTbl = $this->getCustomTable('user_setting');
                                $oSet = $oSetTbl->select([
                                    'user_idfs' => $oPlcUserCheck->getID(),
                                    'setting_name' => 'tgbot-gamenotifications',
                                ]);
                                if(count($oSet) == 0) {
                                    $oSetTbl->insert([
                                        'user_idfs' => $oPlcUserCheck->getID(),
                                        'setting_name' => 'tgbot-gamenotifications',
                                        'setting_value' => 'off',
                                    ]);
                                } else {
                                    $oSetTbl->update([
                                        'setting_value' => 'off',
                                    ],[
                                        'user_idfs' => $oPlcUserCheck->getID(),
                                        'setting_name' => 'tgbot-gamenotifications',
                                    ]);
                                }
                                $keyboard = [
                                    'keyboard' => [
                                        [
                                            ['text' => '🏠‍️️ Back to Menu']
                                        ]
                                    ]
                                ];
                                $encodedKeyboard = json_encode($keyboard);
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'reply_markup' => $encodedKeyboard,
                                    'text' => "Game Notifications are now disabled.",
                                ];
                                $aMsgData['reply'] = $aContent['text'];
                                TelegramController::sendTelegramMessage($aContent);
                            } else {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "You are not logged in. Please /login",
                                ];
                                $aMsgData['reply'] = $aContent['text'];
                                TelegramController::sendTelegramMessage($aContent);
                            }
                            break;
                        case '🔔 Game Notifications ON':
                            $oPlcUserCheck = $this->loadTelegramPLCUser($iChatID);
                            if($oPlcUserCheck) {
                                $oSetTbl = $this->getCustomTable('user_setting');
                                $oSet = $oSetTbl->select([
                                    'user_idfs' => $oPlcUserCheck->getID(),
                                    'setting_name' => 'tgbot-gamenotifications',
                                ]);
                                if(count($oSet) == 0) {
                                    $oSetTbl->insert([
                                        'user_idfs' => $oPlcUserCheck->getID(),
                                        'setting_name' => 'tgbot-gamenotifications',
                                        'setting_value' => 'on',
                                    ]);
                                } else {
                                    $oSetTbl->update([
                                        'setting_value' => 'on',
                                    ],[
                                        'user_idfs' => $oPlcUserCheck->getID(),
                                        'setting_name' => 'tgbot-gamenotifications',
                                    ]);
                                }
                                $keyboard = [
                                    'keyboard' => [
                                        [
                                            ['text' => '🏠‍️️ Back to Menu']
                                        ]
                                    ]
                                ];
                                $encodedKeyboard = json_encode($keyboard);
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'reply_markup' => $encodedKeyboard,
                                    'text' => "Game Notifications are now enabled.",
                                ];
                                $aMsgData['reply'] = $aContent['text'];
                                TelegramController::sendTelegramMessage($aContent);
                            } else {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "You are not logged in. Please /login",
                                ];
                                $aMsgData['reply'] = $aContent['text'];
                                TelegramController::sendTelegramMessage($aContent);
                            }
                            break;
                        case '🔔️️ Notifications':
                            $oPlcUserCheck = $this->loadTelegramPLCUser($iChatID);
                            if($oPlcUserCheck) {
                                $aSettings = ['text' => '🔕 Game Notifications ON'];
                                if($oPlcUserCheck->getSetting('tgbot-gamenotifications') == 'on') {
                                    $aSettings = ['text' => '🔕 Game Notifications OFF'];
                                } else {
                                    $aSettings = ['text' => '🔔 Game Notifications ON'];
                                }
                                $keyboard = [
                                    'keyboard' => [
                                        [
                                            $aSettings
                                        ],
                                        [
                                            ['text' => '🏠‍️️ Back to Menu']
                                        ]
                                    ]
                                ];
                                $encodedKeyboard = json_encode($keyboard);
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'reply_markup' => $encodedKeyboard,
                                    'text' => "Change your Notifications Settings.",
                                ];
                                $aMsgData['reply'] = $aContent['text'];
                                TelegramController::sendTelegramMessage($aContent);
                            } else {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "You are not logged in. Please /login",
                                ];
                                $aMsgData['reply'] = $aContent['text'];
                                TelegramController::sendTelegramMessage($aContent);
                            }
                            break;
                        case '⬅️️ Logout':
                            $oPlcUserCheck = $this->loadTelegramPLCUser($iChatID);
                            if($oPlcUserCheck) {
                                if($oPlcUserCheck->getID() != 0 && $oPlcUserCheck != '') {
                                    $oUserTbl->update([
                                        'telegram_chatid' => '',
                                    ],'User_ID = '.$oPlcUserCheck->getID());
                                }
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "Logged out successfully. Press /start again",
                                ];
                                $aMsgData['reply'] = $aContent['text'];
                                TelegramController::sendTelegramMessage($aContent);
                            } else {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "You are not logged in. Please /login",
                                ];
                                $aMsgData['reply'] = $aContent['text'];
                                TelegramController::sendTelegramMessage($aContent);
                            }
                            break;
                        case '⚙️️️️ Settings':
                            $oPlcUserCheck = $this->loadTelegramPLCUser($iChatID);
                            if($oPlcUserCheck) {
                                $keyboard = [
                                    'keyboard' => [
                                        [
                                            ['text' => '🔔️️ Notifications'],
                                        ],
                                        [
                                            ['text' => '⬅️️ Logout'],
                                        ],
                                        [
                                            ['text' => '🏠‍️️ Back to Menu'],
                                        ]
                                    ]
                                ];
                                $encodedKeyboard = json_encode($keyboard);
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'reply_markup' => $encodedKeyboard,
                                    'text' => "What do you want to manage?",
                                ];
                                $aMsgData['reply'] = $aContent['text'];
                                TelegramController::sendTelegramMessage($aContent);
                            } else {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "You are not logged in. Please /login",
                                ];
                                $aMsgData['reply'] = $aContent['text'];
                                TelegramController::sendTelegramMessage($aContent);
                            }
                            break;
                        case '☑️️ Shortlinks':
                            $oPlcUserCheck = $this->loadTelegramPLCUser($iChatID);
                            if($oPlcUserCheck) {
                                $keyboard = [
                                    'keyboard' => [
                                        [
                                            ['text' => '🏠‍️️ Back to Menu'],
                                        ],
                                    ]
                                ];
                                $encodedKeyboard = json_encode($keyboard);
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'reply_markup' => $encodedKeyboard,
                                    'text' => "Shortlinks coming soon!",
                                ];
                                $aMsgData['reply'] = $aContent['text'];
                                TelegramController::sendTelegramMessage($aContent);
                            } else {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "You are not logged in. Please /login",
                                ];
                                $aMsgData['reply'] = $aContent['text'];
                                TelegramController::sendTelegramMessage($aContent);
                            }
                            break;
                        case '💰 Balance':
                            $oPlcUserCheck = $this->loadTelegramPLCUser($iChatID);
                            if($oPlcUserCheck) {
                                $keyboard = [
                                    'keyboard' => [
                                        [
                                            ['text' => '☑️️ Shortlinks'],
                                            ['text' => '⚔️️️ Games'],
                                        ],
                                        [
                                            ['text' => '💰 Balance'],
                                        ],
                                        [
                                            ['text' => '⚙️️️️ Settings'],
                                        ]
                                    ]
                                ];
                                $encodedKeyboard = json_encode($keyboard);
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'reply_markup' => $encodedKeyboard,
                                    'text' => "Your current Balance is: ".number_format((float)$oPlcUserCheck->token_balance,2,'.','\'').' Coins',
                                ];
                                $aMsgData['reply'] = $aContent['text'];
                                TelegramController::sendTelegramMessage($aContent);
                            } else {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "You are not logged in. Please /login",
                                ];
                                $aMsgData['reply'] = $aContent['text'];
                                TelegramController::sendTelegramMessage($aContent);
                            }
                            break;
                        case '🏠‍️️ Menu':
                        case '🏠‍️️ Back to Menu':
                            $oPlcUserCheck = $this->loadTelegramPLCUser($iChatID);
                            if($oPlcUserCheck) {
                                $keyboard = [
                                    'keyboard' => [
                                        [
                                            ['text' => '☑️️ Shortlinks'],
                                            ['text' => '⚔️️️ Games'],
                                        ],
                                        [
                                            ['text' => '💰 Balance'],
                                        ],
                                        [
                                            ['text' => '⚙️️️️ Settings'],
                                        ]
                                    ]
                                ];
                                $encodedKeyboard = json_encode($keyboard);
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'reply_markup' => $encodedKeyboard,
                                    'text' => "What do you like to do?",
                                ];
                                $aMsgData['reply'] = $aContent['text'];
                                TelegramController::sendTelegramMessage($aContent);
                            } else {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "You are not logged in. Please /login",
                                ];
                                $aMsgData['reply'] = $aContent['text'];
                                TelegramController::sendTelegramMessage($aContent);
                            }
                            break;
                        case '/start':
                            $sUser = '';
                            if(isset($oUpdate->message->chat->username)) {
                                $sUser = $oUpdate->message->chat->username;
                            }
                            try {
                                $oPlcUserCheck = $this->oTableGateway->getSingle($iChatID, 'telegram_chatid');
                                $keyboard = [
                                    'keyboard' => [
                                        [
                                            ['text' => '☑️️ Shortlinks'],
                                        ],
                                        [
                                            ['text' => '⚔️️️ Games'],
                                        ],
                                        [
                                            ['text' => '⚙️️️️ Settings'],
                                        ]
                                    ]
                                ];
                                $encodedKeyboard = json_encode($keyboard);
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'reply_markup' => $encodedKeyboard,
                                    'text' => "Hi ".$oPlcUserCheck->getLabel()." - Welcome to Swissfaucet.io Bot! What do you like to do?",
                                ];
                                $aMsgData['reply'] = $aContent['text'];
                                TelegramController::sendTelegramMessage($aContent);
                            } catch(\RuntimeException $e) {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "Hi Welcome to Swissfaucet.io Bot! I don't know you yet. Please /login or /signup",
                                ];
                                TelegramController::sendTelegramMessage($aContent);
                            }
                            break;
                        case '/login':
                            $aContent = [
                                'chat_id' => $iChatID,
                                'text' => "Please provide our Swissfaucet.io Username or E-Mail",
                            ];
                            $aMsgData['parse_next'] = 1;
                            $aMsgData['parse_type'] = 'username';
                            $aMsgData['reply'] = $aContent['text'];
                            TelegramController::sendTelegramMessage($aContent);
                            break;
                        case '/signup':
                            $aContent = [
                                'chat_id' => $iChatID,
                                'text' => "Please enter your e-mail address",
                            ];
                            $aMsgData['parse_next'] = 1;
                            $aMsgData['parse_type'] = 'signup';
                            $aMsgData['reply'] = $aContent['text'];
                            TelegramController::sendTelegramMessage($aContent);
                            break;
                        default:
                            break;
                    }
                } else {
                    $oReplyCheck = $oReplyCheck->current();
                    switch($oReplyCheck->parse_type) {
                        case 'rpsvote':
                            $sChoice = $oUpdate->message->text;
                            $oPlcUserCheck = false;
                            try {
                                $oPlcUserCheck = $this->oTableGateway->getSingle($iChatID, 'telegram_chatid');
                            } catch(\RuntimeException $e) {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "You are not logged in. Please /login",
                                ];
                                TelegramController::sendTelegramMessage($aContent);
                            }

                            if($oPlcUserCheck) {
                                $iVote = 0;
                                switch($sChoice) {
                                    case '🗿 Rock':
                                        $iVote = 1;
                                        break;
                                    case '📝️️️ Paper':
                                        $iVote = 2;
                                        break;
                                    case '✂️ Scissors':
                                        $iVote = 3;
                                        break;
                                    default:
                                        break;
                                }

                                $aContent = [
                                    'chat_id' => $iChatID,
                                ];

                                if($iVote == 0) {
                                    $keyboard = [
                                        'keyboard' => [
                                            [
                                                ['text' => '🗿 Rock'],
                                                ['text' => '📝️️️ Paper'],
                                                ['text' => '✂️ Scissors'],
                                            ]
                                        ]
                                    ];
                                    $aContent['text'] = "Could not cast your vote, please try again. (can happen if you click too fast)";
                                } else {
                                    $keyboard = [
                                        'keyboard' => [
                                            [
                                                ['text' => '☑️️️ New Game'],
                                                ['text' => '👁‍️️ My Games'],
                                            ],
                                            [
                                                ['text' => '👁‍️️ Look for Games'],
                                            ],
                                            [
                                                ['text' => '🏠‍️️ Menu'],
                                            ]
                                        ]
                                    ];
                                    $aContent['text'] = "Welcome to Rock, Paper Scissors";
                                    $iGameID = (int)$oReplyCheck->reply;
                                    $oMsgTbl->update([
                                        'parse_next' => 0,
                                    ],'Message_ID = '.$oReplyCheck->Message_ID);

                                    if(ServerController::joinRPSGame($iGameID, $oPlcUserCheck->getID(), $iVote, 'telegram')) {
                                        $aGameInfo = ServerController::matchRPSGame($iGameID, $iVote, 0, 'telegram');
                                    }
                                    $aMsgData['reply'] = $aContent['text'];
                                }

                                $encodedKeyboard = json_encode($keyboard);
                                $aContent['reply_markup'] = $encodedKeyboard;

                                TelegramController::sendTelegramMessage($aContent);
                            }
                            break;
                        case 'coins':
                            $fCoins = $oUpdate->message->text;
                            $oPlcUserCheck = false;
                            try {
                                $oPlcUserCheck = $this->oTableGateway->getSingle($iChatID, 'telegram_chatid');
                            } catch(\RuntimeException $e) {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "You are not logged in. Please /login",
                                ];
                                TelegramController::sendTelegramMessage($aContent);
                            }
                            if(is_numeric($fCoins) && $oPlcUserCheck) {
                                if($fCoins > $oPlcUserCheck->token_balance) {
                                    $aContent = [
                                        'chat_id' => $iChatID,
                                        'text' => "Dude thats more than your token balance of ".number_format((float)$oPlcUserCheck->token_balance,2,'.','').' Coins - please lower your bet.',
                                    ];
                                    TelegramController::sendTelegramMessage($aContent);
                                } else {
                                    ServerController::prepareRPSGame($oPlcUserCheck,$fCoins);
                                    $keyboard = [
                                        'keyboard' => [
                                            [
                                                ['text' => '🗿 Rock'],
                                                ['text' => '📝️️️ Paper'],
                                                ['text' => '✂️ Scissors'],
                                            ]
                                        ]
                                    ];
                                    $encodedKeyboard = json_encode($keyboard);
                                    $aContent = [
                                        'chat_id' => $iChatID,
                                        'reply_markup' => $encodedKeyboard,
                                        'text' => "Bet of ".$fCoins.' set. Now whats your choice?',
                                    ];
                                    TelegramController::sendTelegramMessage($aContent);
                                    $oMsgTbl->update([
                                        'parse_next' => 0,
                                    ],'Message_ID = '.$oReplyCheck->Message_ID);
                                }
                            } else {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "Please provide a valid amount of coins (Min 1, Max Balance)",
                                ];
                                TelegramController::sendTelegramMessage($aContent);
                            }
                            break;
                        case 'password':
                            $sPWCheck = $oUpdate->message->text;
                            $iUserID = (int)$oReplyCheck->reply;
                            $oPlcUserCheck = false;
                            try {
                                $oPlcUserCheck = $this->oTableGateway->getSingle($iUserID);
                            } catch(\RuntimeException $e) {

                            }
                            if($oPlcUserCheck) {
                                if($oPlcUserCheck->login_counter <= 5) {
                                    if(password_verify($sPWCheck, $oPlcUserCheck->password)) {
                                        $oUsrTbl = $this->getCustomTable('user');
                                        $oUsrTbl->update([
                                            'telegram_chatid' => $iChatID,
                                        ],'User_ID = '.$oPlcUserCheck->getID());
                                        $aContent = [
                                            'chat_id' => $iChatID,
                                            'text' => "Success. Welcome ! Press /start again.",
                                        ];
                                        $oUserTbl->update([
                                            'login_counter' => 0,
                                        ],'User_ID = '.$oPlcUserCheck->getID());
                                        $oMsgTbl->update([
                                            'parse_next' => 0,
                                        ],'Message_ID = '.$oReplyCheck->Message_ID);
                                        TelegramController::sendTelegramMessage($aContent);
                                    } else {
                                        $oUserTbl->update([
                                            'login_counter' => (int)$oPlcUserCheck->login_counter+1,
                                        ],'User_ID = '.$oPlcUserCheck->getID());
                                        $aContent = [
                                            'chat_id' => $iChatID,
                                            'text' => "Wrong password. Please try again (max ".(5-$oPlcUserCheck->login_counter).' times)',
                                        ];
                                        TelegramController::sendTelegramMessage($aContent);
                                    }
                                } else {
                                    $aContent = [
                                        'chat_id' => $iChatID,
                                        'text' => "Oh cmon ...please stop this! Otherwise your IP will get banned from bot.",
                                    ];
                                    TelegramController::sendTelegramMessage($aContent);
                                }
                            } else {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "User not found",
                                ];
                                TelegramController::sendTelegramMessage($aContent);
                            }
                            break;
                        case 'signuppw2':
                            $sUserPWRepeat = $oUpdate->message->text;
                            $aRegInfo = explode('###', $oReplyCheck->reply);
                            $sHashPW = (isset($aRegInfo[2])) ? $aRegInfo[2] : '';
                            $sUserName = $aRegInfo[0];
                            $sEmail = $aRegInfo[1];
                            if(!password_verify($sUserPWRepeat,$sHashPW)) {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "Passwords do not match. Please start again with the password",
                                ];
                                $oMsgTbl->update([
                                    'parse_type' => 'signuppw',
                                    'reply' => $sUserName,
                                ],'Message_ID = '.$oReplyCheck->Message_ID);
                                TelegramController::sendTelegramMessage($aContent);
                            } else {
                                # sign up
                                $oNewUser = $this->oTableGateway->generateNew();
                                $oNewUser->exchangeArray([
                                    'email' => $sEmail,
                                    'username' => $sUserName,
                                    'full_name' => $sUserName,
                                    'password' => $sHashPW,
                                    'theme' => 'faucet',
                                    'lang' => 'en_US',
                                    'telegram_chatid' => $iChatID,
                                ]);
                                try {
                                    $iNewUserID = $this->oTableGateway->saveSingle($oNewUser);
                                    $aContent = [
                                        'chat_id' => $iChatID,
                                        'text' => "Success. Welcome to Swissfaucet.io ! Press /start again.",
                                    ];
                                } catch(\RuntimeException $e) {
                                    $aContent = [
                                        'chat_id' => $iChatID,
                                        'text' => "Error while registering the user. Press /start again.",
                                    ];
                                }

                                $oMsgTbl->update([
                                    'parse_next' => 0,
                                ],'Message_ID = '.$oReplyCheck->Message_ID);
                                TelegramController::sendTelegramMessage($aContent);
                            }
                            break;
                        case 'signuppw':
                            $sUserPW = $oUpdate->message->text;

                            if(strlen($sUserPW) < 4) {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "Please choose a safe password",
                                ];
                                TelegramController::sendTelegramMessage($aContent);
                            } else {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "Please repeat password",
                                ];
                                $oMsgTbl->update([
                                    'parse_type' => 'signuppw2',
                                    'reply' => $oReplyCheck->reply.'###'.password_hash($sUserPW, PASSWORD_DEFAULT),
                                ],'Message_ID = '.$oReplyCheck->Message_ID);
                                TelegramController::sendTelegramMessage($aContent);
                            }
                            break;
                        case 'signupuser':
                            $sUserCheck = $oUpdate->message->text;
                            if(strlen($sUserCheck) < 3) {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "Please choose another username",
                                ];
                                TelegramController::sendTelegramMessage($aContent);
                            } else {
                                $oPlcUserCheck = false;
                                try {
                                    $oPlcUserCheck = $this->oTableGateway->getSingle($sUserCheck, 'username');
                                } catch(\RuntimeException $e) {

                                }
                                if($oPlcUserCheck) {
                                    $aContent = [
                                        'chat_id' => $iChatID,
                                        'text' => "Username is already taken. Please try another one.",
                                    ];
                                    TelegramController::sendTelegramMessage($aContent);
                                } else {
                                    $aContent = [
                                        'chat_id' => $iChatID,
                                        'text' => "Now choose a password",
                                    ];
                                    $oMsgTbl->update([
                                        'parse_type' => 'signuppw',
                                        'reply' => $sUserCheck.'###'.$oReplyCheck->reply,
                                    ],'Message_ID = '.$oReplyCheck->Message_ID);
                                    TelegramController::sendTelegramMessage($aContent);
                                }
                            }
                            break;
                        case 'signup':
                            $sEmailCheck = $oUpdate->message->text;
                            $bIsEmail = stripos($sEmailCheck,'@');
                            if($bIsEmail === false) {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "Please provide a valid email address",
                                ];
                                TelegramController::sendTelegramMessage($aContent);
                            } else {
                                $oPlcUserCheck = false;
                                try {
                                    $oPlcUserCheck = $this->oTableGateway->getSingle($sEmailCheck, 'email');
                                } catch(\RuntimeException $e) {

                                }
                                if($oPlcUserCheck) {
                                    $aContent = [
                                        'chat_id' => $iChatID,
                                        'text' => "There is already an account with that e-mail. please use /login",
                                    ];
                                    $oMsgTbl->update([
                                        'parse_next' => 0,
                                    ],'Message_ID = '.$oReplyCheck->Message_ID);
                                    TelegramController::sendTelegramMessage($aContent);
                                } else {
                                    $aContent = [
                                        'chat_id' => $iChatID,
                                        'text' => "Now choose a username",
                                    ];
                                    $oMsgTbl->update([
                                        'parse_type' => 'signupuser',
                                        'reply' => $sEmailCheck,
                                    ],'Message_ID = '.$oReplyCheck->Message_ID);
                                    TelegramController::sendTelegramMessage($aContent);
                                }
                            }

                            break;
                        case 'username':
                            $sUserCheck = (isset($oUpdate->message->text)) ? $oUpdate->message->text : '';
                            $bIsEmail = stripos($sUserCheck,'@');
                            $oPlcUserCheck = false;
                            if($bIsEmail === false) {
                                try {
                                    $oPlcUserCheck = $this->oTableGateway->getSingle($sUserCheck, 'username');
                                } catch(\RuntimeException $e) {

                                }
                            } else {
                                try {
                                    $oPlcUserCheck = $this->oTableGateway->getSingle($sUserCheck, 'email');
                                } catch(\RuntimeException $e) {

                                }
                            }

                            if($oPlcUserCheck) {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "Now enter your password",
                                ];
                                $oMsgTbl->update([
                                    'parse_type' => 'password',
                                    'reply' => $oPlcUserCheck->getID(),
                                ],'Message_ID = '.$oReplyCheck->Message_ID);
                                TelegramController::sendTelegramMessage($aContent);
                            } else {
                                $aContent = [
                                    'chat_id' => $iChatID,
                                    'text' => "User not found. Please try again.",
                                ];
                                TelegramController::sendTelegramMessage($aContent);
                            }
                            break;
                        default:
                            break;
                    }
                }

                $aMsgData['message'] = utf8_decode($aMsgData['message']);
                $oMsgTbl->insert($aMsgData);
            } else {
                // already processed
            }

        }

        $aReturn = [
            'state' => 'success',
            'message' => 'update parsed',
        ];

        return $this->defaultJSONResponse($aReturn);
    }

    /**
     * Default JSON Response Template for API
     *
     * @param $aReturn
     * @return false
     * @since 1.0.0
     */
    private function defaultJSONResponse($aReturn) {
        # Print List with all Entities
        header('Content-Type: application/json');
        echo json_encode($aReturn);

        return false;
    }

    private static function sendTelegramMessage($aContent)
    {
        $sToken = CoreEntityController::$aGlobalSettings['tgbot-token'];
        $ch = curl_init();
        $url="https://api.telegram.org/bot$sToken/SendMessage";
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($aContent));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        // receive server response ...
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $server_output = curl_exec ($ch);
        curl_close ($ch);
    }

    public static function timeElapsedString($datetime, $full = false) {
        $now = new \DateTime;
        $ago = new \DateTime($datetime);
        $diff = $now->diff($ago);

        $diff->w = floor($diff->d / 7);
        $diff->d -= $diff->w * 7;

        $string = array(
            'y' => 'year',
            'm' => 'month',
            'w' => 'week',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'minute',
            's' => 'second',
        );
        foreach ($string as $k => &$v) {
            if ($diff->$k) {
                $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        }

        if (!$full) $string = array_slice($string, 0, 1);
        return $string ? implode(', ', $string) . ' ago' : 'just now';
    }
}
