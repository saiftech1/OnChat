<?php
namespace hypergo\room;

class RoomManager {
    public $rooms;

    public function __construct(array $rooms) {
		$this->setRooms($rooms);
    }

    /**
     * @return array
     */
    public function getRooms():array {
        return $this->rooms;
    }

    /**
     * @param array $rooms
     * @return void
     */
    public function setRooms(array $rooms) {
        $this->rooms = $rooms;
    }

    public function getRoom($rid):array {
        $rooms = $this->getRooms();

        return (array) $rooms[$rid];
    }

    /**
     * 是否存在该房间
     * 
     * @param mixed $rid
     * @return boolean
     */
    public function hasRoom($rid):bool {
        $rooms = $this->getRooms();

        return isset($rooms[$rid]);
    }
    
    /**
     * 添加聊天者
     *
     * @param [type] $rid
     * @param integer $fd
     * @return void
     */
    public function addChatter($rid, int $fd) {
        $rooms = $this->getRooms();
        $room = $rooms[$rid]; //找到这个房间的数组
        $room[] = $fd; //添加聊天者的fd到房间
        $rooms[$rid] = $room;
		
        $this->setRooms($rooms);
    }

    /**
     * 移除聊天者
     *
     * @param [type] $rid
     * @param integer $fd
     * @return void
     */
    public function removeChatter($rid, int $fd) {
        $rooms = $this->getRooms();
        $room = $rooms[$rid]; //找到这个房间的数组
        $key = array_search($fd, $room); //通过value取得key
		unset($room[$key]);
        $rooms[$rid] = $room;

        $this->setRooms($rooms);
    }

    /**
     * 获取该房间聊天人数
     *
     * @param [type] $rid
     * @return integer
     */
    public function getChatterNum($rid):int {
        $rooms = $this->getRooms();

        return count($rooms[$rid]);
    }
}
?>