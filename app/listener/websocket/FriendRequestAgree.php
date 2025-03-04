<?php

declare(strict_types=1);

namespace app\listener\websocket;

use app\constant\SocketEvent;
use app\constant\SocketRoomPrefix;
use app\service\Friend as FriendService;

class FriendRequestAgree extends SocketEventHandler
{

    /**
     * 事件监听处理
     *
     * @return mixed
     */
    public function handle(FriendService $friendService, $event)
    {
        ['requestId' => $requestId, 'requesterAlias' => $requesterAlias] = $event;

        $user = $this->getUser();

        $result = $friendService->agree($requestId, $user['id'], $requesterAlias);

        $chatroomId = $result->data['chatroomId'];
        $this->websocket->join(SocketRoomPrefix::CHATROOM . $chatroomId);
        $this->websocket->emit(SocketEvent::FRIEND_REQUEST_AGREE, $result);

        // 如果成功同意申请，则尝试给申请人推送消息
        if (!$result->isSuccess()) {
            return false;
        }

        // 拿到申请人的FD
        $requesterFd = $this->fdTable->getFd($result->data['requesterId']);
        if ($requesterFd) {
            // 加入新的聊天室
            $this->websocket->setSender($requesterFd)
                ->join(SocketRoomPrefix::CHATROOM . $chatroomId)
                ->emit(SocketEvent::FRIEND_REQUEST_AGREE, $result);
        }
    }
}
