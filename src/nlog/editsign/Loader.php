<?php
/**
 * Copyright (C) 2017-2019  NLOG(엔로그)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace nlog\editsign;

use ifteam\SimpleArea\database\area\AreaProvider;
use pocketmine\block\Sign as SignBlock;
use pocketmine\block\utils\SignText;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

class Loader extends PluginBase implements Listener {

    /** @var int */
    private const FORM_ID = 202198;

    /** @var string[]|SignBlock[] */
    public static $process = [];
    /** @var string */
    public static $prefix = "§b§l[ 표지판수정 ]§r§7 ";

    public function onEnable() {
        if (!class_exists(SignText::class, false)) {
            $this->getServer()->getLogger()->info(TextFormat::RED . "[EditSignPost] 구버전의 구동기에선 실행할 수 없어요.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }

        $this->getServer()->getCommandMap()->register('sign', new EditSignPostCommand($this));
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $this->getServer()->getLogger()->info(TextFormat::DARK_AQUA . "[EditSignPost] 플러그인이 활성화되었어요.");
    }

    public function onReceive(DataPacketReceiveEvent $ev) {
        $pk = $ev->getPacket();
        $player = $ev->getOrigin()->getPlayer();
        if (
                $pk instanceof ModalFormResponsePacket &&
                $pk->formId === self::FORM_ID &&
                self::$process[$player->getName()] instanceof SignBlock
        ) {
            if (json_decode($pk->formData, true) === null) {
                return;
            }
            $data = array_values(json_decode($pk->formData, true));

            /** @var SignBlock $sign */
            $sign = self::$process[$player->getName()];
            $new = (clone $sign->getText());
            $new->setLines($data);

            unset(self::$process[$player->getName()]);


            if ($sign->updateText($player, $new)) {
                $player->sendMessage(self::$prefix . "표지판 내용을 수정하였습니다.");
            } else {
                $player->sendMessage(self::$prefix . "표지판 내용을 수정하지 못하였습니다.");
            }
        }
    }

    public function onTouch(PlayerInteractEvent $ev) {
        if ((self::$process[$ev->getPlayer()->getName()] ?? 'none') !== 'edit') {
            return;
        }

        if (($block = $ev->getBlock()) instanceof SignBlock && $block->getPos()->getWorld()->getBlock($block->getPos()) instanceof SignBlock) {
            /** @var SignBlock $block */
            unset(self::$process[$ev->getPlayer()->getName()]);

            if ($ev->getPlayer()->isOp()) {
                $this->sendFormPacket($ev->getPlayer(), $block->getText());
                self::$process[$ev->getPlayer()->getName()] = $block;
            } elseif (self::compatibilityWithSimpleArea()) {
                $section = AreaProvider::getInstance()->getArea($ev->getBlock()->getPos()->getWorld(), $ev->getBlock()->getPos()->getX(), $ev->getBlock()->getPos()->getZ());
                if ($section !== null && $section->isResident($ev->getPlayer()->getName())) {
                    $this->sendFormPacket($ev->getPlayer(), $block->getText());
                    self::$process[$ev->getPlayer()->getName()] = $block;
                }
            }
        }

    }

    public function sendFormPacket(Player $player, SignText $signText) {
        $i = 0;
        $pk = new ModalFormRequestPacket();
        $pk->formId = self::FORM_ID;
        $pk->formData = json_encode([
                'type' => 'custom_form',
                'title' => '표지판 내용 수정',
                'content' => array_values(array_map(function ($text) use (&$i) {
                    ++$i;
                    return ["type" => "input", "text" => $i . "번째 줄", "default" => $text, "placeholder" => $i . "번째 줄"];
                }, $signText->getLines()))

        ]);

        $player->sendDataPacket($pk);
    }

    public static function compatibilityWithSimpleArea(): bool {
        return Server::getInstance()->getPluginManager()->getPlugin("SimpleArea") instanceof Plugin;
    }

    public function onQuit(PlayerQuitEvent $ev) {
        if (isset(self::$process[$ev->getPlayer()->getName()])) {
            unset(self::$process[$ev->getPlayer()->getName()]);
        }
    }

    protected function onLoad() {
        self::$process = [];
    }

}
