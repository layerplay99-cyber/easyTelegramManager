const zh = {
  system: {
    name: '机器人管理系统',
    chinese: '中文',
    english: '英文',
    thai: '泰语',
    vietnamese: '越南语',
    confirm: '确定',
    cancel: '取消',
    warning: '警告',
    next: '下一步',
    prev: '上一步',
    yes: '是',
    no: '否',
    add: '新增',
    edit: '编辑',
    delete: '删除',
    detail: '详情',
    finish: '完成',
    back: '返回',
    update: '更新',
    search: '搜索',
    reset: '重置'
  },

  // 功能（执行器 / 触发方式 / 分类）
  // 注意：必须挂在顶层，代码中使用的是 feature.triggers.*，
  // 之前误嵌在 system 内导致取不到而回落到原始 key（command、callback_query…）
  feature: {
    driverLabel: '执行器',
    triggerLabel: '触发方式',
    commandsLabel: '命令',
    triggers: {
      command: '斜杠命令',
      message: '消息',
      callback_query: '按钮回调',
      inline_query: '内联查询',
      inline_result: '内联结果',
      membership: '成员变化',
      poll_answer: '投票回调',
      chat_join_request: '入群申请',
      webhook: '三方推送',
      manual: '手动/接口',
    },
    categories: {
      system: '系统',
      custom: '客户端',
      bot: '机器人',
      realMan: '真人',
    },
  },

  login: {
    email: '邮箱',
    username: '账号',
    password: '密码',
    sign_in: '登录',
    welcome: '👏欢迎回来',
    lost_password: '忘记密码?',
    remember: '记住我',
    verify: {
      email: {
        required: '请先输入账号',
        invalid: '邮箱地址无效'
      },

      username: {
        required: '请先输入账号'
      },

      password: {
        required: '请先输入密码'
      }
    }
  },

  register: {
    sign_up: '注册'
  },

  telegram: {
    phone: {
      title: '手机号管理',
      resourceId: '资源ID',
      homeLink: '首页链接',
      resourceCategory: '资源分类',
      resourceLink: '资源链接',
      resourceType: '资源类型',
      status: '状态',
      createTime: '创建时间',
      operation: '操作',
      enabled: '启用',
      disabled: '禁用',
      export: '导出',
      import: '导入',
      downloadTemplate: '下载模板',
      importProgress: '导入进度',
      selectPlaceholder: '请选择',
      startDate: '开始日期',
      endDate: '结束日期',
      to: '至',
      preparing: '准备中...',
      items: '条',
      success: '成功',
      failed: '失败',
      uploading: '正在上传文件...',
      importing: '正在导入数据...',
      analyzing: '正在分析文件...',
      importComplete: '导入完成！',
      operationFailed: '操作失败',
      failedDetails: '失败详情',
      linkPlaceholder: '输入资源链接，如果是Facebook链接，系统将尝试提取FBID',
      facebookLinkQuery: '首页链接查询',
      facebookLinkPlaceholder: '输入Facebook个人主页链接，如：https://www.facebook.com/irfan.kos.39',
      queryId: '查询ID',
      queryIdDesc: '输入Facebook个人主页链接，系统将自动获取用户ID并转换为标准格式'
    },
    tUser: {
      title: 'Telegram用户管理',
      username: '用户名',
      name: '姓名',
      country: '国家',
      works: '工作',
      age: '年龄',
      phone: '手机号',
      status: '状态',
      createTime: '创建时间',
      operation: '操作'
    },
    sCanLog: {
      title: '扫描日志',
      scanTime: '扫描时间',
      scanResult: '扫描结果',
      scanType: '扫描类型',
      targetId: '目标ID',
      status: '状态',
      operation: '操作'
    }
  },
  generate: {
    schema: {
      title: '创建数据表',
      name: '表名称',
      name_verify: '请输入表名称',
      engine: {
        name: '表引擎',
        verify: '请选择表引擎',
        placeholder: '选择表引擎'
      },
      default_field: {
        name: '默认字段',
        created_at: '创建时间',
        updated_at: '更新时间',
        creator: '创建人',
        delete_at: '软删除'
      },
      comment: {
        name: '表注释',
        verify: '请填写表注释/说明'
      },

      structure: {
        title: '创建数据结构',
        field_name: {
          name: '字段名称',
          verify: '请填写字段名称'
        },
        length: '长度',
        type: {
          name: '类型',
          placeholder: '选择字段类型',
          verify: '请先选择字段类型'
        },
        form_label: '表单 Label',
        form_component: '表单组件',
        list: '列表',
        form: '表单',
        unique: '唯一',
        search: '查询',
        search_op: {
          name: '搜索操作符',
          placeholder: '选择搜索操作符'
        },
        nullable: 'nullable',
        default: '默认值',
        rules: {
          name: '验证规则',
          placeholder: '选择验证规则'
        },
        operate: '操作',
        comment: '字段注释'
      }
    },
    code: {
      title: '生成代码',
      module: {
        name: '所属模块',
        placeholder: '请选择模块',
        verify: '请选择模块'
      },
      controller: {
        name: '控制器',
        placeholder: '请输入控制器名称',
        verify: '请输入控制器名称'
      },
      model: {
        name: '模型',
        placeholder: '请输入模型名称',
        verify: '请输入模型名称'
      },
      paginate: '分页',
      menu: {
        name: '菜单名称',
        placeholder: '请输入菜单名称',
        verify: '请输入菜单名称'
      }
    }
  },

  module: {
    create: '创建模块',
    update: '更新模块',
    form: {
      name: {
        title: '模块名称',
        required: '请输入模块名称'
      },

      path: {
        title: '模块目录',
        required: '请输入模块目录'
      },

      desc: {
        title: '模块描述'
      },

      keywords: {
        title: '模块关键字'
      },

      dirs: {
        title: '默认目录',
        Controller: 'Controller 目录',
        Model: 'Model 目录',
        Database: 'Database 目录',
        Request: 'Request 目录'
      }
    }
  },

  broadcast: {
    title: '消息群发',
    selectBot: '选择Bot',
    selectBotPlaceholder: '请选择Bot',
    selectGroupCategory: '选择群分组',
    messageContent: '消息内容',
    messagePlaceholder: '请输入要发送的消息内容',
    selectAll: '全选（所有群组）',
    searchGroupName: '搜索群名称',
    selected: '已选择',
    groups: '个群组',
    allGroups: '全部',
    groupCategory: '分组',
    groupName: '群组名称',
    noGroups: '暂无可用群组',
    syncFirst: '未找到可用群组，请先同步群组',
    confirmSend: '确定发送',
    sendSuccess: '消息发送成功',
    sendFailed: '消息发送失败',
    partialFailed: '部分消息发送失败',
    selectBotRequired: '请选择Bot',
    messageRequired: '请输入消息内容',
    selectGroupRequired: '请至少选择一个群组',
    loadBotsFailed: '加载Bot列表失败',
    loadGroupsFailed: '加载群组失败',
    loadGroupsRetry: '加载群组失败，请稍后重试',
    sendRetry: '发送消息失败，请稍后重试',
    photoUrl: '图片地址',
    photoUrlPlaceholder: '请输入图片URL或上传图片',
    uploadPhoto: '上传图片',
    photoTip: '支持 jpg/jpeg/png/gif/webp 格式，文件大小不超过 5MB',
    caption: '图片描述',
    captionPlaceholder: '请输入图片描述（可选）',
    photoRequired: '请上传图片或输入图片URL',
    invalidFileType: '不支持的文件类型，仅支持 jpg/jpeg/png/gif/webp',
    fileSizeLimit: '文件大小不能超过 5MB',
    uploadSuccess: '图片上传成功',
    uploadFailed: '图片上传失败'
  },

  groupSelect: {
    title: '选择群组',
    messageType: '消息类型',
    messageTypePlaceholder: '请选择消息类型',
    textType: '文字',
    mediaType: '图文',
    photoType: '图片',
    selectCurrentPage: '全选（当前页）',
    confirmExecute: '确定执行',
    operateSuccess: '操作成功',
    operateFailed: '操作失败',
    partialFailed: '部分操作失败'
  }
}

export default zh
