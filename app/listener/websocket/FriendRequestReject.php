<?php

declare(strict_types=1);

namespace app\listener\websocket;

use app\constant\SocketEvent;
use app\service\Friend as FriendService;

class FriendRequestReject extends SocketEventHandler
{

    /**
     * 事件监听处理
     *
     * @return mixed
     */
    public function handle(FriendService $friendService, $event)
    {
        ['requestId' => $requestId, 'reason' => $reason] = $event;

        $user = $this->getUser();

        $result = $friendService->reject($requestId, $user['id'], $reason);

        $this->websocket->emit(SocketEvent::FRIEND_REQUEST_REJECT, $result);

        // 如果成功拒绝申请，则尝试给申请人推送消息
        if (!$result->isSuccess()) {
            return false;
        }

        // 拿到申请人的FD
        $requesterFd = $this->fdTable->getFd($result->data['requesterId']);
        $requesterFd && $this->websocket->setSender($requesterFd)->emit(SocketEvent::FRIEND_REQUEST_REJECT, $result);
    }
}
