<?php

declare(strict_types=1);

namespace app\service;

use Identicon\Identicon;
use app\constant\SessionKey;

use app\core\Result;
use app\core\identicon\ImageMagickGenerator;
use app\core\storage\Storage;
use app\facade\ChatroomService;
use app\facade\IndexService;
use app\model\ChatMember as ChatMemberModel;
use app\model\ChatRecord as ChatRecordModel;
use app\model\ChatSession as ChatSessionModel;
use app\model\Chatroom as ChatroomModel;
use app\model\User as UserModel;
use app\model\UserInfo as UserInfoModel;
use app\util\Date as DateUtil;
use app\util\File as FileUtil;
use app\util\Str as StrUtil;
use think\Collection;
use think\facade\Db;
use think\facade\Request;
use think\facade\Session;

class User
{
    /** 用户已存在 */
    const CODE_USER_EXIST = 1;
    /** 用户不存在 */
    const CODE_USER_NOT_EXIST = 2;
    /** 用户密码错误 */
    const CODE_PASSWORD_ERROR = 3;
    /** 用户密码过短/过长 */
    const CODE_PASSWORD_IRREGULAR  = 4;
    /** 个性签名过短/过长 */
    const CODE_SIGNATURE_IRREGULAR  = 5;

    /** 响应消息预定义 */
    const MSG = [
        self::CODE_USER_EXIST          => '用户已存在',
        self::CODE_USER_NOT_EXIST      => '用户不存在',
        self::CODE_PASSWORD_ERROR      => '密码错误',
        self::CODE_PASSWORD_IRREGULAR  => '密码长度必须在' . ONCHAT_PASSWORD_MIN_LENGTH . '~' . ONCHAT_PASSWORD_MAX_LENGTH . '位字符之间',
        self::CODE_SIGNATURE_IRREGULAR => '个性签名长度必须在' . ONCHAT_SIGNATURE_MIN_LENGTH . '~' . ONCHAT_SIGNATURE_MAX_LENGTH . '位字符之间',
    ];

    /** User 字段 */
    const USER_FIELDS = [
        'user.id',
        'user.username',
        'user.email',
        'user.telephone',
        'user.create_time',
        'user.update_time',
        'user_info.nickname',
        'user_info.signature',
        'user_info.mood',
        'user_info.login_time',
        'user_info.birthday',
        'user_info.gender',
        'user_info.age',
        'user_info.constellation',
        'user_info.avatar',
        'user_info.background_image',
    ];

    /**
     * 获取储存在SESSION中的用户ID
     *
     * @return integer|null
     */
    public function getId(): ?int
    {
        return Session::get(SessionKey::USER_LOGIN . '.id');
    }

    /**
     * 获取储存在SESSION中的用户名
     *
     * @return string|null
     */
    public function getUsername(): ?string
    {
        return Session::get(SessionKey::USER_LOGIN . '.username');
    }

    /**
     * 注册账户
     *
     * @param string $username 用户名
     * @param string $password 密码
     * @param string $email 邮箱
     * @param string $captcha 验证码
     * @return Result
     */
    public function register(string $username, string $password, string $email, string $captcha): Result
    {
        if (!ONCHAT_CAN_REGISTER) {
            return Result::create(Result::CODE_ERROR_UNKNOWN, '暂不开放注册！');
        }

        if (!$this->checkEmail($email)->data) { // 如果邮箱不可用
            return Result::create(Result::CODE_ERROR_PARAM);
        }

        if (!IndexService::checkEmailCaptcha($email, $captcha)) {
            return Result::create(Result::CODE_ERROR_PARAM, '验证码错误！');
        }

        $username = StrUtil::trimAll($username);
        $password = StrUtil::trimAll($password);

        if (!preg_match(ONCHAT_USERNAME_PATTERN, $username)) {
            return Result::create(Result::CODE_ERROR_PARAM, '用户名格式不规范！');
        }

        $result = $this->checkPassword($password);
        if ($result !== Result::CODE_SUCCESS) { // 如果用户密码不符合规范
            return Result::create(Result::CODE_ERROR_PARAM, self::MSG[$result]);
        }

        if (!empty($this->getIdByUsername($username))) { // 如果已经有这个用户了
            return Result::create(Result::CODE_ERROR_PARAM, self::MSG[self::CODE_USER_EXIST]);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!$hash) { // 如果密码散列创建失败
            return Result::create(Result::CODE_ERROR_UNKNOWN, '密码散列创建失败');
        }

        $timestamp = time() * 1000;
        $identicon = new Identicon(new ImageMagickGenerator());

        // 启动事务
        Db::startTrans();
        try {
            $user = UserModel::create([
                'username'    => $username,
                'password'    => $hash,
                'email'       => strtolower($email),
                'create_time' => $timestamp,
                'update_time' => $timestamp,
            ]);

            $storage = Storage::getInstance();
            // 根据用户ID创建哈希头像
            $imageData = $identicon->getImageData($user->id, 256, null, '#f5f5f5');

            $path = $storage->getRootPath() . 'avatar/user/' . $user->id . '/';
            $file = md5((string) DateUtil::now()) . '.png';

            $result = $storage->save($path, $file, $imageData);

            if (!$result->isSuccess()) {
                return $result;
            }

            $filename = $path . $file;

            // 暂存一下用户信息，便于最后直接返回给前端
            $userInfo = [
                'user_id'          => $user->id,
                'nickname'         => $user->username,
                'login_time'       => $timestamp,
                'avatar'           => $filename,
                'background_image' => 'http://static.hypergo.net/img/rkph.jpg', // TODO
            ];

            UserInfoModel::create($userInfo);

            $this->saveLoginStatus($user->id, $username, $hash); // 保存登录状态

            ChatroomService::addMember(1, $user->id); // 添加新用户到默认聊天室

            unset($user->password); // 删掉密码

            $userInfo['avatar'] = $storage->getUrl($filename);
            $userInfo['avatarThumbnail'] = $storage->getThumbnailUrl($filename);

            // 创建一个聊天室通知会话
            ChatSessionModel::create([
                'user_id' => $user->id,
                'type'    => ChatSessionModel::TYPE_CHATROOM_NOTICE,
                'data'    => [],
                'visible' => false,
                'create_time' => $timestamp,
                'update_time' => $timestamp
            ]);

            // 提交事务
            Db::commit();

            return Result::success($user->toArray() + $userInfo);
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            return Result::create(Result::CODE_ERROR_UNKNOWN, $e->getMessage());
        }
    }

    /**
     * 用户登录
     *
     * @param string $username 用户名
     * @param string $password 密码
     * @return Result
     */
    public function login(string $username, string $password): Result
    {
        if (!preg_match(ONCHAT_USERNAME_PATTERN, $username)) {
            return Result::create(Result::CODE_ERROR_PARAM, '用户名格式不规范！');
        }

        $result = $this->checkPassword($password);
        if ($result !== Result::CODE_SUCCESS) { // 如果用户密码不符合规范
            return Result::create(Result::CODE_ERROR_PARAM, self::MSG[$result]);
        }

        $fields = self::USER_FIELDS;
        $fields[] = 'user.password';

        $user = $this->getInfoByKey('username', $username, $fields);

        if (empty($user)) { // 如果用户不存在
            return Result::create(Result::CODE_ERROR_PARAM, self::MSG[self::CODE_USER_NOT_EXIST]);
        }

        if (!password_verify($password, $user->password)) { // 如果密码错误
            return Result::create(Result::CODE_ERROR_PARAM, self::MSG[self::CODE_PASSWORD_ERROR]);
        }

        $this->saveLoginStatus($user->id, $user->username, $user->password); // 保存登录状态

        unset($user->password);

        $storage = Storage::getInstance();

        $user->avatar = $storage->getUrl($user->avatar);
        $user->avatarThumbnail = $storage->getThumbnailUrl($user->avatar);

        return Result::success($user);
    }

    /**
     * 清除登录Session，退出登录
     *
     * @return void
     */
    public function logout(): void
    {
        Session::set(SessionKey::USER_LOGIN, null);
    }

    /**
     * 修改密码
     *
     * @param string $oldPassword 原密码
     * @param string $newPassword 新密码
     * @return Result
     */
    public function changePassword(string $oldPassword, string $newPassword): Result
    {
        $result = $this->checkPassword($newPassword);
        if ($result !== Result::CODE_SUCCESS) { // 如果用户密码不符合规范
            return Result::create(Result::CODE_ERROR_PARAM, self::MSG[$result]);
        }

        $id = $this->getId();

        $user = UserModel::find($id);

        if (!password_verify($oldPassword, $user->password)) { // 如果密码错误
            return Result::create(Result::CODE_ERROR_PARAM, self::MSG[self::CODE_PASSWORD_ERROR]);
        }

        $newPassword = StrUtil::trimAll($newPassword);
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);

        if (!$hash) { // 如果密码散列创建失败
            return Result::create(Result::CODE_ERROR_UNKNOWN, '密码散列创建失败');
        }

        $user->password = $hash;
        $user->update_time = time() * 1000;
        $user->save();

        $this->logout();

        return Result::success();
    }

    /**
     * 通过用户名发送邮件
     *
     * @param string $username
     * @return Result
     */
    public function sendEmailCaptcha(string $username): Result
    {
        $email = UserModel::where('username', '=', $username)->value('email');

        if (!$email) {
            return Result::create(Result::CODE_ERROR_PARAM);
        }

        return IndexService::sendEmailCaptcha($email);
    }

    /**
     * 重置密码
     *
     * @param string $username 用户名
     * @param string $password 密码
     * @param string $captcha 验证码
     * @return Result
     */
    public function resetPassword(string $username, string $password, string $captcha): Result
    {
        if (!preg_match(ONCHAT_USERNAME_PATTERN, $username)) {
            return Result::create(Result::CODE_ERROR_PARAM, '用户名格式不规范！');
        }

        $result = $this->checkPassword($password);
        if ($result !== Result::CODE_SUCCESS) { // 如果用户密码不符合规范
            return Result::create(Result::CODE_ERROR_PARAM, self::MSG[$result]);
        }

        $user = UserModel::where('username', '=', $username)->find();

        if (!IndexService::checkEmailCaptcha($user->email, $captcha)) {
            return Result::create(Result::CODE_ERROR_PARAM, '验证码错误！');
        }

        $password = StrUtil::trimAll($password);
        $hash = password_hash($password, PASSWORD_DEFAULT);

        if (!$hash) { // 如果密码散列创建失败
            return Result::create(Result::CODE_ERROR_UNKNOWN, '密码散列创建失败');
        }

        $user->password = $hash;
        $user->update_time = time() * 1000;
        $user->save();

        return Result::success();
    }

    /**
     * 设置用户登录Session，用于保存登录状态
     *
     * @param integer $id 用户ID
     * @param string $username 用户名
     * @param string $hashPassword 密码密文
     * @return void
     */
    public function saveLoginStatus(int $id, string $username, string $hashPassword): void
    {
        Session::set(SessionKey::USER_LOGIN, [
            'id'       => $id,
            'username' => $username,
            'password' => $hashPassword,
        ]);
    }

    /**
     * 通过用户标识获取用户信息
     * $field为数组时，返回数据集对象；为单个字段时只返回该字段的数据
     *
     * @param string $key 用户标识名
     * @param mixed $value 用户标识值
     * @param string|array $field 需要获取的字段名
     * @return mixed
     */
    public function getInfoByKey(string $key, $value, $field)
    {
        $query = UserModel::join('user_info', 'user_info.user_id = user.id')
            ->where($key === 'id' ? 'user.id' : $key, '=', $value);

        if (is_array($field)) {
            return $query->field($field)->find();
        }

        return $query->value($field);
    }

    /**
     * 通过用户ID获取User
     *
     * @param integer $id
     * @return Result
     */
    public function getUserById(int $id): Result
    {
        $user = UserModel::join('user_info', 'user_info.user_id = user.id')
            ->where('user.id', '=', $id)
            ->field(self::USER_FIELDS)
            ->find();

        if (!$user) {
            return Result::create(Result::CODE_ERROR_PARAM, self::MSG[self::CODE_USER_NOT_EXIST]);
        }

        $storage = Storage::getInstance();

        $user->avatarThumbnail = $storage->getThumbnailUrl($user->avatar);
        $user->avatar          = $storage->getUrl($user->avatar);

        return Result::success($user);
    }

    /**
     * 通过用户名获取User
     *
     * @param string $username
     * @return Result
     */
    public function getUserByUsername(string $username): Result
    {
        $user = UserModel::where('user.username', '=', $username)->join('user_info', 'user_info.user_id = user.id')
            ->field(self::USER_FIELDS)->find();

        if (!$user) {
            return Result::create(Result::CODE_ERROR_PARAM, self::MSG[self::CODE_USER_NOT_EXIST]);
        }

        $storage = Storage::getInstance();

        $user->avatarThumbnail = $storage->getThumbnailUrl($user->avatar);
        $user->avatar          = $storage->getUrl($user->avatar);

        return Result::success($user);
    }

    /**
     * 获得用户在聊天室中的昵称
     *
     * @param integer $id 用户ID
     * @param integer $chatroomId
     * @return string|null
     */
    public function getNicknameInChatroom(int $id, int $chatroomId): ?string
    {
        return ChatMemberModel::where([
            'user_id'     => $id,
            'chatroom_id' => $chatroomId
        ])->value('nickname');
    }

    /**
     * 通过用户ID获取用户名
     *
     * @param integer $id 用户ID
     * @return string|null
     */
    public function getUsernameById(int $id): ?string
    {
        return UserModel::where('id', '=', $id)->value('username');
    }

    /**
     * 通过用户名获取用户ID
     *
     * @param string $username 用户名
     * @return integer
     */
    public function getIdByUsername(string $username): ?int
    {
        return UserModel::where('username', '=', $username)->value('id');
    }

    /**
     * 检查用户是否已经登录/处于登录状态
     * 如果已登录，则返回User, 否则返回false
     *
     * @return Result
     */
    public function checkLogin(): Result
    {
        $session = Session::get(SessionKey::USER_LOGIN);
        if (empty($session)) { // 如果没有登录的Session
            return Result::success(false);
        }

        $fields = self::USER_FIELDS;
        $fields[] = 'user.password';
        $user = $this->getInfoByKey('id', $session['id'], $fields);

        if ($session['password'] !== $user['password']) { // 如果密码错误
            return Result::success(false);
        }

        $storage = Storage::getInstance();

        $user->avatarThumbnail = $storage->getThumbnailUrl($user->avatar);
        $user->avatar          = $storage->getUrl($user->avatar);

        unset($user->password);

        return Result::success($user);
    }

    /**
     * 检查用户密码是否符合规范
     *
     * @param string $password
     * @return integer
     */
    public function checkPassword(string $password): int
    {
        $length = StrUtil::length($password);

        if ($length < ONCHAT_PASSWORD_MIN_LENGTH || $length > ONCHAT_PASSWORD_MAX_LENGTH) {
            return self::CODE_PASSWORD_IRREGULAR;
        }

        return Result::CODE_SUCCESS;
    }

    /**
     * 检测邮箱是否可用
     *
     * @param string $email
     * @return Result
     */
    public function checkEmail(string $email): Result
    {

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Result::success(false);
        }

        return Result::success(UserModel::where('email', '=', $email)->field('id')->find() === null);
    }

    /**
     * 上传头像
     *
     * @return Result
     */
    public function avatar(): Result
    {
        $userId = $this->getId();

        try {
            $storage = Storage::getInstance();
            $image   = Request::file('image');
            $path    = $storage->getRootPath() . 'avatar/user/' . $userId . '/';
            $file    = $image->md5() . '.' . FileUtil::getExtension($image);

            $result = $storage->save($path, $file, $image);
            $storage->clear($path, Storage::AVATAR_MAX_COUNT);

            if (!$result->isSuccess()) {
                return $result;
            }

            $filename = $path . $file;

            // 更新新头像
            UserInfoModel::update(['avatar' => $filename], [
                'user_id' => $userId
            ]);

            return Result::success([
                'avatar'          => $storage->getUrl($filename),
                'avatarThumbnail' => $storage->getThumbnailUrl($filename)
            ]);
        } catch (\Exception $e) {
            return Result::create(Result::CODE_ERROR_UNKNOWN, $e->getMessage());
        }
    }

    /**
     * 查询该用户下所有的聊天室
     *
     * @return Collection
     */
    public function getChatrooms($userId = null): Collection
    {
        return ChatMemberModel::join('chatroom', 'chat_member.chatroom_id = chatroom.id')
            ->where('chat_member.user_id', '=', $userId ?: $this->getId())
            ->field('chatroom.*')
            ->select();
    }

    /**
     * 获取该用户的聊天会话
     *
     * @return Result
     */
    public function getChatSessions(): Result
    {
        $userId = $this->getId();

        $storage = Storage::getInstance();

        // 存放私聊聊天室ID列表
        $privateChatroomIdList = [];

        $data = ChatSessionModel::leftJoin('chatroom', 'chat_session.data->chatroomId = chatroom.id')
            ->where([
                'chat_session.user_id' => $userId,
                'chat_session.visible' => true
            ])
            ->field([
                'chat_session.id',
                'chat_session.user_id',
                'chat_session.type',
                'chat_session.data',
                'chat_session.unread',
                'chat_session.sticky',
                'chat_session.create_time',
                'chat_session.update_time',
                'chatroom.name AS title',
                'chatroom.avatar AS avatarThumbnail',
                'chatroom.type AS chatroomType',
            ])
            ->select();

        $query = null;
        $field = [
            'chat_record.*',
            'chat_member.nickname',
        ];
        $max = 'MAX(chat_record.id)';

        foreach ($data as $item) {
            switch ($item->type) {
                    // 聊天室类型会话
                case ChatSessionModel::TYPE_CHATROOM:
                    $chatroomId = $item->data->chatroomId;
                    // 将这些数据丢到data里面
                    $item->data->chatroomType = $item->chatroomType;
                    unset($item->chatroomType);

                    switch ($item->data->chatroomType) {
                        case ChatroomModel::TYPE_GROUP_CHAT:
                            $item->avatarThumbnail = $storage->getThumbnailUrl($item->avatarThumbnail);
                            break;

                        case ChatroomModel::TYPE_PRIVATE_CHAT:
                            $privateChatroomIdList[] = $chatroomId;
                            break;
                    }

                    $table = ChatRecordModel::getTableNameById($chatroomId);
                    $on = 'chat_member.user_id = chat_record.user_id AND chat_member.chatroom_id = ' . $chatroomId;

                    if (!$query) {
                        $query = ChatRecordModel::opt($chatroomId)
                            ->alias('chat_record')
                            ->leftJoin('chat_member', $on)
                            ->where('chat_record.id', '=', function ($query) use ($chatroomId, $table, $max) {
                                $query->table($table)
                                    ->alias('chat_record')
                                    ->where('chat_record.chatroom_id', '=', $chatroomId)
                                    ->fieldRaw($max);
                            })
                            // ->limit(1)
                            ->field($field);
                    } else {
                        $query->unionAll(function ($query) use ($chatroomId, $table, $field, $on, $max) {
                            $query->table($table)
                                ->alias('chat_record')
                                ->leftJoin('chat_member', $on)
                                ->where('chat_record.id', '=', function ($query) use ($chatroomId, $table, $max) {
                                    $query->table($table)
                                        ->alias('chat_record')
                                        ->where('chat_record.chatroom_id', '=', $chatroomId)
                                        ->fieldRaw($max);
                                })
                                ->limit(1)
                                ->field($field);
                        });
                    }
                    break;
            }
        }

        // 最新消息的数据集
        $latestMsgList = $query->select();
        // 聊天室最新消息
        $latestMsg = null;
        // 好友信息
        $friendInfo = null;

        // 如果有私聊聊天室，就去找到好友的昵称和头像
        if (!empty($privateChatroomIdList)) {
            $friendInfo = ChatMemberModel::join('user_info', 'chat_member.user_id = user_info.user_id')
                ->where([
                    ['chat_member.chatroom_id', 'IN', $privateChatroomIdList],
                    ['chat_member.user_id', '<>', $userId]
                ])
                ->field([
                    'chat_member.chatroom_id',
                    'chat_member.nickname',
                    'user_info.user_id',
                    'user_info.avatar',
                ])
                ->select();
        }

        foreach ($data as $item) {
            switch ($item->type) {
                case ChatSessionModel::TYPE_CHATROOM:
                    // 将最新消息填入
                    $item->content = $latestMsgList->where('chatroom_id', '=', $item->data->chatroomId)->shift();

                    // 将私聊聊天室的头像，好友昵称填入
                    if ($item->data->chatroomType == ChatroomModel::TYPE_PRIVATE_CHAT && $friendInfo) {
                        $info = $friendInfo->where('chatroom_id', '=', $item->data->chatroomId)->shift();
                        if ($info) {
                            $item->data->userId = $info->user_id;
                            $item->title = $info->nickname;
                            $item->avatarThumbnail = $storage->getThumbnailUrl($info->avatar);
                        }
                    }
                    break;
            }
        }

        return Result::success($data);
    }

    /**
     * 获取私聊聊天室列表
     *
     * @return Result
     */
    public function getPrivateChatrooms(): Result
    {
        $userId = $this->getId();
        $storage = Storage::getInstance();

        $data = ChatMemberModel::join('user_info', 'user_info.user_id = chat_member.user_id')
            ->where('chat_member.chatroom_id', 'IN', function ($query)  use ($userId) {
                // 私聊聊天室ID列表
                $query->table('chatroom')->join('chat_member', 'chatroom.id = chat_member.chatroom_id')->where([
                    'chatroom.type' =>  ChatroomModel::TYPE_PRIVATE_CHAT,
                    'chat_member.user_id' => $userId
                ])->field('chatroom.id');
            })
            ->where('chat_member.user_id', '<>', $userId)
            ->field([
                'chat_member.id',
                'chat_member.user_id as friendId',
                'chat_member.chatroom_id',
                'chat_member.nickname AS title',
                'chat_member.create_time',
                'chat_member.update_time',
                'user_info.signature AS content',
                'user_info.avatar AS avatarThumbnail',
            ])
            ->select();

        foreach ($data as $item) {
            $item->userId = $userId;
            $item->type = ChatSessionModel::TYPE_CHATROOM;
            $item->data = [
                'chatroomId'   => $item->chatroom_id,
                'chatroomType' => ChatroomModel::TYPE_PRIVATE_CHAT,
                'userId'       => $item->friendId
            ];
            $item->avatarThumbnail = $storage->getThumbnailUrl($item->avatarThumbnail);

            unset($item->friendId, $item->chatroom_id);
        }

        return Result::success($data);
    }

    /**
     * 获取群聊聊天室列表
     *
     * @return Result
     */
    public function getGroupChatrooms(): Result
    {
        $userId = $this->getId();
        $storage = Storage::getInstance();

        $data = ChatMemberModel::join('chatroom', 'chatroom.id = chat_member.chatroom_id')
            ->where([
                'chat_member.user_id' => $userId,
                'chatroom.type' => ChatroomModel::TYPE_GROUP_CHAT
            ])
            ->field([
                'chat_member.id',
                'chat_member.chatroom_id',
                'chat_member.create_time',
                'chat_member.update_time',
                'chatroom.name AS title',
                'chatroom.description AS content',
                'chatroom.avatar AS avatarThumbnail',
            ])
            ->select();

        foreach ($data as $item) {
            $item->userId = $userId;
            $item->type = ChatSessionModel::TYPE_CHATROOM;
            $item->data = [
                'chatroomId'   => $item->chatroom_id,
                'chatroomType' => ChatroomModel::TYPE_GROUP_CHAT
            ];
            $item->avatarThumbnail = $storage->getThumbnailUrl($item->avatarThumbnail);

            unset($item->chatroom_id);
        }

        return Result::success($data);
    }

    /**
     * 置顶聊天列表子项
     *
     * @param integer $id 会话ID
     * @param boolean $sticky
     * @return Result
     */
    public function stickyChatSession(int $id, $sticky = true): Result
    {
        $userId = $this->getId();

        $chatSession = ChatSessionModel::where([
            'id'      => $id,
            'user_id' => $userId
        ])->find();

        if (!$chatSession) {
            return Result::create(Result::CODE_ERROR_PARAM);
        }

        $chatSession->sticky = $sticky;
        $chatSession->save();

        return Result::success();
    }

    /**
     * 取消置顶聊天列表子项
     *
     * @param integer $id 聊天室成员表ID
     * @return Result
     */
    public function unstickyChatSession(int $id): Result
    {
        return $this->stickyChatSession($id, false);
    }

    /**
     * 将聊天列表子项设置为已读
     *
     * @param integer $id
     * @param integer $unread
     * @return Result
     */
    public function readedChatSession(int $id, int $unread = 0): Result
    {
        $userId = $this->getId();

        $chatSession = ChatSessionModel::where([
            'id'      => $id,
            'user_id' => $userId
        ])->find();

        if (!$chatSession) {
            return Result::create(Result::CODE_ERROR_PARAM);
        }

        $chatSession->unread = $unread;
        $chatSession->save();

        return Result::success();
    }

    /**
     * 将聊天列表子项设置为未读
     *
     * @param integer $id
     * @return Result
     */
    public function unreadChatSession(int $id): Result
    {
        return $this->readedChatSession($id, 1);
    }

    /**
     * 保存用户信息
     *
     * @return Result
     */
    public function saveUserInfo(): Result
    {
        $userId = $this->getId();

        $nickname      = Request::param('nickname/s') ?: $this->getUsername();
        $signature     = Request::param('signature/s');
        $mood          = Request::param('mood/d');
        $birthday      = Request::param('birthday/d');
        $gender        = Request::param('gender/d');
        $age           = isset($birthday) ? DateUtil::getAge((int) $birthday / 1000) : null;
        $constellation = isset($birthday) ? DateUtil::getConstellation((int) $birthday / 1000) : null;

        if ($signature) {
            if (StrUtil::isEmpty($signature)) {
                $signature = null;
            } else {
                $signature = trim($signature);

                if (StrUtil::length($signature) > ONCHAT_SIGNATURE_MAX_LENGTH) {
                    return Result::create(self::CODE_SIGNATURE_IRREGULAR, self::MSG[self::CODE_SIGNATURE_IRREGULAR]);
                }
            }
        }

        if (!isset($mood)) {
            $mood = 0;
        }

        if (!isset($gender)) {
            $gender = 2;
        }

        $userInfo = UserInfoModel::update([
            'nickname'      => $nickname,
            'signature'     => $signature,
            'mood'          => $mood,
            'birthday'      => $birthday,
            'gender'        => $gender,
            'age'           => $age,
            'constellation' => $constellation,
        ], [
            'user_id' => $userId
        ]);

        return Result::success($userInfo);
    }

    /**
     * 绑定电子邮箱
     *
     * @param string $email 邮箱
     * @param string $captcha 验证码
     * @return Result
     */
    public function bindEmail(string $email, string $captcha): Result
    {
        $userId = $this->getId();

        if (!$this->checkEmail($email)->data) { // 如果邮箱不可用
            return Result::create(Result::CODE_ERROR_PARAM);
        }

        if (!IndexService::checkEmailCaptcha($email, $captcha)) {
            return Result::create(Result::CODE_ERROR_PARAM, '验证码错误！');
        }

        $email = strtolower($email);

        UserModel::update([
            'id'          => $userId,
            'email'       => $email,
            'update_time' => time() * 1000
        ]);

        return Result::success($email);
    }

    /**
     * 模糊搜索用户
     *
     * @param string $keyword
     * @param integer $page
     * @return Result
     */
    public function search(string $keyword, int $page): Result
    {
        $storage = Storage::getInstance();

        $expression = "%{$keyword}%";
        $data = UserModel::join('user_info', 'user.id = user_info.user_id')->whereOr([
            ['user_info.nickname', 'LIKE', $expression],
            ['user.username', 'LIKE', $expression],
            ['user.id', 'LIKE', $expression],
        ])->page($page, 15)
            ->field(self::USER_FIELDS)
            ->select();

        foreach ($data as $item) {
            $item->avatarThumbnail = $storage->getThumbnailUrl($item->avatar);
            $item->avatar          = $storage->getUrl($item->avatar);
        }

        return Result::success($data);
    }
}
