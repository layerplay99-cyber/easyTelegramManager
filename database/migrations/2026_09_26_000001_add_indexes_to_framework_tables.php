<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为 CatchAdmin 框架自带的表补二级索引。
 *
 * 背景：框架自带的建表迁移只定义了主键，除 personal_access_tokens.token、
 * failed_jobs.uuid 外没有任何二级索引。随着数据量增长，
 * 登录（where email）、操作日志（where creator_id + order by）、
 * 权限树（where parent_id）、角色权限关联（whereIn role_id）都会退化成全表扫描。
 *
 * 注意：本迁移全程幂等——先判断表/列/索引是否存在，重复执行不会报错，
 * 线上若已手工加过同名索引也会自动跳过。
 * users.email 只加普通索引不加唯一约束：历史数据可能已存在重复邮箱，
 * 强行加唯一会让迁移失败，需要唯一约束请先清洗数据。
 */
return new class extends Migration
{
    /**
     * 待创建的索引：[表名 => [列名数组, 索引名]]
     */
    private array $indexes = [
        'users' => [
            [['email'], 'idx_users_email'],
            [['department_id'], 'idx_users_department_id'],
            [['status'], 'idx_users_status'],
            [['creator_id'], 'idx_users_creator_id'],
        ],
        'log_login' => [
            [['account'], 'idx_log_login_account'],
            [['login_at'], 'idx_log_login_login_at'],
            [['status'], 'idx_log_login_status'],
        ],
        'log_operate' => [
            [['creator_id'], 'idx_log_operate_creator_id'],
            [['created_at'], 'idx_log_operate_created_at'],
            [['module'], 'idx_log_operate_module'],
            [['creator_id', 'created_at'], 'idx_log_operate_creator_created'],
        ],
        'permissions' => [
            [['parent_id'], 'idx_permissions_parent_id'],
            [['module'], 'idx_permissions_module'],
            [['permission_mark'], 'idx_permissions_permission_mark'],
            [['type'], 'idx_permissions_type'],
            [['module', 'permission_mark'], 'idx_permissions_module_mark'],
        ],
        'departments' => [
            [['parent_id'], 'idx_departments_parent_id'],
        ],
        'roles' => [
            [['parent_id'], 'idx_roles_parent_id'],
        ],
        'jobs' => [
            [['parent_id'], 'idx_jobs_parent_id'],
        ],
        'role_has_permissions' => [
            [['role_id'], 'idx_role_has_permissions_role_id'],
            [['permission_id'], 'idx_role_has_permissions_permission_id'],
        ],
        'user_has_roles' => [
            [['user_id'], 'idx_user_has_roles_user_id'],
            [['role_id'], 'idx_user_has_roles_role_id'],
        ],
        'user_has_jobs' => [
            [['user_id'], 'idx_user_has_jobs_user_id'],
            [['job_id'], 'idx_user_has_jobs_job_id'],
        ],
        'role_has_departments' => [
            [['role_id'], 'idx_role_has_departments_role_id'],
            [['department_id'], 'idx_role_has_departments_department_id'],
        ],
        'cms_posts' => [
            [['category_id'], 'idx_cms_posts_category_id'],
            [['status'], 'idx_cms_posts_status'],
        ],
        'cms_options' => [
            [['key'], 'idx_cms_options_key'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $definitions) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $tableBlueprint) use ($table, $definitions) {
                foreach ($definitions as [$columns, $indexName]) {
                    // 列不存在就跳过（不同版本的模块表结构可能有差异）
                    foreach ($columns as $column) {
                        if (! Schema::hasColumn($table, $column)) {
                            continue 2;
                        }
                    }

                    if ($this->indexExists($table, $indexName)) {
                        continue;
                    }

                    $tableBlueprint->index($columns, $indexName);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $definitions) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $tableBlueprint) use ($table, $definitions) {
                foreach ($definitions as [$columns, $indexName]) {
                    if (! $this->indexExists($table, $indexName)) {
                        continue;
                    }

                    $tableBlueprint->dropIndex($indexName);
                }
            });
        }
    }

    /**
     * 兼容不同 Laravel / 数据库版本判断索引是否存在
     */
    private function indexExists(string $table, string $indexName): bool
    {
        try {
            return Schema::hasIndex($table, $indexName);
        } catch (\Throwable $e) {
            return false;
        }
    }
};
