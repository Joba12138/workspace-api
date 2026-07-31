<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 为已有表 / 字段补充 MySQL COMMENT。
 *
 * 约定：已上线迁移不可改，只能新增。本文件不改表结构，仅写注释。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->tableComments() as $table => $comment) {
            $this->commentTable($table, $comment);
        }

        foreach ($this->columnComments() as $table => $columns) {
            foreach ($columns as $column => $comment) {
                $this->commentColumn($table, $column, $comment);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (array_keys($this->tableComments()) as $table) {
            $this->commentTable($table, '');
        }

        foreach ($this->columnComments() as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                $this->commentColumn($table, $column, '');
            }
        }
    }

    private function commentTable(string $table, string $comment): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $safe = str_replace("'", "''", $comment);
        DB::statement("ALTER TABLE `{$table}` COMMENT = '{$safe}'");
    }

    /**
     * 保留原字段定义，仅改 COMMENT（从 information_schema 读取类型/默认值）。
     */
    private function commentColumn(string $table, string $column, string $comment): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $schema = DB::getDatabaseName();
        $row = DB::selectOne(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY, GENERATION_EXPRESSION
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$schema, $table, $column]
        );

        if (! $row) {
            return;
        }

        // 生成列不改
        if (! empty($row->GENERATION_EXPRESSION)) {
            return;
        }

        $nullable = $row->IS_NULLABLE === 'YES' ? 'NULL' : 'NOT NULL';
        $extra = trim((string) $row->EXTRA);
        // 去掉 on update 以外可能干扰的非定义片段时保持 EXTRA 原样（含 auto_increment / on update）
        $extraSql = $extra !== '' ? ' '.$extra : '';

        $defaultSql = '';
        if ($row->COLUMN_DEFAULT !== null) {
            $default = (string) $row->COLUMN_DEFAULT;
            // MySQL 8 对表达式默认值可能带包装，CURRENT_TIMESTAMP 等保持原样
            if (preg_match('/^(CURRENT_TIMESTAMP(?:\(\d*\))?|NULL)$/i', $default)
                || str_starts_with($default, '(')) {
                $defaultSql = ' DEFAULT '.$default;
            } else {
                $defaultSql = ' DEFAULT '.DB::getPdo()->quote($default);
            }
        } elseif ($row->IS_NULLABLE === 'YES' && ! str_contains(strtolower($extra), 'auto_increment')) {
            // 显式 NULL 默认在部分类型上可省略；保持不写 DEFAULT 更安全
            $defaultSql = '';
        }

        $safeComment = str_replace("'", "''", $comment);

        $sql = sprintf(
            'ALTER TABLE `%s` MODIFY COLUMN `%s` %s %s%s%s COMMENT \'%s\'',
            $table,
            $column,
            $row->COLUMN_TYPE,
            $nullable,
            $defaultSql,
            $extraSql,
            $safeComment
        );

        DB::statement($sql);
    }

    /**
     * @return array<string, string>
     */
    private function tableComments(): array
    {
        return [
            'users' => '用户账号：登录身份，可加入多个家庭空间',
            'password_reset_tokens' => '密码重置令牌',
            'sessions' => 'Web 会话存储',
            'cache' => '应用缓存键值',
            'cache_locks' => '缓存锁',
            'jobs' => '队列任务',
            'job_batches' => '批量队列任务',
            'failed_jobs' => '失败的队列任务',
            'personal_access_tokens' => 'API 个人访问令牌（Sanctum）',

            'workspaces' => '家庭/协作空间：多人共用一套档案与记录',
            'memberships' => '空间成员关系：用户在某空间的角色与当前关注人生阶段',
            'members' => '档案人：本人/伴侣/宝宝/宠物等被记录主体（非登录账号）',
            'stages' => '档案人生命阶段片段：孕期、新生儿、恋爱等时间段',
            'kinship_edges' => '亲属关系边：parent/spouse/sibling，用于视角化称呼推导',
            'life_stage_defs' => '人生阶段字典：首页关注阶段与主推栏目配置',

            'template_packs' => '人生栏目/模板包字典：恋爱、宝宝、日常等',
            'template_pack_installations' => '空间已安装的栏目：含恋爱阶段 phase、主题色、绑定伴侣等',
            'record_types' => '记录类型字典与动态表单 Schema',
            'records' => '通用记录：喂养、约会、大事记等，payload 由 Schema 驱动',
            'metric_defs' => '指标定义：身高、体重等',
            'metric_samples' => '指标采样点：可来源于某条记录',
            'reminders' => '提醒事项：到期提醒与可选周期规则',

            'checklists' => '清单主表：如疫苗接种计划',
            'checklist_items' => '清单条目：剂次、推荐日期、完成状态',
            'attachments' => '附件/相册：OSS 或本地文件，可绑定成员、记录或清单项',
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function columnComments(): array
    {
        return [
            'users' => [
                'id' => '主键',
                'name' => '昵称/显示名',
                'email' => '登录邮箱',
                'email_verified_at' => '邮箱验证时间',
                'password' => '密码哈希',
                'remember_token' => '记住登录令牌',
                'created_at' => '创建时间',
                'updated_at' => '更新时间',
            ],
            'workspaces' => [
                'id' => '空间 UUID',
                'name' => '空间名称',
                'owner_id' => '创建者用户 ID',
                'created_at' => '创建时间',
                'updated_at' => '更新时间',
                'deleted_at' => '软删除时间',
                'deleted_by' => '软删除操作人',
            ],
            'memberships' => [
                'id' => '成员关系 UUID',
                'workspace_id' => '所属空间',
                'user_id' => '登录用户',
                'role' => '角色：owner|editor|viewer',
                'focus_stage_kind' => '当前关注人生阶段：love|pregnancy|parenting|daily 等',
                'created_at' => '创建时间',
                'updated_at' => '更新时间',
                'deleted_at' => '软删除时间',
                'deleted_by' => '软删除操作人',
            ],
            'members' => [
                'id' => '档案人 UUID',
                'workspace_id' => '所属空间',
                'linked_user_id' => '关联登录用户（可选）',
                'name' => '姓名',
                'nickname' => '昵称',
                'type' => '类型：self|partner|child|fetus|elder|pet|other',
                'gender' => '性别',
                'birthday' => '生日（日期）',
                'born_at' => '出生时刻（胎儿转新生儿等）',
                'avatar_url' => '头像 URL',
                'meta' => '扩展元数据 JSON',
                'created_at' => '创建时间',
                'updated_at' => '更新时间',
                'deleted_at' => '软删除时间',
                'deleted_by' => '软删除操作人',
            ],
            'stages' => [
                'id' => '阶段 UUID',
                'workspace_id' => '所属空间',
                'member_id' => '所属档案人',
                'kind' => '阶段类型：pregnancy|newborn|love|adult 等',
                'title' => '显示标题',
                'started_at' => '开始时间',
                'ended_at' => '结束时间（空表示进行中）',
                'meta' => '扩展：如预产期、末次月经等',
                'created_at' => '创建时间',
                'updated_at' => '更新时间',
                'deleted_at' => '软删除时间',
                'deleted_by' => '软删除操作人',
            ],
            'kinship_edges' => [
                'id' => '关系边 UUID',
                'workspace_id' => '所属空间',
                'from_member_id' => '关系起点档案人',
                'to_member_id' => '关系终点档案人',
                'relation' => '绝对关系：parent|spouse|sibling（from 是 to 的…）',
                'created_at' => '创建时间',
                'updated_at' => '更新时间',
                'deleted_at' => '软删除时间',
                'deleted_by' => '软删除操作人',
            ],
            'life_stage_defs' => [
                'key' => '阶段键',
                'title' => '标题',
                'subtitle' => '副标题',
                'primary_pack' => '首页主推栏目 pack_key',
                'pack_keys' => '相关栏目 keys JSON',
                'sort' => '排序',
                'is_core' => '是否主轴阶段',
                'created_at' => '创建时间',
                'updated_at' => '更新时间',
                'deleted_at' => '软删除时间',
            ],
            'template_packs' => [
                'key' => '栏目键：love|newborn|journal 等',
                'title' => '标题',
                'subtitle' => '副标题',
                'color' => '主题色',
                'color_soft' => '浅色背景',
                'icon' => '图标',
                'sort' => '排序',
                'is_public' => '是否公开可安装',
                'config' => '默认记录类型/指标/提醒配置 JSON',
                'created_at' => '创建时间',
                'updated_at' => '更新时间',
                'deleted_at' => '软删除时间',
            ],
            'template_pack_installations' => [
                'id' => '安装记录 UUID',
                'workspace_id' => '所属空间',
                'pack_key' => '栏目键',
                'phase' => '栏目子阶段：dating|engaged|married 等（恋爱栏目）',
                'display_title' => '自定义显示名',
                'color' => '覆盖主题色',
                'color_soft' => '覆盖浅色背景',
                'partner_member_id' => '绑定的伴侣档案人',
                'phase_changed_at' => '阶段变更时间',
                'meta' => '扩展：纪念日、起始日等 JSON',
                'installed_at' => '安装时间',
                'installed_by' => '安装人',
                'created_at' => '创建时间',
                'updated_at' => '更新时间',
                'deleted_at' => '软删除时间',
                'deleted_by' => '软删除操作人',
            ],
            'record_types' => [
                'key' => '类型键',
                'title' => '标题',
                'pack_key' => '所属栏目',
                'icon' => '图标',
                'color' => '颜色',
                'sort' => '排序',
                'schema' => '动态表单字段定义 JSON',
                'is_active' => '是否启用',
                'created_at' => '创建时间',
                'updated_at' => '更新时间',
                'deleted_at' => '软删除时间',
            ],
            'records' => [
                'id' => '记录 UUID',
                'workspace_id' => '所属空间',
                'member_id' => '关联档案人',
                'type' => '记录类型（record_types.key）',
                'happened_at' => '发生时间',
                'payload' => '结构化内容 JSON',
                'note' => '备注',
                'client_id' => '客户端幂等 ID',
                'created_by' => '创建人',
                'created_at' => '创建时间',
                'updated_at' => '更新时间',
                'deleted_at' => '软删除时间',
                'deleted_by' => '软删除操作人',
            ],
            'metric_defs' => [
                'key' => '指标键：height|weight 等',
                'title' => '标题',
                'unit' => '单位',
                'pack_key' => '所属栏目',
                'color' => '颜色',
                'sort' => '排序',
                'created_at' => '创建时间',
                'updated_at' => '更新时间',
                'deleted_at' => '软删除时间',
            ],
            'metric_samples' => [
                'id' => '采样 UUID',
                'workspace_id' => '所属空间',
                'member_id' => '档案人',
                'metric_key' => '指标键',
                'value' => '数值',
                'unit' => '单位',
                'measured_at' => '测量时间',
                'source_record_id' => '来源记录',
                'created_by' => '创建人',
                'created_at' => '创建时间',
                'updated_at' => '更新时间',
                'deleted_at' => '软删除时间',
                'deleted_by' => '软删除操作人',
            ],
            'reminders' => [
                'id' => '提醒 UUID',
                'workspace_id' => '所属空间',
                'member_id' => '关联档案人（可选）',
                'title' => '提醒标题',
                'due_at' => '到期时间',
                'recurrence' => '周期规则 JSON',
                'related_type' => '关联业务类型',
                'related_key' => '关联业务键',
                'status' => '状态：pending|done|dismissed',
                'created_by' => '创建人',
                'created_at' => '创建时间',
                'updated_at' => '更新时间',
                'deleted_at' => '软删除时间',
                'deleted_by' => '软删除操作人',
            ],
            'checklists' => [
                'id' => '清单 UUID',
                'workspace_id' => '所属空间',
                'member_id' => '关联档案人（可选）',
                'key' => '清单业务键：如 vaccine_cn_infant',
                'title' => '标题',
                'pack_key' => '所属栏目',
                'meta' => '扩展 JSON',
                'created_at' => '创建时间',
                'updated_at' => '更新时间',
                'deleted_at' => '软删除时间',
                'deleted_by' => '软删除操作人',
            ],
            'checklist_items' => [
                'id' => '条目 UUID',
                'checklist_id' => '所属清单',
                'workspace_id' => '所属空间',
                'title' => '条目标题',
                'dose_no' => '当前剂次',
                'dose_total' => '总剂次',
                'is_free' => '是否免费苗',
                'age_months' => '推荐月龄',
                'recommended_on' => '推荐接种日期',
                'status' => '状态：pending|done|skipped',
                'done_at' => '完成时间',
                'source_record_id' => '关联记录',
                'sort' => '排序',
                'meta' => '扩展 JSON',
                'created_at' => '创建时间',
                'updated_at' => '更新时间',
                'deleted_at' => '软删除时间',
                'deleted_by' => '软删除操作人',
            ],
            'attachments' => [
                'id' => '附件 UUID',
                'workspace_id' => '所属空间',
                'disk' => '存储磁盘名',
                'bucket_name' => 'OSS Bucket',
                'size' => '字节大小',
                'module' => '业务模块：album|love|avatar|record|document|video',
                'kind' => '文件种类：image|video|file',
                'file_name' => '原始文件名',
                'file_path' => '对象存储路径',
                'md5_key' => '内容 MD5（去重）',
                'mime_type' => 'MIME 类型',
                'width' => '图片/视频宽',
                'height' => '图片/视频高',
                'duration' => '视频时长（秒）',
                'extension' => '扩展名',
                'guard' => '上传守卫标识',
                'uploaded_by' => '上传人',
                'member_id' => '相册主体档案人',
                'attachable_type' => '多态关联类型',
                'attachable_id' => '多态关联 ID',
                'captured_at' => '拍摄/发生时间',
                'day_age' => '相对宝宝日龄',
                'note' => '说明',
                'meta' => '扩展：恋爱绑定类型等 JSON',
                'created_at' => '创建时间',
                'updated_at' => '更新时间',
                'deleted_at' => '软删除时间',
                'deleted_by' => '软删除操作人',
            ],
            'personal_access_tokens' => [
                'id' => '主键',
                'tokenable_type' => '令牌所属模型类型',
                'tokenable_id' => '令牌所属模型 ID',
                'name' => '令牌名称',
                'token' => '令牌哈希',
                'abilities' => '能力范围 JSON',
                'last_used_at' => '最后使用时间',
                'expires_at' => '过期时间',
                'created_at' => '创建时间',
                'updated_at' => '更新时间',
            ],
        ];
    }
};
